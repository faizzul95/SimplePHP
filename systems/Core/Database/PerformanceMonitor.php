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
    protected static $enabled = true;

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
            'backtrace' => self::getBacktrace()
        ];
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
            'backtrace' => $timer['backtrace']
        ];

        // Add to query log
        self::addToLog($entry, $queryType);

        // Update statistics
        self::updateStats($executionTime, $memoryUsed, $queryType);

        // Check if slow query
        if ($executionTime > self::$slowQueryThreshold) {
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
     * Generate performance report
     *
     * @return array
     */
    public static function generateReport()
    {
        return [
            'summary' => self::getStats(),
            'slow_queries' => self::getSlowQueries(10),
            'frequent_queries' => self::getMostFrequentQueries(10),
            'connection_stats' => ConnectionPool::getStats(),
            'statement_cache_stats' => StatementCache::getStats(),
            'query_cache_stats' => QueryCache::getStats(),
            'optimizer_stats' => EagerLoadOptimizer::getStats()
        ];
    }

    /**
     * Clear all logs and reset statistics
     *
     * @return void
     */
    public static function reset()
    {
        self::$queryLog = [];
        self::$slowQueries = [];
        self::$timers = [];
        self::$stats = [
            'total_queries' => 0,
            'slow_queries' => 0,
            'total_time' => 0,
            'avg_time' => 0,
            'max_time' => 0,
            'min_time' => PHP_FLOAT_MAX,
            'memory_peak' => 0
        ];
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
