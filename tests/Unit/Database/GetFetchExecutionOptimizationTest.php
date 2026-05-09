<?php

declare(strict_types=1);

use Core\Database\BaseDatabase;
use PHPUnit\Framework\TestCase;

final class GetFetchExecutionOptimizationProbe extends BaseDatabase
{
    public array $rows = [];
    public int $executeCalls = 0;
    public int $sanitizeCalls = 0;
    public array $eagerLoadCalls = [];

    public function __construct(array $rows)
    {
        $this->rows = $rows;
        $this->connectionName = 'default';
        $this->suppressQueryCache = true;
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
    public function count($table = null) { return count($this->rows); }
    public function exists($table = null) { return !empty($this->rows); }
    public function _getLimitOffsetPaginate($query, $limit, $offset) { return $query; }
    public function batchInsert($data) { return []; }
    public function batchUpdate($data) { return []; }
    public function upsert($values, $uniqueBy = 'id', $updateColumns = null) { return []; }
    protected function sanitizeColumn($data): array { return is_array($data) ? $data : []; }

    protected function _prepareStatement($query)
    {
        $rows = $this->rows;
        $database = $this;

        return new class($rows, $database) {
            private array $rows;
            private GetFetchExecutionOptimizationProbe $database;

            public function __construct(array $rows, GetFetchExecutionOptimizationProbe $database)
            {
                $this->rows = $rows;
                $this->database = $database;
            }

            public function execute(): bool
            {
                $this->database->executeCalls++;
                return true;
            }

            public function fetchAll($mode): array
            {
                return $this->rows;
            }

            public function fetch($mode): array|false
            {
                return $this->rows[0] ?? false;
            }

            public function closeCursor(): void
            {
            }

            public function __get(string $name)
            {
                if ($name === 'queryString') {
                    return 'SELECT * FROM `users`';
                }

                return null;
            }
        };
    }

    protected function _safeOutputSanitize($data)
    {
        $this->sanitizeCalls++;

        if (is_array($data) && array_is_list($data)) {
            foreach ($data as &$row) {
                $row['sanitized'] = true;
            }

            return $data;
        }

        if (is_array($data)) {
            $data['sanitized'] = true;
        }

        return $data;
    }

    protected function _processEagerLoading(&$data, $relations, $connectionName, $typeFetch)
    {
        $this->eagerLoadCalls[] = [
            'type' => $typeFetch,
            'connection' => $connectionName,
            'relations' => array_keys($relations),
        ];

        if ($typeFetch === 'fetch') {
            $data['eager_loaded'] = true;
            return $data;
        }

        foreach ($data as &$row) {
            $row['eager_loaded'] = true;
        }

        return $data;
    }
}

final class GetFetchExecutionOptimizationTest extends TestCase
{
    public function testGetUsesSharedExecutionPathWithoutChangingPostProcessing(): void
    {
        $database = new GetFetchExecutionOptimizationProbe([
            ['id' => 1, 'name' => 'alpha'],
            ['id' => 2, 'name' => 'beta'],
        ]);

        $result = $database
            ->safeOutput(true)
            ->with('posts', 'posts', 'user_id', 'id')
            ->query('SELECT * FROM `users`')
            ->get();

        self::assertSame(1, $database->executeCalls);
        self::assertSame(1, $database->sanitizeCalls);
        self::assertSame('get', $database->eagerLoadCalls[0]['type'] ?? null);
        self::assertTrue($result[0]['sanitized']);
        self::assertTrue($result[0]['eager_loaded']);
        self::assertTrue($result[1]['sanitized']);
        self::assertTrue($result[1]['eager_loaded']);
    }

    public function testFetchUsesSharedExecutionPathWithoutChangingPostProcessing(): void
    {
        $database = new GetFetchExecutionOptimizationProbe([
            ['id' => 1, 'name' => 'alpha'],
        ]);

        $result = $database
            ->safeOutput(true)
            ->withOne('post', 'posts', 'user_id', 'id')
            ->query('SELECT * FROM `users` LIMIT 1')
            ->fetch();

        self::assertSame(1, $database->executeCalls);
        self::assertSame(1, $database->sanitizeCalls);
        self::assertSame('fetch', $database->eagerLoadCalls[0]['type'] ?? null);
        self::assertTrue($result['sanitized']);
        self::assertTrue($result['eager_loaded']);
    }

    public function testFetchWithoutRowsUsesFastPathAndSkipsEagerLoading(): void
    {
        $database = new GetFetchExecutionOptimizationProbe([]);

        $result = $database
            ->safeOutput(true)
            ->query('SELECT * FROM `users` LIMIT 1')
            ->fetch();

        self::assertFalse($result);
        self::assertSame(1, $database->executeCalls);
        self::assertSame(1, $database->sanitizeCalls);
        self::assertSame([], $database->eagerLoadCalls);
    }
}