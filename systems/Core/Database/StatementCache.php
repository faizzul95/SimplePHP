<?php

namespace Core\Database;

/**
 * Prepared Statement Cache
 * 
 * Caches prepared PDO statements to reduce query parsing overhead
 * and improve performance for repeated queries.
 * 
 * @category Database
 * @package  Core\Database
 * @author   Mohd Fahmy Izwan Zulkhafri <faizzul14@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0.html GNU Public License
 * @version  1.0.0
 */
class StatementCache
{
    /**
     * @var array Cache of prepared statements
     */
    protected static $cache = [];

    /**
     * @var array Statement usage statistics
     */
    protected static $stats = [];

    /**
     * @var int Maximum number of cached statements
     */
    protected static $maxSize = 100;

    /**
     * @var bool Enable/disable caching
     */
    protected static $enabled = true;

    /**
     * Get or create a prepared statement
     *
     * @param \PDO   $pdo PDO instance
     * @param string $sql SQL query
     * @param string $connectionName Connection identifier
     * @return \PDOStatement
     */
    public static function get(\PDO $pdo, $sql, $connectionName = 'default')
    {
        if (!self::$enabled) {
            return $pdo->prepare($sql);
        }

        $key = self::generateKey($sql, $connectionName);

        // Check if statement exists in cache
        if (isset(self::$cache[$key])) {
            self::recordHit($key);
            return self::$cache[$key];
        }

        // Prepare new statement
        $stmt = $pdo->prepare($sql);

        // Cache it if we have space
        if (count(self::$cache) < self::$maxSize) {
            self::$cache[$key] = $stmt;
            self::recordMiss($key);
        } else {
            // Remove least used statement
            self::evictLeastUsed();
            self::$cache[$key] = $stmt;
            self::recordMiss($key);
        }

        return $stmt;
    }

    /**
     * Generate cache key for a statement
     *
     * @param string $sql SQL query
     * @param string $connectionName Connection name
     * @return string
     */
    protected static function generateKey($sql, $connectionName)
    {
        return md5($connectionName . ':' . $sql);
    }

    /**
     * Record cache hit
     *
     * @param string $key Cache key
     * @return void
     */
    protected static function recordHit($key)
    {
        if (!isset(self::$stats[$key])) {
            self::$stats[$key] = ['hits' => 0, 'misses' => 0, 'last_used' => 0];
        }
        self::$stats[$key]['hits']++;
        self::$stats[$key]['last_used'] = microtime(true);
    }

    /**
     * Record cache miss
     *
     * @param string $key Cache key
     * @return void
     */
    protected static function recordMiss($key)
    {
        if (!isset(self::$stats[$key])) {
            self::$stats[$key] = ['hits' => 0, 'misses' => 0, 'last_used' => 0];
        }
        self::$stats[$key]['misses']++;
        self::$stats[$key]['last_used'] = microtime(true);
    }

    /**
     * Evict least used statement from cache
     *
     * @return void
     */
    protected static function evictLeastUsed()
    {
        if (empty(self::$cache)) {
            return;
        }

        $leastUsedKey = null;
        $minScore = PHP_INT_MAX;

        foreach (self::$stats as $key => $stat) {
            // Calculate score: hits - (time since last use in seconds / 10)
            $timeSinceUse = microtime(true) - $stat['last_used'];
            $score = $stat['hits'] - ($timeSinceUse / 10);

            if ($score < $minScore) {
                $minScore = $score;
                $leastUsedKey = $key;
            }
        }

        if ($leastUsedKey !== null) {
            unset(self::$cache[$leastUsedKey]);
            unset(self::$stats[$leastUsedKey]);
        }
    }

    /**
     * Clear all cached statements
     *
     * @return void
     */
    public static function clear()
    {
        self::$cache = [];
        self::$stats = [];
    }

    /**
     * Clear statements for a specific connection
     *
     * @param string $connectionName Connection name
     * @return int Number of statements cleared
     */
    public static function clearConnection($connectionName)
    {
        $cleared = 0;
        foreach (array_keys(self::$cache) as $key) {
            if (strpos($key, md5($connectionName)) === 0) {
                unset(self::$cache[$key]);
                unset(self::$stats[$key]);
                $cleared++;
            }
        }
        return $cleared;
    }

    /**
     * Get cache statistics
     *
     * @return array
     */
    public static function getStats()
    {
        $totalHits = 0;
        $totalMisses = 0;

        foreach (self::$stats as $stat) {
            $totalHits += $stat['hits'];
            $totalMisses += $stat['misses'];
        }

        $totalRequests = $totalHits + $totalMisses;
        $hitRate = $totalRequests > 0 ? round(($totalHits / $totalRequests) * 100, 2) : 0;

        return [
            'cached_statements' => count(self::$cache),
            'total_hits' => $totalHits,
            'total_misses' => $totalMisses,
            'hit_rate' => $hitRate,
            'max_size' => self::$maxSize,
            'enabled' => self::$enabled
        ];
    }

    /**
     * Set maximum cache size
     *
     * @param int $size Maximum number of statements to cache
     * @return void
     */
    public static function setMaxSize($size)
    {
        self::$maxSize = max(1, (int)$size);
    }

    /**
     * Enable statement caching
     *
     * @return void
     */
    public static function enable()
    {
        self::$enabled = true;
    }

    /**
     * Disable statement caching
     *
     * @return void
     */
    public static function disable()
    {
        self::$enabled = false;
    }

    /**
     * Check if caching is enabled
     *
     * @return bool
     */
    public static function isEnabled()
    {
        return self::$enabled;
    }
}
