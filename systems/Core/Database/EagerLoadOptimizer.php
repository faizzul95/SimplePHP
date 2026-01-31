<?php

namespace Core\Database;

/**
 * Eager Loading Optimizer
 * 
 * Optimizes eager loading queries by implementing smart batching,
 * query optimization, and adaptive chunk sizing.
 * 
 * @category Database
 * @package  Core\Database
 * @author   Mohd Fahmy Izwan Zulkhafri <faizzul14@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0.html GNU Public License
 * @version  1.0.0
 */
class EagerLoadOptimizer
{
    /**
     * @var array Configuration for adaptive chunk sizing
     */
    protected static $config = [
        'min_chunk_size' => 100,
        'max_chunk_size' => 2000,
        'default_chunk_size' => 1000,
        'adaptive_enabled' => true,
        'use_in_clause_optimization' => true,
    ];

    /**
     * @var array Performance history for adaptive sizing
     */
    protected static $performanceHistory = [];

    /**
     * Calculate optimal chunk size based on data characteristics
     *
     * @param int    $totalRecords Total number of records
     * @param string $table Table name
     * @param array  $history Performance history (optional)
     * @return int Optimal chunk size
     */
    public static function getOptimalChunkSize($totalRecords, $table = null, array $history = [])
    {
        if (!self::$config['adaptive_enabled']) {
            return self::$config['default_chunk_size'];
        }

        // For small datasets, process in one chunk
        if ($totalRecords <= self::$config['min_chunk_size']) {
            return $totalRecords;
        }

        // For medium datasets, use default
        if ($totalRecords <= self::$config['max_chunk_size']) {
            return self::$config['default_chunk_size'];
        }

        // For large datasets, calculate based on history
        if ($table && isset(self::$performanceHistory[$table])) {
            $avgTime = self::$performanceHistory[$table]['avg_time'];
            $avgSize = self::$performanceHistory[$table]['avg_size'];

            // If queries are fast, increase chunk size
            if ($avgTime < 0.1 && $avgSize < self::$config['max_chunk_size']) {
                return min(self::$config['max_chunk_size'], $avgSize * 1.5);
            }

            // If queries are slow, decrease chunk size
            if ($avgTime > 0.5 && $avgSize > self::$config['min_chunk_size']) {
                return max(self::$config['min_chunk_size'], $avgSize * 0.75);
            }

            return $avgSize;
        }

        return self::$config['default_chunk_size'];
    }

    /**
     * Record performance metrics for adaptive optimization
     *
     * @param string $table Table name
     * @param int    $recordCount Number of records processed
     * @param float  $executionTime Execution time in seconds
     * @return void
     */
    public static function recordPerformance($table, $recordCount, $executionTime)
    {
        if (!isset(self::$performanceHistory[$table])) {
            self::$performanceHistory[$table] = [
                'samples' => [],
                'avg_time' => 0,
                'avg_size' => 0
            ];
        }

        // Add sample
        self::$performanceHistory[$table]['samples'][] = [
            'size' => $recordCount,
            'time' => $executionTime
        ];

        // Keep only last 10 samples
        if (count(self::$performanceHistory[$table]['samples']) > 10) {
            array_shift(self::$performanceHistory[$table]['samples']);
        }

        // Recalculate averages
        $totalTime = 0;
        $totalSize = 0;
        $count = count(self::$performanceHistory[$table]['samples']);

        foreach (self::$performanceHistory[$table]['samples'] as $sample) {
            $totalTime += $sample['time'];
            $totalSize += $sample['size'];
        }

        self::$performanceHistory[$table]['avg_time'] = $totalTime / $count;
        self::$performanceHistory[$table]['avg_size'] = $totalSize / $count;
    }

    /**
     * Optimize IN clause by removing duplicates and sorting
     *
     * @param array $values Values for IN clause
     * @return array Optimized values
     */
    public static function optimizeInClause(array $values)
    {
        if (!self::$config['use_in_clause_optimization']) {
            return $values;
        }

        // Remove duplicates
        $values = array_unique($values);

        // Sort for better index usage
        sort($values);

        return array_values($values);
    }

    /**
     * Determine if query should use batch processing
     *
     * @param int $recordCount Number of records
     * @return bool
     */
    public static function shouldUseBatching($recordCount)
    {
        return $recordCount >= self::$config['default_chunk_size'];
    }

    /**
     * Split array into optimized chunks
     *
     * @param array  $array Array to split
     * @param string $table Table name for adaptive sizing
     * @return array Array of chunks
     */
    public static function createOptimalChunks(array $array, $table = null)
    {
        $totalRecords = count($array);
        $chunkSize = self::getOptimalChunkSize($totalRecords, $table);

        return array_chunk($array, $chunkSize);
    }

    /**
     * Optimize eager loading query strategy
     *
     * @param array $primaryKeys Primary keys to load
     * @param array $options Query options
     * @return array Optimized query strategy
     */
    public static function optimizeQueryStrategy(array $primaryKeys, array $options = [])
    {
        $strategy = [
            'method' => 'batch', // batch, single, or join
            'chunk_size' => self::$config['default_chunk_size'],
            'use_cache' => true,
            'parallel' => false
        ];

        $count = count($primaryKeys);

        // For very small datasets, use single query
        if ($count <= 10) {
            $strategy['method'] = 'single';
            $strategy['chunk_size'] = $count;
            return $strategy;
        }

        // For medium datasets, use optimized batching
        if ($count <= 500) {
            $strategy['method'] = 'batch';
            $strategy['chunk_size'] = min($count, 250);
            return $strategy;
        }

        // For large datasets, use adaptive batching
        $strategy['method'] = 'batch';
        $strategy['chunk_size'] = self::getOptimalChunkSize(
            $count, 
            $options['table'] ?? null
        );

        return $strategy;
    }

    /**
     * Set configuration
     *
     * @param array $config Configuration options
     * @return void
     */
    public static function setConfig(array $config)
    {
        self::$config = array_merge(self::$config, $config);
    }

    /**
     * Get current configuration
     *
     * @return array
     */
    public static function getConfig()
    {
        return self::$config;
    }

    /**
     * Clear performance history
     *
     * @return void
     */
    public static function clearHistory()
    {
        self::$performanceHistory = [];
    }

    /**
     * Get performance statistics
     *
     * @return array
     */
    public static function getStats()
    {
        $stats = [
            'tables_tracked' => count(self::$performanceHistory),
            'config' => self::$config,
            'history' => []
        ];

        foreach (self::$performanceHistory as $table => $data) {
            $stats['history'][$table] = [
                'avg_time' => round($data['avg_time'], 4),
                'avg_size' => round($data['avg_size'], 0),
                'samples' => count($data['samples'])
            ];
        }

        return $stats;
    }

    /**
     * Enable adaptive chunk sizing
     *
     * @return void
     */
    public static function enableAdaptive()
    {
        self::$config['adaptive_enabled'] = true;
    }

    /**
     * Disable adaptive chunk sizing
     *
     * @return void
     */
    public static function disableAdaptive()
    {
        self::$config['adaptive_enabled'] = false;
    }
}
