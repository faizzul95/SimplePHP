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