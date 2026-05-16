<?php

declare(strict_types=1);

use Core\Database\BaseDatabase;
use PHPUnit\Framework\TestCase;

class BaseDatabaseBatchProcessingProbe extends BaseDatabase
{
    public static array $batchInsertCalls = [];
    public static array $batchUpdateCalls = [];
    public static array $upsertCalls = [];
    public static array $whereInCalls = [];
    public static array $deleteCalls = [];

    public function __construct()
    {
        $this->table = 'users';
        $this->column = '*';
        $this->driver = 'mysql';
    }

    public static function resetState(): void
    {
        self::$batchInsertCalls = [];
        self::$batchUpdateCalls = [];
        self::$upsertCalls = [];
        self::$whereInCalls = [];
        self::$deleteCalls = [];
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
    protected function sanitizeColumn($data): array { return is_array($data) ? $data : []; }

    public function batchInsert($data)
    {
        self::$batchInsertCalls[] = $data;
        return ['code' => 201, 'affected_rows' => count($data)];
    }

    public function batchUpdate($data)
    {
        self::$batchUpdateCalls[] = $data;
        return ['code' => 200, 'affected_rows' => count($data)];
    }

    public function upsert($values, $uniqueBy = 'id', $updateColumns = null)
    {
        self::$upsertCalls[] = [$values, $uniqueBy, $updateColumns];
        return ['code' => 200, 'affected_rows' => count($values)];
    }

    public function whereIn($column, $value = [])
    {
        self::$whereInCalls[] = [$column, $value];
        return $this;
    }

    public function delete($returnData = false)
    {
        self::$deleteCalls[] = end(self::$whereInCalls);
        return true;
    }
}

final class AdaptiveBaseDatabaseBatchProcessingProbe extends BaseDatabaseBatchProcessingProbe
{
    protected function recommendedIterableWriteBatchSize(?array $sampleRow = null): int
    {
        return 2;
    }
}

final class BaseDatabaseBatchProcessingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        BaseDatabaseBatchProcessingProbe::resetState();
    }

    public function testInsertInBatchesConsumesIterableAndReportsProgress(): void
    {
        $database = new BaseDatabaseBatchProcessingProbe();
        $progress = [];

        $processed = $database->insertInBatches((function (): \Generator {
            yield ['name' => 'Alpha'];
            yield ['name' => 'Beta'];
            yield ['name' => 'Gamma'];
        })(), function (array $state) use (&$progress): void {
            $progress[] = $state;
        }, 2);

        self::assertSame(3, $processed);
        self::assertCount(2, BaseDatabaseBatchProcessingProbe::$batchInsertCalls);
        self::assertSame([
            ['operation' => 'insert', 'processed_rows' => 2, 'batches_processed' => 1, 'last_batch_rows' => 2],
            ['operation' => 'insert', 'processed_rows' => 3, 'batches_processed' => 2, 'last_batch_rows' => 1],
        ], $progress);
    }

    public function testUpdateInBatchesStopsAfterCommittedBatchWhenProgressReturnsFalse(): void
    {
        $database = new BaseDatabaseBatchProcessingProbe();

        $processed = $database->updateInBatches((function (): \Generator {
            yield ['id' => 1, 'status' => 'active'];
            yield ['id' => 2, 'status' => 'inactive'];
            yield ['id' => 3, 'status' => 'pending'];
        })(), static fn(array $state): bool => $state['batches_processed'] < 1, 2);

        self::assertSame(2, $processed);
        self::assertCount(1, BaseDatabaseBatchProcessingProbe::$batchUpdateCalls);
    }

    public function testUpsertInBatchesUsesIterableAndPropagatesArguments(): void
    {
        $database = new BaseDatabaseBatchProcessingProbe();

        $processed = $database->upsertInBatches((function (): \Generator {
            yield ['email' => 'a@example.test', 'name' => 'Alpha'];
            yield ['email' => 'b@example.test', 'name' => 'Beta'];
        })(), 'email', ['name'], null, 1);

        self::assertSame(2, $processed);
        self::assertCount(2, BaseDatabaseBatchProcessingProbe::$upsertCalls);
        self::assertSame('email', BaseDatabaseBatchProcessingProbe::$upsertCalls[0][1]);
        self::assertSame(['name'], BaseDatabaseBatchProcessingProbe::$upsertCalls[0][2]);
    }

    public function testDeleteInBatchesConsumesIterableValuesInChunks(): void
    {
        $database = new BaseDatabaseBatchProcessingProbe();

        $processed = $database->deleteInBatches((function (): \Generator {
            yield 10;
            yield 11;
            yield 12;
        })(), 'id', null, 2);

        self::assertSame(3, $processed);
        self::assertSame([
            ['id', [10, 11]],
            ['id', [12]],
        ], BaseDatabaseBatchProcessingProbe::$whereInCalls);
        self::assertCount(2, BaseDatabaseBatchProcessingProbe::$deleteCalls);
    }

    public function testDeleteInBatchesDeduplicatesChunkValues(): void
    {
        $database = new BaseDatabaseBatchProcessingProbe();

        $processed = $database->deleteInBatches([10, 10, 11], 'id', null, 10);

        self::assertSame(2, $processed);
        self::assertSame([['id', [10, 11]]], BaseDatabaseBatchProcessingProbe::$whereInCalls);
    }

    public function testInsertInBatchesUsesAdaptiveBatchSizeWhenNoOverrideIsProvided(): void
    {
        $database = new AdaptiveBaseDatabaseBatchProcessingProbe();

        $processed = $database->insertInBatches([
            ['name' => 'Alpha', 'email' => 'a@example.test', 'status' => 'active'],
            ['name' => 'Beta', 'email' => 'b@example.test', 'status' => 'inactive'],
            ['name' => 'Gamma', 'email' => 'c@example.test', 'status' => 'pending'],
        ]);

        self::assertSame(3, $processed);
        self::assertCount(2, BaseDatabaseBatchProcessingProbe::$batchInsertCalls);
        self::assertCount(2, BaseDatabaseBatchProcessingProbe::$batchInsertCalls[0]);
        self::assertCount(1, BaseDatabaseBatchProcessingProbe::$batchInsertCalls[1]);
    }
}