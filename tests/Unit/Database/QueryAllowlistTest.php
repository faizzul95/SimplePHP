<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use Core\Database\BaseDatabase;
use PHPUnit\Framework\TestCase;

final class QueryAllowlistBuilderStub extends BaseDatabase
{
    public ?array $capturedOrderBy = null;
    public ?array $capturedWhere = null;

    public function __construct()
    {
    }

    public function connect($connectionID = null)
    {
        return $this;
    }

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

    public function orderBy($column, $direction = 'ASC')
    {
        $this->capturedOrderBy = [$column, $direction];
        return $this;
    }

    public function where($columnName, $operator = null, $value = null)
    {
        $this->capturedWhere = [$columnName, $operator, $value];
        return $this;
    }
}

final class QueryAllowlistTest extends TestCase
{
    public function testOrderBySafeResolvesQualifiedAllowlistAndSanitizesDirection(): void
    {
        $builder = new QueryAllowlistBuilderStub();

        $builder
            ->table('users')
            ->setSortableColumns(['users.name', 'users.email'])
            ->orderBySafe('name', 'drop table');

        self::assertSame(['users.name', 'ASC'], $builder->capturedOrderBy);
    }

    public function testOrderBySafeRejectsAmbiguousShortColumn(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ambiguous');

        $builder = new QueryAllowlistBuilderStub();
        $builder
            ->table('users')
            ->setSortableColumns(['users.name', 'profiles.name'])
            ->orderBySafe('name', 'ASC');
    }

    public function testWhereSafeUsesFilterableAllowlist(): void
    {
        $builder = new QueryAllowlistBuilderStub();

        $builder
            ->table('users')
            ->setFilterableColumns(['users.status'])
            ->whereSafe('status', 'active');

        self::assertSame(['users.status', '=', 'active'], $builder->capturedWhere);
    }

    public function testResetClearsQueryAllowlistState(): void
    {
        $builder = new QueryAllowlistBuilderStub();

        $builder
            ->table('users')
            ->setSortableColumns(['users.name'])
            ->setFilterableColumns(['users.email'])
            ->reset();

        self::assertSame([], $builder->getSortableColumns());
        self::assertSame([], $builder->getFilterableColumns());
    }
}
