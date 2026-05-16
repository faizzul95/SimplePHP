<?php

declare(strict_types=1);

use Core\Database\BaseDatabase;
use Core\Database\PerformanceMonitor;
use PHPUnit\Framework\TestCase;

final class DebugSnapshotProbe extends BaseDatabase
{
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

    protected function _generateFullQuery($query, $binds = null, bool $storeInProfiler = true)
    {
        if (!empty($binds)) {
            foreach ($binds as $value) {
                $replacement = is_numeric($value) ? (string) $value : "'" . (string) $value . "'";
                $query = preg_replace('/\?/', $replacement, $query, 1);
            }
        }

        if ($storeInProfiler) {
            $this->_profiler['profiling'][$this->_profilerActive]['full_query'] = $query;
        }

        return $query;
    }

    public function recordAdaptiveDecision(array $metadata): void
    {
        $this->_appendProfilerMetadata('adaptive_chunk_decisions', $metadata);
    }
}

final class DebugSnapshotTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        PerformanceMonitor::reset();
        PerformanceMonitor::enable();
    }

    public function testToDebugSnapshotIncludesSqlProfilerAndPerformanceReport(): void
    {
        $database = new DebugSnapshotProbe();
        $database->setProfilingEnabled(true);
        $database->select('id')->where('status', 'active');
        $database->recordAdaptiveDecision([
            'phase' => 'streaming',
            'method' => 'lazyById',
            'previous_size' => 2000,
            'next_size' => 250,
            'observed_rows' => 2000,
            'observed_columns' => 29,
        ]);

        PerformanceMonitor::startQuery('debug-snapshot-query', 'SELECT id FROM users WHERE status = ?', ['active'], 'select');
        usleep(1000);
        PerformanceMonitor::endQuery('debug-snapshot-query', 1);

        $snapshot = $database->toDebugSnapshot([
            'recent_limit' => 1,
            'adaptive_limit' => 1,
        ]);

        self::assertIsArray($snapshot['sql']);
        self::assertStringContainsString('SELECT', (string) $snapshot['sql']['query']);
        self::assertStringContainsString('users', (string) $snapshot['raw_sql']);
        self::assertIsArray($snapshot['debug_sql']);
        self::assertIsArray($snapshot['profiler']);
        self::assertSame(1, $snapshot['performance_report']['adaptive_chunk_stats']['total_decisions']);
        self::assertCount(1, $snapshot['performance_report']['recent_adaptive_chunk_decisions']);
        self::assertSame('lazyById', $snapshot['performance_report']['recent_adaptive_chunk_decisions'][0]['method']);
    }
}