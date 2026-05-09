<?php

declare(strict_types=1);

namespace Core\Database;

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
            throw new RuntimeException(
                class_basename($this->modelClass) . ': no matching record found.'
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
        /** @var TModel $model */
        $model = new $this->modelClass();
        $model->forceFill($attributes);
        $model->syncOriginal();
        $model->exists = true;
        return $model;
    }
}
