<?php

namespace Core\Database;

use Core\Database\StatementCache;

/**
 * Database Performance Monitor
 * 
 * Monitors and tracks database performance metrics including
 * query execution times, connection usage, memory consumption,
 * and slow query detection.
 * 
 * @category Database
 * @package  Core\Database
 * @author   Mohd Fahmy Izwan Zulkhafri <faizzul14@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0.html GNU Public License
 * @version  1.0.0
 */
class PerformanceMonitor
{
    /**
     * @var array Query execution logs
     */
    protected static $queryLog = [];

    /**
     * @var array Slow query log
     */
    protected static $slowQueries = [];

    /**
     * @var array<string, array{sql: string, count: int}> Per-request SELECT fingerprints for N+1 detection.
     * Key: md5 of normalized SQL. Value: array with SQL preview and execution count.
     */
    protected static array $queryFingerprints = [];

    /**
     * @var int Number of repeated identical query patterns that triggers an N+1 warning.
     * Override via PerformanceMonitor::setN1WarnThreshold().
     */
    protected static int $n1WarnThreshold = 30;

    /**
     * @var bool Track query fingerprints for N+1 detection even when monitoring is disabled.
     * Enabled automatically when APP_DEBUG is true.
     */
    protected static bool $n1DetectionEnabled = false;

    /**
     * @var float Slow query threshold in seconds
     */
    protected static $slowQueryThreshold = 1.0;

    /**
     * @var array Query type-specific slow thresholds (in seconds)
     */
    protected static $slowThresholdsByType = [
        'select' => 1.0,
        'insert' => 0.5,
        'update' => 0.5,
        'delete' => 0.5,
        'other' => 1.0
    ];

    /**
     * @var int Maximum log entries
     */
    protected static $maxLogSize = 1000;

    /**
     * @var bool Enable/disable monitoring
     */
    protected static $enabled = false;

    /**
     * @var bool Capture stack traces for monitored queries.
     */
    protected static $captureBacktraces = false;

    /**
     * @var array Performance statistics
     */
    protected static $stats = [
        'total_queries' => 0,
        'slow_queries' => 0,
        'total_time' => 0,
        'avg_time' => 0,
        'max_time' => 0,
        'min_time' => PHP_FLOAT_MAX,
        'memory_peak' => 0,
        'by_type' => [
            'select' => ['count' => 0, 'time' => 0.0],
            'insert' => ['count' => 0, 'time' => 0.0],
            'update' => ['count' => 0, 'time' => 0.0],
            'delete' => ['count' => 0, 'time' => 0.0],
            'other' => ['count' => 0, 'time' => 0.0]
        ]
    ];

    /**
     * @var array Active query timers
     */
    protected static $timers = [];

    /**
     * Start monitoring a query
     *
     * @param string $queryId Unique identifier for the query
     * @param string $sql SQL query
     * @param array  $binds Query parameters
     * @param string $queryType Type of query (select, insert, update, delete)
     * @return void
     */
    public static function startQuery($queryId, $sql, array $binds = [], $queryType = 'select')
    {
        if (!self::$enabled) {
            return;
        }

        self::$timers[$queryId] = [
            'sql' => $sql,
            'binds' => $binds,
            'query_type' => strtolower($queryType),
            'start_time' => microtime(true),
            'start_memory' => memory_get_usage(true),
        ];

        if (self::$captureBacktraces) {
            self::$timers[$queryId]['backtrace'] = self::getBacktrace();
        }
    }

    /**
     * End monitoring a query
     *
     * @param string $queryId Unique identifier for the query
     * @param int    $rowCount Number of rows affected/returned
     * @return void
     */
    public static function endQuery($queryId, $rowCount = 0)
    {
        if (!self::$enabled || !isset(self::$timers[$queryId])) {
            return;
        }

        $timer = self::$timers[$queryId];
        $endTime = microtime(true);
        $endMemory = memory_get_usage(true);

        $executionTime = $endTime - $timer['start_time'];
        $memoryUsed = $endMemory - $timer['start_memory'];

        $queryType = $timer['query_type'] ?? 'other';
        
        $entry = [
            'query_id' => $queryId,
            'sql' => $timer['sql'],
            'binds' => $timer['binds'],
            'query_type' => $queryType,
            'execution_time' => $executionTime,
            'memory_used' => $memoryUsed,
            'row_count' => $rowCount,
            'timestamp' => time(),
        ];

        if (isset($timer['backtrace'])) {
            $entry['backtrace'] = $timer['backtrace'];
        }

        // Track query fingerprint for N+1 detection (full-profiling path).
        // Called once here — trackSql() in HasProfiling is guarded by !self::$enabled
        // so there is no double-counting between the two code paths.
        self::trackQueryFingerprint($entry['sql']);

        // Add to query log
        self::addToLog($entry);

        // Update statistics
        self::updateStats($executionTime, $memoryUsed, $queryType);

        // Check if slow query — use per-type threshold for finer-grained detection
        $threshold = self::$slowThresholdsByType[$queryType] ?? self::$slowQueryThreshold;
        if ($executionTime > $threshold) {
            self::$slowQueries[] = $entry;
            self::$stats['slow_queries']++;

            // Keep only last N slow queries
            if (count(self::$slowQueries) > 100) {
                array_shift(self::$slowQueries);
            }
        }

        unset(self::$timers[$queryId]);
    }

    /**
     * Add entry to query log
     *
     * @param array $entry Log entry
     * @return void
     */
    protected static function addToLog(array $entry)
    {
        self::$queryLog[] = $entry;

        // Limit log size
        if (count(self::$queryLog) > self::$maxLogSize) {
            array_shift(self::$queryLog);
        }
    }

    /**
     * Lightweight N+1 tracking entry-point used when full profiling is OFF.
     * Called from HasProfiling::_startProfiler() before the profiling guard.
     * No-ops when both conditions are already handled elsewhere.
     *
     * @param string $sql Raw SQL string
     * @return void
     */
    public static function trackSql(string $sql): void
    {
        // Only active in N+1-detection-only mode.
        // When full profiling is on, endQuery() handles tracking to avoid double-counting.
        if (!self::$n1DetectionEnabled || self::$enabled) {
            return;
        }
        self::trackQueryFingerprint($sql);
    }

    /**
     * Track the normalized SQL fingerprint and emit an N+1 warning when the
     * same query pattern is executed more than $n1WarnThreshold times in one request.
     *
     * Only SELECT queries are inspected — repeated INSERTs/UPDATEs in loops
     * are a separate concern (batching) and not flagged here.
     *
     * @param string $sql Raw SQL (may contain PDO ? placeholders)
     * @return void
     */
    protected static function trackQueryFingerprint(string $sql): void
    {
        if (!self::$n1DetectionEnabled && !self::$enabled) {
            return;
        }

        // Only watch SELECT statements — N+1 is specifically a read-loop issue
        $firstWord = strtoupper(substr(ltrim($sql), 0, 6));
        if ($firstWord !== 'SELECT') {
            return;
        }

        // Normalize: collapse whitespace to produce a stable fingerprint
        $normalized = preg_replace('/\s+/', ' ', strtolower(trim($sql))) ?? $sql;
        $key        = md5($normalized);

        $prev  = self::$queryFingerprints[$key] ?? ['sql' => $sql, 'count' => 0];
        $count = $prev['count'] + 1;
        self::$queryFingerprints[$key] = ['sql' => $prev['sql'], 'count' => $count];

        // Warn once — exactly at the threshold to avoid log flooding
        if ($count === self::$n1WarnThreshold) {
            $preview = strlen($sql) > 120 ? substr($sql, 0, 120) . '...' : $sql;
            $message = sprintf(
                '[N+1 DETECTED] Query pattern executed %d times in one request. SQL: %s',
                $count,
                $preview
            );

            // Use the framework logger when available, fall back to error_log
            if (function_exists('logger') && is_callable(['Components\Logger', 'log_warning'])) {
                try {
                    logger()->log_warning($message);
                } catch (\Throwable) {
                    error_log($message);
                }
            } else {
                error_log($message);
            }
        }
    }

    /**
     * Return all query patterns that exceeded the N+1 detection threshold
     * during the current request, sorted by execution count descending.
     *
     * Reads from $queryFingerprints so it works correctly whether full
     * profiling is enabled or only N+1 detection is active.
     *
     * Useful in debug bars, development dashboards, or integration tests:
     *   $suspects = PerformanceMonitor::getN1Suspects();
     *
     * @return array{sql: string, count: int}[]
     */
    public static function getN1Suspects(): array
    {
        $suspects = array_values(
            array_filter(
                self::$queryFingerprints,
                static fn($entry) => $entry['count'] >= self::$n1WarnThreshold
            )
        );
        usort($suspects, static fn($a, $b) => $b['count'] <=> $a['count']);
        return $suspects;
    }

    /**
     * Set the repeated-query count that triggers an N+1 warning.
     *
     * @param int $threshold Minimum repeat count before warning is emitted (min 2)
     * @return void
     */
    public static function setN1WarnThreshold(int $threshold): void
    {
        self::$n1WarnThreshold = max(2, $threshold);
    }

    /**
     * Explicitly enable or disable N+1 fingerprint tracking independent of
     * the main monitoring toggle. Set to true when APP_DEBUG is on.
     *
     * @param bool $enabled
     * @return void
     */
    public static function setN1DetectionEnabled(bool $enabled): void
    {
        self::$n1DetectionEnabled = $enabled;
    }

    /**
     * Update performance statistics
     *
     * @param float $executionTime Query execution time
     * @param int   $memoryUsed Memory used
     * @param string $queryType Type of query
     * @return void
     */
    protected static function updateStats($executionTime, $memoryUsed, $queryType = 'other')
    {
        self::$stats['total_queries']++;
        self::$stats['total_time'] += $executionTime;
        self::$stats['avg_time'] = self::$stats['total_time'] / self::$stats['total_queries'];
        
        if ($executionTime > self::$stats['max_time']) {
            self::$stats['max_time'] = $executionTime;
        }
        
        if ($executionTime < self::$stats['min_time']) {
            self::$stats['min_time'] = $executionTime;
        }

        $currentMemory = memory_get_peak_usage(true);
        if ($currentMemory > self::$stats['memory_peak']) {
            self::$stats['memory_peak'] = $currentMemory;
        }

        // Update type-specific statistics
        if (isset(self::$stats['by_type'][$queryType])) {
            self::$stats['by_type'][$queryType]['count']++;
            self::$stats['by_type'][$queryType]['time'] += $executionTime;
        }
    }

    /**
     * Get simplified backtrace
     *
     * @return array
     */
    protected static function getBacktrace()
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5);
        $simplified = [];

        foreach ($trace as $item) {
            if (isset($item['file']) && isset($item['line'])) {
                $simplified[] = [
                    'file' => basename($item['file']),
                    'line' => $item['line'],
                    'function' => $item['function'] ?? 'unknown'
                ];
            }
        }

        return $simplified;
    }

    /**
     * Get performance statistics
     *
     * @return array
     */
    public static function getStats()
    {
        return array_merge(self::$stats, [
            'slow_query_threshold' => self::$slowQueryThreshold,
            'capture_backtraces' => self::$captureBacktraces,
            'queries_logged' => count(self::$queryLog),
            'slow_queries_logged' => count(self::$slowQueries),
            'current_memory' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true)
        ]);
    }

    /**
     * Get query log
     *
     * @param int $limit Number of entries to return
     * @return array
     */
    public static function getQueryLog($limit = null)
    {
        if ($limit === null) {
            return self::$queryLog;
        }

        return array_slice(self::$queryLog, -$limit);
    }

    /**
     * Get slow queries
     *
     * @param int $limit Number of entries to return
     * @return array
     */
    public static function getSlowQueries($limit = null)
    {
        $queries = self::$slowQueries;

        // Sort by execution time descending
        usort($queries, function($a, $b) {
            return $b['execution_time'] <=> $a['execution_time'];
        });

        if ($limit === null) {
            return $queries;
        }

        return array_slice($queries, 0, $limit);
    }

    /**
     * Get queries by execution time range
     *
     * @param float $minTime Minimum execution time
     * @param float $maxTime Maximum execution time
     * @return array
     */
    public static function getQueriesByTime($minTime, $maxTime = null)
    {
        $filtered = array_filter(self::$queryLog, function($entry) use ($minTime, $maxTime) {
            $time = $entry['execution_time'];
            if ($maxTime === null) {
                return $time >= $minTime;
            }
            return $time >= $minTime && $time <= $maxTime;
        });

        return array_values($filtered);
    }

    /**
     * Get most frequent queries
     *
     * @param int $limit Number of queries to return
     * @return array
     */
    public static function getMostFrequentQueries($limit = 10)
    {
        $frequency = [];

        foreach (self::$queryLog as $entry) {
            $key = md5($entry['sql']);
            if (!isset($frequency[$key])) {
                $frequency[$key] = [
                    'sql' => $entry['sql'],
                    'count' => 0,
                    'total_time' => 0,
                    'avg_time' => 0
                ];
            }
            $frequency[$key]['count']++;
            $frequency[$key]['total_time'] += $entry['execution_time'];
        }

        // Calculate averages
        foreach ($frequency as &$item) {
            $item['avg_time'] = $item['total_time'] / $item['count'];
        }

        // Sort by count descending
        usort($frequency, function($a, $b) {
            return $b['count'] <=> $a['count'];
        });

        return array_slice($frequency, 0, $limit);
    }

    /**
     * Get the most recent queries.
     *
     * @param int $limit Number of queries to return
     * @return array
     */
    public static function getRecentQueries($limit = 10)
    {
        if ($limit <= 0) {
            return [];
        }

        return array_slice(array_reverse(self::$queryLog), 0, $limit);
    }

    /**
     * Get queries with the highest total execution time.
     *
     * @param int $limit Number of queries to return
     * @return array
     */
    public static function getHeaviestQueries($limit = 10)
    {
        $queries = [];

        foreach (self::$queryLog as $entry) {
            $key = md5($entry['sql']);
            if (!isset($queries[$key])) {
                $queries[$key] = [
                    'sql' => $entry['sql'],
                    'query_type' => $entry['query_type'] ?? 'other',
                    'count' => 0,
                    'total_time' => 0.0,
                    'avg_time' => 0.0,
                    'max_time' => 0.0,
                ];
            }

            $queries[$key]['count']++;
            $queries[$key]['total_time'] += (float) ($entry['execution_time'] ?? 0);
            $queries[$key]['max_time'] = max($queries[$key]['max_time'], (float) ($entry['execution_time'] ?? 0));
        }

        foreach ($queries as &$query) {
            $query['avg_time'] = $query['count'] > 0
                ? $query['total_time'] / $query['count']
                : 0.0;
        }
        unset($query);

        usort($queries, function ($left, $right) {
            return $right['total_time'] <=> $left['total_time'];
        });

        return array_slice($queries, 0, $limit);
    }

    /**
     * Generate performance report
     *
     * @return array
     */
    public static function generateReport(array $options = [])
    {
        $slowLimit = max(1, (int) ($options['slow_limit'] ?? 10));
        $frequentLimit = max(1, (int) ($options['frequent_limit'] ?? 10));
        $recentLimit = max(1, (int) ($options['recent_limit'] ?? 10));
        $heavyLimit = max(1, (int) ($options['heavy_limit'] ?? 10));

        return [
            'summary' => self::getStats(),
            'slow_queries' => self::getSlowQueries($slowLimit),
            'frequent_queries' => self::getMostFrequentQueries($frequentLimit),
            'recent_queries' => self::getRecentQueries($recentLimit),
            'heavy_queries' => self::getHeaviestQueries($heavyLimit),
            'connection_stats' => ConnectionPool::getStats(),
            'statement_cache_stats' => StatementCache::getStats(),
            'query_cache_stats' => QueryCache::getStats(),
            'optimizer_stats' => EagerLoadOptimizer::getStats()
        ];
    }

    /**
     * Clear all logs and reset statistics.
     * Also resets per-request N+1 fingerprints.
     *
     * @return void
     */
    public static function reset()
    {
        self::$queryLog           = [];
        self::$slowQueries        = [];
        self::$timers             = [];
        self::$captureBacktraces  = false;
        self::$queryFingerprints  = [];
        self::$stats = [
            'total_queries' => 0,
            'slow_queries'  => 0,
            'total_time'    => 0,
            'avg_time'      => 0,
            'max_time'      => 0,
            'min_time'      => PHP_FLOAT_MAX,
            'memory_peak'   => 0,
            'by_type' => [
                'select' => ['count' => 0, 'time' => 0.0],
                'insert' => ['count' => 0, 'time' => 0.0],
                'update' => ['count' => 0, 'time' => 0.0],
                'delete' => ['count' => 0, 'time' => 0.0],
                'other'  => ['count' => 0, 'time' => 0.0],
            ],
        ];
    }

    /**
     * Flush only the per-request N+1 fingerprint table.
     *
     * Useful in Octane/worker mode where query logs are kept across requests
     * for aggregated metrics, but the per-request fingerprint set must be
     * cleared on each new request to avoid false positives.
     *
     * @return void
     */
    public static function resetQueryLog(): void
    {
        self::$queryFingerprints = [];
        self::$timers            = [];
    }

    /**
     * Set slow query threshold
     *
     * @param float $seconds Threshold in seconds
     * @return void
     */
    public static function setSlowQueryThreshold($seconds)
    {
        self::$slowQueryThreshold = max(0.001, (float)$seconds);
    }

    /**
     * Set maximum log size
     *
     * @param int $size Maximum number of log entries
     * @return void
     */
    public static function setMaxLogSize($size)
    {
        self::$maxLogSize = max(1, (int)$size);
    }

    /**
     * Enable monitoring
     *
     * @return void
     */
    public static function enable()
    {
        self::$enabled = true;
    }

    /**
     * Disable monitoring
     *
     * @return void
     */
    public static function disable()
    {
        self::$enabled = false;
    }

    /**
     * Enable or disable stack trace capture for monitored queries.
     *
     * @param bool $enabled
     * @return void
     */
    public static function setCaptureBacktraces($enabled): void
    {
        self::$captureBacktraces = (bool) $enabled;
    }

    /**
     * Check whether stack trace capture is enabled.
     *
     * @return bool
     */
    public static function isCapturingBacktraces(): bool
    {
        return self::$captureBacktraces;
    }

    /**
     * Check if monitoring is enabled
     *
     * @return bool
     */
    public static function isEnabled()
    {
        return self::$enabled;
    }

    /**
     * Export logs to file
     *
     * @param string $filePath File path to export to
     * @return bool Success status
     */
    public static function exportLogs($filePath)
    {
        $data = [
            'exported_at' => date('Y-m-d H:i:s'),
            'stats' => self::getStats(),
            'query_log' => self::$queryLog,
            'slow_queries' => self::$slowQueries
        ];

        return file_put_contents($filePath, json_encode($data, JSON_PRETTY_PRINT)) !== false;
    }
}
