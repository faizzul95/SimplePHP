<?php

namespace Core\Database;

/**
 * Query Result Cache
 * 
 * Advanced caching layer for database query results with TTL,
 * tag-based invalidation, and memory-efficient storage.
 * 
 * @category Database
 * @package  Core\Database
 * @author   Mohd Fahmy Izwan Zulkhafri <faizzul14@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0.html GNU Public License
 * @version  1.0.0
 */
class QueryCache
{
    /**
     * @var string Cache directory
     */
    protected static $cacheDir = null;

    /**
     * @var int Default TTL in seconds
     */
    protected static $defaultTTL = 3600;

    /**
     * @var bool Enable/disable caching
     */
    protected static $enabled = true;

    /**
     * @var array In-memory cache for frequently accessed queries
     */
    protected static $memoryCache = [];

    /**
     * @var int Maximum items in memory cache
     */
    protected static $memoryCacheSize = 50;

    /**
     * @var array Cache statistics
     */
    protected static $stats = [
        'hits' => 0,
        'misses' => 0,
        'sets' => 0,
        'invalidations' => 0
    ];

    /**
     * @var array Tag to cache key mapping
     */
    protected static $tags = [];

    /**
     * Initialize cache directory
     *
     * @param string $dir Cache directory path
     * @return void
     */
    public static function init($dir = null)
    {
        if ($dir === null) {
            $dir = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'query';
        }

        self::$cacheDir = rtrim($dir, DIRECTORY_SEPARATOR);

        if (!is_dir(self::$cacheDir)) {
            mkdir(self::$cacheDir, 0755, true);
        }
    }

    /**
     * Get cached query result
     *
     * @param string $key Cache key
     * @return mixed|null Cached result or null if not found
     */
    public static function get($key)
    {
        if (!self::$enabled) {
            return null;
        }

        self::initIfNeeded();

        // Check memory cache first
        if (isset(self::$memoryCache[$key])) {
            $cached = self::$memoryCache[$key];
            if ($cached['expires'] > time()) {
                self::$stats['hits']++;
                return $cached['data'];
            } else {
                unset(self::$memoryCache[$key]);
            }
        }

        // Check file cache
        $filePath = self::getCacheFilePath($key);
        if (!file_exists($filePath)) {
            self::$stats['misses']++;
            return null;
        }

        $cached = unserialize(file_get_contents($filePath));

        // Check expiration
        if ($cached['expires'] < time()) {
            unlink($filePath);
            self::$stats['misses']++;
            return null;
        }

        // Store in memory cache for quick access
        self::addToMemoryCache($key, $cached['data'], $cached['expires']);

        self::$stats['hits']++;
        return $cached['data'];
    }

    /**
     * Store query result in cache
     *
     * @param string $key Cache key
     * @param mixed  $data Data to cache
     * @param int    $ttl Time to live in seconds
     * @param array  $tags Tags for invalidation
     * @return bool Success status
     */
    public static function set($key, $data, $ttl = null, array $tags = [])
    {
        if (!self::$enabled) {
            return false;
        }

        self::initIfNeeded();

        $ttl = $ttl ?? self::$defaultTTL;
        $expires = time() + $ttl;

        $cached = [
            'data' => $data,
            'expires' => $expires,
            'tags' => $tags,
            'created' => time()
        ];

        // Save to memory cache
        self::addToMemoryCache($key, $data, $expires);

        // Save to file cache
        $filePath = self::getCacheFilePath($key);
        $result = file_put_contents($filePath, serialize($cached), LOCK_EX);

        // Register tags
        foreach ($tags as $tag) {
            if (!isset(self::$tags[$tag])) {
                self::$tags[$tag] = [];
            }
            self::$tags[$tag][] = $key;
        }

        self::$stats['sets']++;
        return $result !== false;
    }

    /**
     * Delete cached item
     *
     * @param string $key Cache key
     * @return bool Success status
     */
    public static function forget($key)
    {
        self::initIfNeeded();

        // Remove from memory cache
        unset(self::$memoryCache[$key]);

        // Remove from file cache
        $filePath = self::getCacheFilePath($key);
        if (file_exists($filePath)) {
            return unlink($filePath);
        }

        return false;
    }

    /**
     * Invalidate cache by tags
     *
     * @param array $tags Tags to invalidate
     * @return int Number of items invalidated
     */
    public static function invalidateTags(array $tags)
    {
        self::initIfNeeded();

        $invalidated = 0;
        foreach ($tags as $tag) {
            if (isset(self::$tags[$tag])) {
                foreach (self::$tags[$tag] as $key) {
                    if (self::forget($key)) {
                        $invalidated++;
                    }
                }
                unset(self::$tags[$tag]);
            }
        }

        self::$stats['invalidations'] += $invalidated;
        return $invalidated;
    }

    /**
     * Clear all cache
     *
     * @return bool Success status
     */
    public static function flush()
    {
        self::initIfNeeded();

        self::$memoryCache = [];
        self::$tags = [];

        if (!is_dir(self::$cacheDir)) {
            return true;
        }

        $files = glob(self::$cacheDir . '/*.cache');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        return true;
    }

    /**
     * Generate cache key from query and parameters
     *
     * @param string $query SQL query
     * @param array  $binds Query parameters
     * @param string $connection Connection name
     * @return string Cache key
     */
    public static function generateKey($query, array $binds = [], $connection = 'default')
    {
        $data = [
            'connection' => $connection,
            'query' => $query,
            'binds' => $binds
        ];

        return md5(json_encode($data));
    }

    /**
     * Get cache file path
     *
     * @param string $key Cache key
     * @return string File path
     */
    protected static function getCacheFilePath($key)
    {
        return self::$cacheDir . DIRECTORY_SEPARATOR . $key . '.cache';
    }

    /**
     * Add item to memory cache
     *
     * @param string $key Cache key
     * @param mixed  $data Data to cache
     * @param int    $expires Expiration timestamp
     * @return void
     */
    protected static function addToMemoryCache($key, $data, $expires)
    {
        // If memory cache is full, remove oldest item
        if (count(self::$memoryCache) >= self::$memoryCacheSize) {
            $oldest = null;
            $oldestTime = PHP_INT_MAX;

            foreach (self::$memoryCache as $k => $item) {
                if ($item['created'] < $oldestTime) {
                    $oldestTime = $item['created'];
                    $oldest = $k;
                }
            }

            if ($oldest !== null) {
                unset(self::$memoryCache[$oldest]);
            }
        }

        self::$memoryCache[$key] = [
            'data' => $data,
            'expires' => $expires,
            'created' => time()
        ];
    }

    /**
     * Initialize cache if needed
     *
     * @return void
     */
    protected static function initIfNeeded()
    {
        if (self::$cacheDir === null) {
            self::init();
        }
    }

    /**
     * Clean expired cache entries
     *
     * @return int Number of entries cleaned
     */
    public static function cleanup()
    {
        self::initIfNeeded();

        $cleaned = 0;
        $now = time();

        // Clean memory cache
        foreach (self::$memoryCache as $key => $item) {
            if ($item['expires'] < $now) {
                unset(self::$memoryCache[$key]);
                $cleaned++;
            }
        }

        // Clean file cache
        if (is_dir(self::$cacheDir)) {
            $files = glob(self::$cacheDir . '/*.cache');
            foreach ($files as $file) {
                if (is_file($file)) {
                    $cached = unserialize(file_get_contents($file));
                    if ($cached['expires'] < $now) {
                        unlink($file);
                        $cleaned++;
                    }
                }
            }
        }

        return $cleaned;
    }

    /**
     * Get cache statistics
     *
     * @return array
     */
    public static function getStats()
    {
        $totalRequests = self::$stats['hits'] + self::$stats['misses'];
        $hitRate = $totalRequests > 0 
            ? round((self::$stats['hits'] / $totalRequests) * 100, 2) 
            : 0;

        return array_merge(self::$stats, [
            'hit_rate' => $hitRate,
            'memory_cache_size' => count(self::$memoryCache),
            'file_cache_size' => self::getFileCacheSize(),
            'enabled' => self::$enabled
        ]);
    }

    /**
     * Get number of files in cache
     *
     * @return int
     */
    protected static function getFileCacheSize()
    {
        self::initIfNeeded();

        if (!is_dir(self::$cacheDir)) {
            return 0;
        }

        $files = glob(self::$cacheDir . '/*.cache');
        return count($files);
    }

    /**
     * Set default TTL
     *
     * @param int $seconds TTL in seconds
     * @return void
     */
    public static function setDefaultTTL($seconds)
    {
        self::$defaultTTL = max(1, (int)$seconds);
    }

    /**
     * Enable caching
     *
     * @return void
     */
    public static function enable()
    {
        self::$enabled = true;
    }

    /**
     * Disable caching
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

    /**
     * Set memory cache size
     *
     * @param int $size Maximum items in memory cache
     * @return void
     */
    public static function setMemoryCacheSize($size)
    {
        self::$memoryCacheSize = max(1, (int)$size);
    }
}
