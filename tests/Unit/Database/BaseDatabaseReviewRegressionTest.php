<?php

declare(strict_types=1);

use Core\Database\BaseDatabase;
use PHPUnit\Framework\TestCase;

final class BaseDatabaseQueryHelperProbe extends BaseDatabase
{
    public array $whereCalls = [];
    public array $whereInCalls = [];
    public mixed $fetchResult = null;
    public mixed $getResult = [];
    public array $insertOrUpdateCalls = [];

    public function __construct()
    {
        $this->table = 'users';
        $this->column = '*';
        $this->driver = 'mysql';
    }

    public function setDriverName(string $driver): void
    {
        $this->driver = $driver;
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

    public function where($columnName, $operator = null, $value = null)
    {
        if ($value === null && $operator !== 'IS NULL' && $operator !== 'IS NOT NULL') {
            $value = $operator;
            $operator = '=';
        }

        $this->whereCalls[] = [$columnName, $operator, $value];
        return $this;
    }

    public function whereIn($column, $value = [])
    {
        $this->whereInCalls[] = [$column, $value];
        return $this;
    }

    public function fetch($table = null)
    {
        return $this->fetchResult;
    }

    public function get($table = null)
    {
        return $this->getResult;
    }

    public function insertOrUpdate($conditions, $data, $primaryKey = 'id')
    {
        $this->insertOrUpdateCalls[] = [$conditions, $data, $primaryKey];
        return ['code' => 200];
    }
}

final class BaseDatabaseSqlProbe extends BaseDatabase
{
    public function __construct()
    {
        $this->table = 'users';
        $this->column = '*';
        $this->driver = 'mysql';
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

    public function currentWhere(): ?string
    {
        return $this->where;
    }

    public function currentBinds(): array
    {
        return $this->_binds;
    }

    public function buildInsertSql(array $data): string
    {
        $this->_buildInsertQuery($data);
        return $this->_query;
    }

    public function buildUpdateSql(array $data): string
    {
        $this->_buildUpdateQuery($data);
        return $this->_query;
    }

    public function expandWildcards(string $query): string
    {
        return $this->_expandAsterisksInQuery($query);
    }
}

final class BaseDatabaseReviewRegressionTest extends TestCase
{
    public function testGetPlatformUsesActiveDriver(): void
    {
        $database = new BaseDatabaseQueryHelperProbe();
        $database->setDriverName('mysql');

        self::assertSame('MySQL', $database->getPlatform());
    }

    public function testValueReturnsQualifiedColumnResult(): void
    {
        $database = new BaseDatabaseQueryHelperProbe();
        $database->fetchResult = ['name' => 'Alice'];

        self::assertSame('Alice', $database->value('users.name'));
    }

    public function testFindDelegatesToPrimaryKeyLookup(): void
    {
        $database = new BaseDatabaseQueryHelperProbe();
        $database->fetchResult = ['id' => 5, 'name' => 'Alpha'];

        $result = $database->find(5);

        self::assertSame(['id' => 5, 'name' => 'Alpha'], $result);
        self::assertSame([['id', '=', 5]], $database->whereCalls);
    }

    public function testFindManyDelegatesToWhereIn(): void
    {
        $database = new BaseDatabaseQueryHelperProbe();
        $database->getResult = [
            ['id' => 1, 'name' => 'Alpha'],
            ['id' => 2, 'name' => 'Beta'],
        ];

        $result = $database->findMany([1, 2, 2]);

        self::assertCount(2, $result);
        self::assertSame([['id', [1, 2]]], $database->whereInCalls);
    }

    public function testFindOrFailThrowsWhenMissing(): void
    {
        $database = new BaseDatabaseQueryHelperProbe();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('No records found matching the query');

        $database->findOrFail(99);
    }

    public function testFirstOrNewReturnsMergedAttributesWhenMissing(): void
    {
        $database = new BaseDatabaseQueryHelperProbe();

        self::assertSame(
            ['email' => 'user@example.com', 'name' => 'Demo'],
            $database->firstOrNew(['email' => 'user@example.com'], ['name' => 'Demo'])
        );
    }

    public function testUpdateOrCreateReturnsFetchedRecord(): void
    {
        $database = new BaseDatabaseQueryHelperProbe();
        $database->fetchResult = ['email' => 'user@example.com', 'name' => 'Updated'];

        $result = $database->updateOrCreate(['email' => 'user@example.com'], ['name' => 'Updated']);

        self::assertSame(['email' => 'user@example.com', 'name' => 'Updated'], $result);
        self::assertSame([[['email' => 'user@example.com'], ['name' => 'Updated'], 'id']], $database->insertOrUpdateCalls);
    }

    public function testWhereInWithEmptyArrayCompilesFalsePredicate(): void
    {
        $database = new BaseDatabaseSqlProbe();

        $database->whereIn('id', []);

        self::assertSame('(0 = 1)', $database->currentWhere());
        self::assertSame([], $database->currentBinds());
    }

    public function testWhereNotInWithEmptyArrayIsNoOp(): void
    {
        $database = new BaseDatabaseSqlProbe();

        $database->whereNotIn('id', []);

        self::assertNull($database->currentWhere());
        self::assertSame([], $database->currentBinds());
    }

    public function testWhereInSplitsLargeListsIntoGroupedClauses(): void
    {
        $database = new BaseDatabaseSqlProbe();

        $database->whereIn('id', range(1, 1001));

        self::assertStringContainsString(' OR ', (string) $database->currentWhere());
        self::assertStringContainsString('`users`.`id` IN (', (string) $database->currentWhere());
        self::assertSame(range(1, 1001), $database->currentBinds());
    }

    public function testWhereNotInSplitsLargeListsIntoGroupedClauses(): void
    {
        $database = new BaseDatabaseSqlProbe();

        $database->whereNotIn('id', range(1, 1001));

        self::assertStringContainsString(' AND ', (string) $database->currentWhere());
        self::assertStringContainsString('`users`.`id` NOT IN (', (string) $database->currentWhere());
        self::assertSame(range(1, 1001), $database->currentBinds());
    }

    public function testWhereIntegerInRawSplitsLargeListsIntoGroupedClauses(): void
    {
        $database = new BaseDatabaseSqlProbe();

        $database->whereIntegerInRaw('id', range(1, 1001));

        self::assertStringContainsString(' OR ', (string) $database->currentWhere());
        self::assertStringContainsString('`users`.`id` IN (', (string) $database->currentWhere());
        self::assertSame([], $database->currentBinds());
    }

    public function testWhereIntegerNotInRawSplitsLargeListsIntoGroupedClauses(): void
    {
        $database = new BaseDatabaseSqlProbe();

        $database->whereIntegerNotInRaw('id', range(1, 1001));

        self::assertStringContainsString(' AND ', (string) $database->currentWhere());
        self::assertStringContainsString('`users`.`id` NOT IN (', (string) $database->currentWhere());
        self::assertSame([], $database->currentBinds());
    }

    public function testBuildInsertQueryEscapesIdentifiers(): void
    {
        $database = new BaseDatabaseSqlProbe();

        $sql = $database->buildInsertSql(['na`me' => 'Alpha', 'email' => 'a@example.com']);

        self::assertStringContainsString('(`na``me`, `email`)', $sql);
    }

    public function testBuildUpdateQueryEscapesIdentifiers(): void
    {
        $database = new BaseDatabaseSqlProbe();

        $sql = $database->buildUpdateSql(['na`me' => 'Alpha']);

        self::assertStringContainsString('SET `na``me` = ?', $sql);
    }

    public function testExpandWildcardsLeavesExplicitSelectListsUntouched(): void
    {
        $database = new BaseDatabaseSqlProbe();

        $query = 'SELECT `users`.`id`, `users`.`name` FROM `users` LEFT JOIN `profiles` ON `profiles`.`user_id` = `users`.`id`';

        self::assertSame($query, $database->expandWildcards($query));
    }
}
