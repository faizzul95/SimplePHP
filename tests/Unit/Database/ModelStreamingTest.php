<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use Core\Database\BaseDatabase;
use Core\Database\Model;
use Core\LazyCollection;
use PHPUnit\Framework\TestCase;

final class ModelStreamingBuilderStub
{
    public array $chunkRows = [];
    public array $cursorRows = [];
    public array $lazyRows = [];
    public array $chunkByIdRows = [];
    public array $lazyByIdRows = [];
    public array $chunkByIdCalls = [];
    public array $lazyByIdCalls = [];

    public function table($table)
    {
        return $this;
    }

    public function setSortableColumns(array $columns)
    {
        return $this;
    }

    public function setFilterableColumns(array $columns)
    {
        return $this;
    }

    public function whereNull($column)
    {
        return $this;
    }

    public function chunk(int $size, callable $callback)
    {
        foreach ($this->chunkRows as $rows) {
            if ($callback($rows) === false) {
                break;
            }
        }

        return $this;
    }

    public function cursor(int $chunkSize = 1000): \Generator
    {
        foreach ($this->cursorRows as $row) {
            yield $row;
        }
    }

    public function lazy(int $chunkSize = 1000): LazyCollection
    {
        $rows = $this->lazyRows;

        return new LazyCollection(static function (int $size, int $offset) use ($rows): array {
            return array_slice($rows, $offset, $size);
        });
    }

    public function chunkById(int $size, callable $callback, string $column = 'id', ?string $alias = null)
    {
        $this->chunkByIdCalls[] = [$size, $column, $alias];

        foreach ($this->chunkByIdRows as $rows) {
            if ($callback($rows) === false) {
                break;
            }
        }

        return $this;
    }

    public function lazyById(int $chunkSize = 1000, string $column = 'id', ?string $alias = null): LazyCollection
    {
        $this->lazyByIdCalls[] = [$chunkSize, $column, $alias];
        $rows = $this->lazyByIdRows;

        return new LazyCollection(static function (int $size, int $offset) use ($rows): array {
            return array_slice($rows, $offset, $size);
        });
    }
}

final class ModelStreamingRuntimeStub
{
    public function __construct(public ModelStreamingBuilderStub $builder)
    {
    }

    public function connection(string $connectionName = 'default')
    {
        return $this->builder;
    }
}

final class ModelAdaptiveStreamingHarnessBuilder extends BaseDatabase
{
    private const TOTAL_RECORDS = 1000000;

    public array $limitHistory = [];

    public function __construct()
    {
        $this->table = 'users';
        $this->column = '*';
        self::$_instance = $this;
    }

    public function connect($connectionID = null) { return $this; }
    public function whereDate($column, $operator = null, $value = null) { return $this; }
    public function orWhereDate($column, $operator = null, $value = null) { return $this; }
    public function whereDay($column, $operator = null, $value = null) { return $this; }
    public function orWhereDay($column, $operator = null, $value = null) { return $this; }
    public function whereMonth($column, $operator = null, $value = null) { return $this; }
    public function orWhereMonth($column, $operator = null, $value = null) { return $this; }
    public function whereYear($column, $operator = null, $value = null) { return $this; }
    public function orWhereYear($column, $operator = null, $value = null) { return $this; }
    public function whereTime($column, $operator = null, $value = null) { return $this; }
    public function orWhereTime($column, $operator = null, $value = null) { return $this; }
    public function whereJsonContains($columnName, $jsonPath, $value) { return $this; }
    public function count($table = null) { return self::TOTAL_RECORDS; }
    public function exists($table = null) { return true; }
    public function _getLimitOffsetPaginate($query, $limit, $offset) { return $query; }
    public function batchInsert($data) { return []; }
    public function batchUpdate($data) { return []; }
    public function upsert($values, $uniqueBy = 'id', $updateColumns = null) { return []; }
    protected function sanitizeColumn($data): array { return is_array($data) ? $data : []; }

    public function limit($limit)
    {
        $limit = (int) $limit;
        $this->limitHistory[] = $limit;
        $this->limit = 'LIMIT ' . $limit;
        return $this;
    }

    public function offset($offset)
    {
        $this->offset = 'OFFSET ' . (int) $offset;
        return $this;
    }

    public function hasColumn($column)
    {
        return $column === 'id';
    }

    public function get($table = null)
    {
        preg_match('/LIMIT\s+(\d+)/i', (string) $this->limit, $limitMatches);
        $limit = isset($limitMatches[1]) ? (int) $limitMatches[1] : self::TOTAL_RECORDS;

        preg_match('/OFFSET\s+(\d+)/i', (string) $this->offset, $offsetMatches);
        $offset = isset($offsetMatches[1]) ? (int) $offsetMatches[1] : 0;

        $lastId = null;
        if (!empty($this->_binds)) {
            $lastId = (int) end($this->_binds);
            reset($this->_binds);
        }

        $startId = $lastId !== null ? $lastId + 1 : $offset + 1;
        if ($startId > self::TOTAL_RECORDS) {
            return [];
        }

        $endId = min(self::TOTAL_RECORDS, $startId + $limit - 1);
        $rows = [];

        for ($id = $startId; $id <= $endId; $id++) {
            $row = ['id' => $id, 'name' => 'User ' . $id, 'email' => 'user' . $id . '@example.test'];
            for ($column = 1; $column <= 26; $column++) {
                $row['col_' . $column] = 'value-' . $column;
            }

            $rows[] = $row;
        }

        return $rows;
    }
}

final class ModelAdaptiveStreamingRuntimeStub
{
    public function __construct(public ModelAdaptiveStreamingHarnessBuilder $builder)
    {
    }

    public function connection(string $connectionName = 'default')
    {
        return $this->builder;
    }
}

final class ModelStreamingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        reset_framework_service();
        require_once ROOT_DIR . 'systems/app.php';
    }

    public function testChunkHydratesModelsPerBatch(): void
    {
        $builder = new ModelStreamingBuilderStub();
        $builder->chunkRows = [
            [
                ['id' => 1, 'name' => 'Alpha'],
                ['id' => 2, 'name' => 'Beta'],
            ],
            [
                ['id' => 3, 'name' => 'Gamma'],
            ],
        ];
        register_framework_service('database.runtime', fn() => new ModelStreamingRuntimeStub($builder));

        $model = new class extends Model {
            protected string $table = 'users';
        };
        $class = $model::class;
        $class::clearBootedModelState();
        $class::retrieved(function (Model $model): void {
            $model->setAttribute('hydrated_flag', true);
        });

        $batches = [];
        $class::chunk(2, function (array $models) use (&$batches): void {
            $batches[] = $models;
        });

        self::assertCount(2, $batches);
        self::assertInstanceOf($class, $batches[0][0]);
        self::assertTrue((bool) $batches[0][0]->getAttribute('hydrated_flag'));
        self::assertSame('Gamma', $batches[1][0]->getAttribute('name'));
    }

    public function testCursorStreamsHydratedModels(): void
    {
        $builder = new ModelStreamingBuilderStub();
        $builder->cursorRows = [
            ['id' => 10, 'name' => 'Alpha'],
            ['id' => 11, 'name' => 'Beta'],
        ];
        register_framework_service('database.runtime', fn() => new ModelStreamingRuntimeStub($builder));

        $model = new class extends Model {
            protected string $table = 'users';
        };
        $class = $model::class;
        $class::clearBootedModelState();

        $rows = iterator_to_array($class::cursor(500), false);

        self::assertCount(2, $rows);
        self::assertInstanceOf($class, $rows[0]);
        self::assertSame(10, $rows[0]->getKey());
        self::assertSame('Beta', $rows[1]->name);
    }

    public function testLazyReturnsHydratedLazyCollection(): void
    {
        $builder = new ModelStreamingBuilderStub();
        $builder->lazyRows = [
            ['id' => 21, 'name' => 'Alpha'],
            ['id' => 22, 'name' => 'Beta'],
        ];
        register_framework_service('database.runtime', fn() => new ModelStreamingRuntimeStub($builder));

        $model = new class extends Model {
            protected string $table = 'users';
        };
        $class = $model::class;
        $class::clearBootedModelState();

        $collection = $class::lazy(1000);

        self::assertInstanceOf(LazyCollection::class, $collection);

        $rows = $collection->all();

        self::assertCount(2, $rows);
        self::assertInstanceOf($class, $rows[0]);
        self::assertSame(22, $rows[1]->getKey());
    }

    public function testChunkByIdHydratesModelsAndPreservesKeysetArguments(): void
    {
        $builder = new ModelStreamingBuilderStub();
        $builder->chunkByIdRows = [
            [
                ['id' => 31, 'name' => 'Alpha'],
                ['id' => 32, 'name' => 'Beta'],
            ],
        ];
        register_framework_service('database.runtime', fn() => new ModelStreamingRuntimeStub($builder));

        $model = new class extends Model {
            protected string $table = 'users';
        };
        $class = $model::class;
        $class::clearBootedModelState();

        $batches = [];
        $class::chunkById(2500, function (array $models) use (&$batches): void {
            $batches[] = $models;
        }, 'id', 'id');

        self::assertSame([[2500, 'id', 'id']], $builder->chunkByIdCalls);
        self::assertInstanceOf($class, $batches[0][1]);
        self::assertSame('Beta', $batches[0][1]->getAttribute('name'));
    }

    public function testLazyByIdReturnsHydratedLazyCollection(): void
    {
        $builder = new ModelStreamingBuilderStub();
        $builder->lazyByIdRows = [
            ['id' => 41, 'name' => 'Alpha'],
            ['id' => 42, 'name' => 'Beta'],
            ['id' => 43, 'name' => 'Gamma'],
        ];
        register_framework_service('database.runtime', fn() => new ModelStreamingRuntimeStub($builder));

        $model = new class extends Model {
            protected string $table = 'users';
        };
        $class = $model::class;
        $class::clearBootedModelState();

        $collection = $class::lazyById(2000, 'id', 'id');
        $rows = $collection->take(2)->all();

        self::assertSame([[2000, 'id', 'id']], $builder->lazyByIdCalls);
        self::assertCount(2, $rows);
        self::assertInstanceOf($class, $rows[0]);
        self::assertSame(42, $rows[1]->getKey());
    }

    public function testLazyByIdMillionRowHarnessHydratesModelsWithoutMaterializingDataset(): void
    {
        $builder = new ModelAdaptiveStreamingHarnessBuilder();
        register_framework_service('database.runtime', fn() => new ModelAdaptiveStreamingRuntimeStub($builder));

        $model = new class extends Model {
            protected string $table = 'users';
        };
        $class = $model::class;
        $class::clearBootedModelState();

        $consumed = 0;
        $firstRow = null;
        $lastKey = null;

        foreach ($class::lazyById(2000, 'id', 'id') as $row) {
            $consumed++;
            $firstRow ??= $row;
            $lastKey = $row->getKey();

            if ($consumed >= 2250) {
                break;
            }
        }

        self::assertSame(2250, $consumed);
        self::assertInstanceOf($class, $firstRow);
        self::assertSame(2250, $lastKey);
        self::assertSame([2000, 250], array_slice($builder->limitHistory, 0, 2));
    }
}