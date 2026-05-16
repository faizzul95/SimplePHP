<?php

declare(strict_types=1);

namespace Core\Database;

use Core\LazyCollection;
use RuntimeException;

/**
 * Driver-agnostic query proxy for Model.
 *
 * Wraps the db()->table() fluent builder obtained at runtime (any driver),
 * intercepts get()/fetch()/paginate() to hydrate results as Model instances,
 * and proxies every other method call to the underlying builder.
 *
 * Fluent builder methods (those that return the builder itself) are detected by
 * identity check: when the builder returns itself, ModelQuery returns $this so
 * chaining stays in the ModelQuery context.
 *
 * @template TModel of Model
 */
class ModelQuery
{
    /** @var class-string<TModel> */
    private string $modelClass;

    /** The underlying driver query-builder instance. */
    private mixed $builder;

    /**
     * @param mixed              $builder    Result of db($conn)->table($table)
     * @param class-string<TModel> $modelClass Fully-qualified model class name
     */
    public function __construct(mixed $builder, string $modelClass)
    {
        $this->builder    = $builder;
        $this->modelClass = $modelClass;
    }

    public function select(array|string $columns = ['*']): self
    {
        $this->builder->select($columns);

        return $this;
    }

    public function where(string|array|\Closure $column, mixed $operator = null, mixed $value = null): self
    {
        $this->builder->where($column, $operator, $value);

        return $this;
    }

    // -------------------------------------------------------------------------
    // Hydrating terminal methods
    // -------------------------------------------------------------------------

    /**
     * Execute the query and hydrate every row as a model instance.
     *
     * @return TModel[]
     */
    public function get(mixed $table = null): array
    {
        $rows = $this->builder->get($table);
        if (!is_array($rows)) {
            return [];
        }
        return array_map([$this, 'hydrateRow'], $rows);
    }

    /**
     * Fetch the first matching row and hydrate it as a model instance.
     *
     * @return TModel|null
     */
    public function fetch(mixed $table = null): mixed
    {
        $row = $this->builder->fetch($table);
        if (!is_array($row) || empty($row)) {
            return null;
        }
        return $this->hydrateRow($row);
    }

    /**
     * Alias for fetch(); optionally restrict columns first.
     *
     * @return TModel|null
     */
    public function first(mixed $columns = []): mixed
    {
        if (!empty($columns)) {
            $this->builder->select($columns);
        }
        return $this->fetch();
    }

    /**
     * Like first() but throws when no row is found.
     *
     * @return TModel
     *
     * @throws RuntimeException
     */
    public function firstOrFail(mixed $columns = []): mixed
    {
        $result = $this->first($columns);
        if ($result === null) {
            $modelParts = explode('\\', $this->modelClass);
            throw new RuntimeException(
                end($modelParts) . ': no matching record found.'
            );
        }
        return $result;
    }

    /**
     * Find by primary key; pass an array to get multiple rows.
     *
     * @return TModel|TModel[]|null
     */
    public function find(mixed $id, mixed $columns = ['*']): mixed
    {
        $instance = new $this->modelClass();
        $pk = $instance->getKeyName();

        if ($columns !== ['*'] && $columns !== '*') {
            $this->builder->select($columns);
        }

        if (is_array($id)) {
            $ids = array_values(array_unique($id));
            if (empty($ids)) {
                return [];
            }
            $this->builder->whereIn($pk, $ids);
            return $this->get();
        }

        $this->builder->where($pk, $id);
        return $this->fetch();
    }

    /**
     * Paginate results; hydrates the `data` slice.
     */
    public function paginate(mixed ...$args): mixed
    {
        $result = $this->builder->paginate(...$args);
        if (is_array($result) && isset($result['data']) && is_array($result['data'])) {
            $result['data'] = array_map([$this, 'hydrateRow'], $result['data']);
        }
        return $result;
    }

    /**
     * Ajax paginate; hydrates the `data` slice.
     */
    public function paginate_ajax(mixed ...$args): mixed
    {
        $result = $this->builder->paginate_ajax(...$args);
        if (is_array($result) && isset($result['data']) && is_array($result['data'])) {
            $result['data'] = array_map([$this, 'hydrateRow'], $result['data']);
        }
        return $result;
    }

    /**
     * Iterate through the result set in hydrated chunks.
     */
    public function chunk(int $size, callable $callback): self
    {
        $this->builder->chunk($size, function (array $rows) use ($callback) {
            return $callback($this->hydrateRows($rows));
        });

        return $this;
    }

    /**
     * Stream hydrated models using the builder's bounded-memory cursor path.
     *
     * @return \Generator<int, TModel>
     */
    public function cursor(int $chunkSize = 1000): \Generator
    {
        foreach ($this->builder->cursor($chunkSize) as $row) {
            yield $this->hydrateRowValue($row);
        }
    }

    /**
     * Return a lazy collection of hydrated models.
     */
    public function lazy(int $chunkSize = 1000): mixed
    {
        return $this->mapTraversableResults($this->builder->lazy($chunkSize));
    }

    /**
     * Iterate through hydrated model chunks using keyset pagination.
     */
    public function chunkById(int $size, callable $callback, string $column = 'id', ?string $alias = null): self
    {
        $this->builder->chunkById($size, function (array $rows) use ($callback) {
            return $callback($this->hydrateRows($rows));
        }, $column, $alias);

        return $this;
    }

    /**
     * Return a lazy collection of hydrated models using keyset pagination.
     */
    public function lazyById(int $chunkSize = 1000, string $column = 'id', ?string $alias = null): mixed
    {
        return $this->mapTraversableResults($this->builder->lazyById($chunkSize, $column, $alias));
    }

    // -------------------------------------------------------------------------
    // Fluent proxy
    // -------------------------------------------------------------------------

    /**
     * Proxy every other method to the underlying builder.
     *
     * When the builder returns itself (fluent call) ModelQuery returns $this so
     * callers stay in the ModelQuery context and results are still hydrated.
     */
    public function __call(string $method, array $args): mixed
    {
        $result = $this->builder->{$method}(...$args);

        // Fluent — builder returned itself → keep caller in ModelQuery context
        if ($result === $this->builder) {
            return $this;
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Hydrate a raw associative array as a model instance.
     *
     * @return TModel
     */
    private function hydrateRow(array $attributes): mixed
    {
        $modelClass = $this->modelClass;

        /** @var TModel $model */
        $model = $modelClass::hydrateRecord($attributes);
        return $model;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, TModel>
     */
    private function hydrateRows(array $rows): array
    {
        return array_map([$this, 'hydrateRow'], $rows);
    }

    private function hydrateRowValue(mixed $row): mixed
    {
        return is_array($row) ? $this->hydrateRow($row) : $row;
    }

    private function mapTraversableResults(mixed $result): mixed
    {
        if ($result instanceof LazyCollection) {
            return $result->map(fn(mixed $row): mixed => $this->hydrateRowValue($row));
        }

        if ($result instanceof \Traversable) {
            return (function () use ($result) {
                foreach ($result as $row) {
                    yield $this->hydrateRowValue($row);
                }
            })();
        }

        return $result;
    }
}
