<?php

declare(strict_types=1);

use Core\Database\BaseDatabase;
use PHPUnit\Framework\TestCase;

final class ChunkIterationOptimizationProbe extends BaseDatabase
{
    public bool $chunkByIdCalled = false;
    public bool $lazyByIdCalled = false;
    public int $getCalls = 0;
    public bool $hasIdColumn = true;
    public mixed $lazyByIdResult = null;

    public function __construct()
    {
        $this->table = 'users';
        $this->column = '*';
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
    public function limit($limit) { return $this; }
    public function offset($offset) { return $this; }
    public function count($table = null) { return 0; }
    public function exists($table = null) { return false; }
    public function _getLimitOffsetPaginate($query, $limit, $offset) { return $query; }
    public function batchInsert($data) { return []; }
    public function batchUpdate($data) { return []; }
    public function upsert($values, $uniqueBy = 'id', $updateColumns = null) { return []; }
    protected function sanitizeColumn($data) { return $data; }

    public function hasColumn($column)
    {
        return $column === 'id' && $this->hasIdColumn;
    }

    public function chunkById(int $size, callable $callback, string $column = 'id', ?string $alias = null)
    {
        $this->chunkByIdCalled = true;
        $callback([['id' => 1]]);
        return $this;
    }

    public function lazyById(int $chunkSize = 1000, string $column = 'id', ?string $alias = null)
    {
        $this->lazyByIdCalled = true;

        if ($this->lazyByIdResult !== null) {
            return $this->lazyByIdResult;
        }

        return (function () {
            yield ['id' => 1];
        })();
    }

    public function get($table = null)
    {
        $this->getCalls++;

        if ($this->getCalls === 1) {
            return [['name' => 'alpha']];
        }

        return [];
    }

    public function exposeAttachEagerLoadedData($method, array $data, array $relatedRecords, string $alias, string $fk_id, string $pk_id): array
    {
        $this->attachEagerLoadedData($method, $data, $relatedRecords, $alias, $fk_id, $pk_id);
        return $data;
    }
}

final class ChunkIterationOptimizationTest extends TestCase
{
    public function testChunkUsesKeysetPaginationWhenQueryShapeIsEligible(): void
    {
        $database = new ChunkIterationOptimizationProbe();
        $chunks = [];

        $database->chunk(100, function (array $rows) use (&$chunks) {
            $chunks[] = $rows;
            return true;
        });

        self::assertTrue($database->chunkByIdCalled);
        self::assertFalse($database->lazyByIdCalled);
        self::assertSame([[['id' => 1]]], $chunks);
    }

    public function testChunkFallsBackToOffsetPaginationForCustomSelects(): void
    {
        $database = new ChunkIterationOptimizationProbe();
        $database->select('name');
        $chunks = [];

        $database->chunk(100, function (array $rows) use (&$chunks) {
            $chunks[] = $rows;
            return true;
        });

        self::assertFalse($database->chunkByIdCalled);
        self::assertSame(1, $database->getCalls);
        self::assertSame([[['name' => 'alpha']]], $chunks);
    }

    public function testCursorUsesKeysetPaginationWhenQueryShapeIsEligible(): void
    {
        $database = new ChunkIterationOptimizationProbe();

        $rows = iterator_to_array($database->cursor(50), false);

        self::assertTrue($database->lazyByIdCalled);
        self::assertSame([['id' => 1]], $rows);
    }

    public function testLazyUsesKeysetPaginationWhenQueryShapeIsEligible(): void
    {
        $database = new ChunkIterationOptimizationProbe();
        $database->lazyByIdResult = new \ArrayIterator([['id' => 1], ['id' => 2]]);

        $result = $database->lazy(250);

        self::assertTrue($database->lazyByIdCalled);
        self::assertInstanceOf(\ArrayIterator::class, $result);
        self::assertSame([['id' => 1], ['id' => 2]], iterator_to_array($result));
    }

    public function testAttachEagerLoadedDataPreservesCollectionShapeWithoutIntermediateMap(): void
    {
        $database = new ChunkIterationOptimizationProbe();

        $result = $database->exposeAttachEagerLoadedData(
            'get',
            [
                ['id' => 10, 'name' => 'alpha'],
                ['id' => 20, 'name' => 'beta'],
                ['id' => 10, 'name' => 'alpha-copy'],
            ],
            [
                ['user_id' => 10, 'title' => 'first'],
                ['user_id' => 10, 'title' => 'second'],
                ['user_id' => 20, 'title' => 'third'],
            ],
            'posts',
            'user_id',
            'id'
        );

        self::assertSame([
            ['user_id' => 10, 'title' => 'first'],
            ['user_id' => 10, 'title' => 'second'],
        ], $result[0]['posts']);
        self::assertSame([
            ['user_id' => 10, 'title' => 'first'],
            ['user_id' => 10, 'title' => 'second'],
        ], $result[2]['posts']);
        self::assertSame([
            ['user_id' => 20, 'title' => 'third'],
        ], $result[1]['posts']);
    }

    public function testAttachEagerLoadedDataPreservesFetchShape(): void
    {
        $database = new ChunkIterationOptimizationProbe();

        $result = $database->exposeAttachEagerLoadedData(
            'fetch',
            [
                ['id' => 10, 'name' => 'alpha'],
                ['id' => 20, 'name' => 'beta'],
            ],
            [
                ['user_id' => 10, 'title' => 'first'],
                ['user_id' => 10, 'title' => 'second'],
            ],
            'post',
            'user_id',
            'id'
        );

        self::assertSame(['user_id' => 10, 'title' => 'first'], $result[0]['post']);
        self::assertNull($result[1]['post']);
    }
}
