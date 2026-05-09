<?php

declare(strict_types=1);

use Core\Database\DatabaseHelper;
use Core\Database\BaseDatabase;
use PHPUnit\Framework\TestCase;

final class RawQueryGuardProbe extends DatabaseHelper
{
    public function assertAllowed(string|array $value): void
    {
        $this->_forbidRawQuery($value);
    }

    public function safeCacheSize(): int
    {
        return count($this->validatedRawQueryInputs);
    }
}

final class RawQueryGuardOptimizationTest extends TestCase
{
    public function testRawQueryGuardCachesSafeIdentifiers(): void
    {
        $helper = new RawQueryGuardProbe();

        $helper->assertAllowed('users.email');
        $helper->assertAllowed('users.email');

        self::assertSame(1, $helper->safeCacheSize());
    }

    public function testRawQueryGuardStillRejectsBareSqlKeywords(): void
    {
        $helper = new RawQueryGuardProbe();

        $this->expectException(\InvalidArgumentException::class);
        $helper->assertAllowed('SELECT');
    }

    public function testRawQueryGuardRejectsStackedQueries(): void
    {
        $helper = new RawQueryGuardProbe();

        $this->expectException(\InvalidArgumentException::class);
        $helper->assertAllowed('name; DROP TABLE users');
    }

    public function testHavingRawRejectsStackedQueries(): void
    {
        $database = new class extends BaseDatabase {
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
        };

        $this->expectException(\InvalidArgumentException::class);
        $database->havingRaw('COUNT(*) > 0; DROP TABLE users');
    }

    public function testToSqlDoesNotDuplicateHavingBindsAcrossRepeatedBuilds(): void
    {
        $database = new class extends BaseDatabase {
            public function __construct()
            {
                $this->table = 'users';
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
        };

        $database->select('id')->where('status', 1)->groupBy('status')->having('COUNT(*)', '1', '>');

        $first = $database->toSql();
        $second = $database->toSql();

        self::assertSame([1, '1'], $first['binds']);
        self::assertSame([1, '1'], $second['binds']);
    }

    public function testGroupByArrayQualifiesDottedColumnsCorrectly(): void
    {
        $database = new class extends BaseDatabase {
            public function __construct()
            {
                $this->table = 'users';
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
        };

        $sql = $database->select('id')->groupBy(['users.status', 'name'])->toSql();

        self::assertStringContainsString('GROUP BY `users`.`status`, `users`.`name`', $sql['query']);
    }

    public function testGroupByStringQualifiesCommaSeparatedColumnsCorrectly(): void
    {
        $database = new class extends BaseDatabase {
            public function __construct()
            {
                $this->table = 'users';
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
        };

        $sql = $database->select('id')->groupBy('users.status, name')->toSql();

        self::assertStringContainsString('GROUP BY `users`.`status`, `users`.`name`', $sql['query']);
    }

    public function testWhereHasWithJoinBuildsValidExistsSubqueryShape(): void
    {
        $database = new class extends BaseDatabase {
            public function __construct()
            {
                $this->table = 'users';
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
        };

        $sql = $database
            ->select('id')
            ->whereHas('user_profile', 'user_id', 'id', function ($query) {
                $query->leftJoin('master_roles', 'id', 'user_profile.role_id');
                $query->where('master_roles.role_status', 1);
            })
            ->toSql();

        self::assertStringContainsString('EXISTS (SELECT 1 FROM `user_profile` LEFT JOIN `master_roles` ON `master_roles`.`id` = `user_profile`.`role_id` WHERE `user_profile`.`user_id` = `users`.`id` AND (`master_roles`.`role_status` = ?))', $sql['query']);
        self::assertSame([1], $sql['binds']);
    }

    public function testWhereDoesntHaveWithJoinBuildsValidExistsSubqueryShape(): void
    {
        $database = new class extends BaseDatabase {
            public function __construct()
            {
                $this->table = 'users';
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
        };

        $sql = $database
            ->select('id')
            ->whereDoesntHave('user_profile', 'user_id', 'id', function ($query) {
                $query->leftJoin('master_roles', 'id', 'user_profile.role_id');
                $query->where('master_roles.role_status', 1);
            })
            ->toSql();

        self::assertStringContainsString('NOT EXISTS (SELECT 1 FROM `user_profile` LEFT JOIN `master_roles` ON `master_roles`.`id` = `user_profile`.`role_id` WHERE `user_profile`.`user_id` = `users`.`id` AND (`master_roles`.`role_status` = ?))', $sql['query']);
        self::assertSame([1], $sql['binds']);
    }
}