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
    protected function sanitizeColumn($data): array { return is_array($data) ? $data : []; }

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

    public function testChunkUsesKeysetPaginationForExplicitSelectsThatIncludeTheKeyColumn(): void
    {
        $database = new ChunkIterationOptimizationProbe();
        $database->select(['id', 'name']);

        $database->chunk(100, static function (array $rows) {
            return true;
        });

        self::assertTrue($database->chunkByIdCalled);
        self::assertSame(0, $database->getCalls);
    }

    public function testChunkUsesKeysetPaginationForAscendingKeyOrder(): void
    {
        $database = new ChunkIterationOptimizationProbe();
        $database->orderBy('id', 'ASC');

        $database->chunk(100, static function (array $rows) {
            return true;
        });

        self::assertTrue($database->chunkByIdCalled);
        self::assertSame(0, $database->getCalls);
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

final class KeysetLimitPreservingProbe extends BaseDatabase
{
    /** @var array<int, array{id:int}> */
    private array $records;

    public function __construct()
    {
        $this->table = 'users';
        $this->column = '*';
        $this->records = array_map(static fn (int $id): array => ['id' => $id], range(1, 10));
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
    public function count($table = null) { return count($this->records); }
    public function exists($table = null) { return !empty($this->records); }
    public function _getLimitOffsetPaginate($query, $limit, $offset) { return $query; }
    public function batchInsert($data) { return []; }
    public function batchUpdate($data) { return []; }
    public function upsert($values, $uniqueBy = 'id', $updateColumns = null) { return []; }
    protected function sanitizeColumn($data): array { return is_array($data) ? $data : []; }

    public function limit($limit)
    {
        $this->limit = 'LIMIT ' . (int) $limit;
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
        $limit = isset($limitMatches[1]) ? (int) $limitMatches[1] : count($this->records);

        $lastId = null;
        if (!empty($this->_binds)) {
            $lastId = (int) end($this->_binds);
            reset($this->_binds);
        }

        $rows = array_values(array_filter(
            $this->records,
            static fn (array $row): bool => $lastId === null || $row['id'] > $lastId
        ));

        return array_slice($rows, 0, $limit);
    }
}

final class KeysetLimitPreservingTest extends TestCase
{
    public function testChunkByIdRespectsOriginalLimit(): void
    {
        $database = new KeysetLimitPreservingProbe();
        $chunks = [];

        $database->limit(3)->chunkById(2, function (array $rows) use (&$chunks) {
            $chunks[] = array_column($rows, 'id');
            return true;
        });

        self::assertSame([[1, 2], [3]], $chunks);
    }

    public function testLazyByIdRespectsOriginalLimit(): void
    {
        $database = new KeysetLimitPreservingProbe();

        $rows = iterator_to_array($database->limit(3)->lazyById(2), false);

        self::assertSame([1, 2, 3], array_column($rows, 'id'));
    }
}

final class AdaptiveStreamingChunkProbe extends BaseDatabase
{
    /** @var array<int, array<string, mixed>> */
    private array $records;

    /** @var array<int, int> */
    public array $limitHistory = [];

    public bool $hasIdColumn = true;

    public function __construct()
    {
        $this->table = 'users';
        $this->column = '*';
        $this->records = array_map(static fn (int $id): array => [
            'id' => $id,
            'name' => 'User ' . $id,
            'email' => 'user' . $id . '@example.test',
            'status' => $id % 2 === 0 ? 'active' : 'inactive',
            'role' => 'member',
            'city' => 'Kuala Lumpur',
        ], range(1, 6));
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
    public function count($table = null) { return count($this->records); }
    public function exists($table = null) { return !empty($this->records); }
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
        return $column === 'id' && $this->hasIdColumn;
    }

    public function get($table = null)
    {
        preg_match('/LIMIT\s+(\d+)/i', (string) $this->limit, $limitMatches);
        $limit = isset($limitMatches[1]) ? (int) $limitMatches[1] : count($this->records);

        preg_match('/OFFSET\s+(\d+)/i', (string) $this->offset, $offsetMatches);
        $offset = isset($offsetMatches[1]) ? (int) $offsetMatches[1] : 0;

        $lastId = null;
        if (!empty($this->_binds)) {
            $lastId = (int) end($this->_binds);
            reset($this->_binds);
        }

        $rows = $this->records;
        if ($lastId !== null) {
            $rows = array_values(array_filter(
                $rows,
                static fn (array $row): bool => $row['id'] > $lastId
            ));
            return array_slice($rows, 0, $limit);
        }

        return array_slice($rows, $offset, $limit);
    }

    protected function recommendedStreamingChunkSize(?array $sampleRow = null, int $requestedSize = 1000): int
    {
        if ($sampleRow === null) {
            return $requestedSize;
        }

        return 2;
    }
}

final class AdaptiveStreamingChunkTest extends TestCase
{
    public function testChunkShrinksFollowUpOffsetBatchesForWideRows(): void
    {
        $database = new AdaptiveStreamingChunkProbe();
        $database->select('name');

        $chunks = [];
        $database->chunk(5, function (array $rows) use (&$chunks) {
            $chunks[] = array_column($rows, 'id');
            return true;
        });

        self::assertSame([[1, 2, 3, 4, 5], [6]], $chunks);
        self::assertSame([5, 2], array_slice($database->limitHistory, 0, 2));
    }

    public function testLazyByIdShrinksFollowUpKeysetBatchesForWideRows(): void
    {
        $database = new AdaptiveStreamingChunkProbe();

        $rows = iterator_to_array($database->lazyById(5), false);

        self::assertSame([1, 2, 3, 4, 5, 6], array_column($rows, 'id'));
        self::assertSame([5, 2], array_slice($database->limitHistory, 0, 2));
    }
}
