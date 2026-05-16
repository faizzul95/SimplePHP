<?php

declare(strict_types=1);

use Core\Database\PerformanceMonitor;
use PHPUnit\Framework\TestCase;

final class PerformanceMonitorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        PerformanceMonitor::reset();
        PerformanceMonitor::enable();
        PerformanceMonitor::setSlowQueryThreshold(0.000001);
    }

    public function testGenerateReportIncludesRecentAndHeavyQueries(): void
    {
        PerformanceMonitor::startQuery('query-1', 'SELECT 1', [], 'select');
        usleep(2000);
        PerformanceMonitor::endQuery('query-1', 1);

        PerformanceMonitor::startQuery('query-2', 'SELECT 1', [], 'select');
        usleep(1000);
        PerformanceMonitor::endQuery('query-2', 1);

        PerformanceMonitor::startQuery('query-3', 'UPDATE users SET name = ?', ['Myth'], 'update');
        usleep(1000);
        PerformanceMonitor::endQuery('query-3', 1);

        $report = PerformanceMonitor::generateReport([
            'slow_limit' => 1,
            'frequent_limit' => 1,
            'recent_limit' => 1,
            'heavy_limit' => 1,
        ]);

        self::assertCount(1, $report['slow_queries']);
        self::assertCount(1, $report['frequent_queries']);
        self::assertCount(1, $report['recent_queries']);
        self::assertCount(1, $report['heavy_queries']);
        self::assertSame('query-3', $report['recent_queries'][0]['query_id']);
        self::assertSame('SELECT 1', $report['frequent_queries'][0]['sql']);
        self::assertContains($report['heavy_queries'][0]['sql'], ['SELECT 1', 'UPDATE users SET name = ?']);
        self::assertGreaterThan(0, $report['heavy_queries'][0]['total_time']);
        self::assertSame(0, $report['adaptive_chunk_stats']['total_decisions']);
        self::assertSame([], $report['recent_adaptive_chunk_decisions']);
    }

    public function testGenerateReportIncludesAdaptiveChunkDecisionStats(): void
    {
        PerformanceMonitor::recordAdaptiveChunkDecision([
            'phase' => 'streaming',
            'method' => 'lazyById',
            'previous_size' => 2000,
            'next_size' => 250,
            'observed_rows' => 2000,
            'observed_columns' => 29,
        ]);
        PerformanceMonitor::recordAdaptiveChunkDecision([
            'phase' => 'eager_loading',
            'relation' => 'posts',
            'previous_size' => 5,
            'next_size' => 2,
            'observed_rows' => 5,
            'observed_columns' => 28,
        ]);

        $report = PerformanceMonitor::generateReport([
            'adaptive_limit' => 2,
        ]);

        self::assertSame(2, $report['adaptive_chunk_stats']['total_decisions']);
        self::assertSame(1753, $report['adaptive_chunk_stats']['total_reduction']);
        self::assertSame(1750, $report['adaptive_chunk_stats']['largest_reduction']);
        self::assertSame(1, $report['adaptive_chunk_stats']['by_phase']['streaming']['count']);
        self::assertSame(1, $report['adaptive_chunk_stats']['by_phase']['eager_loading']['count']);
        self::assertCount(2, $report['recent_adaptive_chunk_decisions']);
        self::assertSame('eager_loading', $report['recent_adaptive_chunk_decisions'][0]['phase']);
        self::assertSame('streaming', $report['recent_adaptive_chunk_decisions'][1]['phase']);
    }

    public function testBacktracesAreOptIn(): void
    {
        PerformanceMonitor::startQuery('query-no-trace', 'SELECT 1', [], 'select');
        usleep(1000);
        PerformanceMonitor::endQuery('query-no-trace', 1);

        $entries = PerformanceMonitor::getQueryLog();
        self::assertArrayNotHasKey('backtrace', $entries[0]);
        self::assertFalse(PerformanceMonitor::isCapturingBacktraces());

        PerformanceMonitor::reset();
        PerformanceMonitor::enable();
        PerformanceMonitor::setCaptureBacktraces(true);

        PerformanceMonitor::startQuery('query-trace', 'SELECT 1', [], 'select');
        usleep(1000);
        PerformanceMonitor::endQuery('query-trace', 1);

        $entries = PerformanceMonitor::getQueryLog();
        self::assertArrayHasKey('backtrace', $entries[0]);
        self::assertIsArray($entries[0]['backtrace']);
        self::assertTrue(PerformanceMonitor::isCapturingBacktraces());
    }
}