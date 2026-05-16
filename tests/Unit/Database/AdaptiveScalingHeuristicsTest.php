<?php

declare(strict_types=1);

use Core\Database\BaseDatabase;
use Core\Database\EagerLoadOptimizer;
use Core\Database\Model;
use PHPUnit\Framework\TestCase;

require_once ROOT_DIR . 'systems/app.php';

final class AdaptiveEagerLoadingProbe extends BaseDatabase
{
    public array $eagerChunkHistory = [];

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
    public function limit($limit) { return $this; }
    public function offset($offset) { return $this; }
    public function count($table = null) { return 0; }
    public function exists($table = null) { return false; }
    public function _getLimitOffsetPaginate($query, $limit, $offset) { return $query; }
    public function batchInsert($data) { return []; }
    public function batchUpdate($data) { return []; }
    public function upsert($values, $uniqueBy = 'id', $updateColumns = null) { return []; }
    protected function sanitizeColumn($data): array { return is_array($data) ? $data : []; }

    public function exposeProcessEagerLoadingInBatches(array &$data, array $primaryKeys, string $table, string $fk_id, string $pk_id, string $connectionName, string $method, string $alias): void
    {
        $this->_processEagerLoadingInBatches($data, $primaryKeys, $table, $fk_id, $pk_id, $connectionName, $method, $alias);
    }

    protected function _processEagerByChunk($chunk, ?\Closure $callback, $connectionObj, $table, $fk_id, ?bool $preferIntegerRawIn = null)
    {
        $this->eagerChunkHistory[] = array_values($chunk);

        return array_map(function (int $id) use ($fk_id): array {
            $row = [$fk_id => $id, 'title' => 'Post ' . $id];
            for ($column = 1; $column <= 26; $column++) {
                $row['extra_' . $column] = 'value-' . $column;
            }

            return $row;
        }, array_values($chunk));
    }

    protected function recommendedReadChunkSize(?array $sampleRow = null, int $requestedSize = 1000): int
    {
        if ($sampleRow === null || $sampleRow === []) {
            return $requestedSize;
        }

        return 2;
    }
}

final class MillionRowStreamingHarnessProbe extends BaseDatabase
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
            $rows[] = $this->buildWideRow($id);
        }

        return $rows;
    }

    private function buildWideRow(int $id): array
    {
        $row = ['id' => $id, 'name' => 'User ' . $id, 'email' => 'user' . $id . '@example.test'];
        for ($column = 1; $column <= 26; $column++) {
            $row['col_' . $column] = 'value-' . $column;
        }

        return $row;
    }
}

final class MillionRowBatchWriteHarnessProbe extends BaseDatabase
{
    public static array $batchInsertCounts = [];

    public function __construct()
    {
        $this->table = 'users';
        $this->column = '*';
    }

    public static function resetState(): void
    {
        self::$batchInsertCounts = [];
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
    public function batchUpdate($data) { return []; }
    public function upsert($values, $uniqueBy = 'id', $updateColumns = null) { return []; }
    protected function sanitizeColumn($data): array { return is_array($data) ? $data : []; }

    public function batchInsert($data)
    {
        self::$batchInsertCounts[] = count($data);
        return ['code' => 201, 'affected_rows' => count($data)];
    }
}

final class AdaptiveScalingHeuristicsTest extends TestCase
{
    private array $originalOptimizerConfig = [];

    protected function setUp(): void
    {
        parent::setUp();
        reset_framework_service();
        $this->originalOptimizerConfig = EagerLoadOptimizer::getConfig();
        EagerLoadOptimizer::clearHistory();
        MillionRowBatchWriteHarnessProbe::resetState();
    }

    protected function tearDown(): void
    {
        EagerLoadOptimizer::setConfig($this->originalOptimizerConfig);
        EagerLoadOptimizer::clearHistory();
        parent::tearDown();
    }

    public function testEagerLoadingShrinksFollowUpChunksForWideRelatedRows(): void
    {
        EagerLoadOptimizer::setConfig([
            'min_chunk_size' => 1,
            'max_chunk_size' => 20,
            'default_chunk_size' => 5,
        ]);

        $database = new AdaptiveEagerLoadingProbe();
        $data = array_map(static fn (int $id): array => ['id' => $id], range(1, 9));

        $database->exposeProcessEagerLoadingInBatches($data, range(1, 9), 'posts', 'user_id', 'id', 'default', 'get', 'posts');

        self::assertSame([
            [1, 2, 3, 4, 5],
            [6, 7],
            [8, 9],
        ], $database->eagerChunkHistory);
        self::assertCount(1, $data[0]['posts']);
        self::assertSame(1, $data[0]['posts'][0]['user_id']);
        self::assertCount(1, $data[8]['posts']);
        self::assertSame(9, $data[8]['posts'][0]['user_id']);
    }

    public function testMillionRowLazyByIdHarnessShrinksFollowUpChunksWithoutMaterializingDataset(): void
    {
        $database = new MillionRowStreamingHarnessProbe();

        $consumed = 0;
        foreach ($database->lazyById(2000) as $row) {
            $consumed++;
            if ($consumed >= 2250) {
                break;
            }
        }

        self::assertSame(2250, $consumed);
        self::assertSame([2000, 250], array_slice($database->limitHistory, 0, 2));
    }

    public function testMillionRowInsertHarnessShrinksBatchSizeWithoutMaterializingDataset(): void
    {
        $database = new MillionRowBatchWriteHarnessProbe();

        $processed = $database->insertInBatches((function (): \Generator {
            for ($id = 1; $id <= 1000000; $id++) {
                $row = ['id' => $id, 'name' => 'User ' . $id, 'email' => 'user' . $id . '@example.test'];
                for ($column = 1; $column <= 26; $column++) {
                    $row['col_' . $column] = 'value-' . $column;
                }

                yield $row;
            }
        })(), static fn (array $state): bool => $state['batches_processed'] < 2);

        self::assertSame(500, $processed);
        self::assertSame([250, 250], MillionRowBatchWriteHarnessProbe::$batchInsertCounts);
    }

    public function testProfilingCapturesAdaptiveStreamingDecisions(): void
    {
        $database = new MillionRowStreamingHarnessProbe();
        $database->setProfilingEnabled(true);

        $consumed = 0;
        foreach ($database->lazyById(2000) as $row) {
            $consumed++;
            if ($consumed >= 2250) {
                break;
            }
        }

        $decisions = $this->collectAdaptiveChunkDecisions($database->profiler());

        self::assertNotEmpty($decisions);
        self::assertTrue($this->hasAdaptiveDecision($decisions, 'streaming', 'lazyById', 2000, 250));
    }

    public function testProfilingCapturesAdaptiveEagerLoadingDecisions(): void
    {
        EagerLoadOptimizer::setConfig([
            'min_chunk_size' => 1,
            'max_chunk_size' => 20,
            'default_chunk_size' => 5,
        ]);

        $database = new AdaptiveEagerLoadingProbe();
        $database->setProfilingEnabled(true);
        $data = array_map(static fn (int $id): array => ['id' => $id], range(1, 9));

        $database->exposeProcessEagerLoadingInBatches($data, range(1, 9), 'posts', 'user_id', 'id', 'default', 'get', 'posts');

        $decisions = $this->collectAdaptiveChunkDecisions($database->profiler());

        self::assertNotEmpty($decisions);
        self::assertTrue($this->hasAdaptiveDecision($decisions, 'eager_loading', null, 5, 2, 'posts'));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function collectAdaptiveChunkDecisions(array $profiler): array
    {
        $decisions = [];
        foreach (($profiler['profiling'] ?? []) as $entry) {
            if (!isset($entry['adaptive_chunk_decisions']) || !is_array($entry['adaptive_chunk_decisions'])) {
                continue;
            }

            foreach ($entry['adaptive_chunk_decisions'] as $decision) {
                if (is_array($decision)) {
                    $decisions[] = $decision;
                }
            }
        }

        return $decisions;
    }

    /**
     * @param array<int, array<string, mixed>> $decisions
     */
    private function hasAdaptiveDecision(array $decisions, string $phase, ?string $method, int $previousSize, int $nextSize, ?string $relation = null): bool
    {
        foreach ($decisions as $decision) {
            if (($decision['phase'] ?? null) !== $phase) {
                continue;
            }

            if ($method !== null && ($decision['method'] ?? null) !== $method) {
                continue;
            }

            if ($relation !== null && ($decision['relation'] ?? null) !== $relation) {
                continue;
            }

            if (($decision['previous_size'] ?? null) === $previousSize && ($decision['next_size'] ?? null) === $nextSize) {
                return true;
            }
        }

        return false;
    }
}