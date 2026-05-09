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

    /** @var string APCu key prefix to namespace query cache entries. */
    protected static string $apcuPrefix = 'qc:';

    /**
     * Initialize cache directory.
     * APCu is used automatically when available (shared hosting friendly — no Redis needed).
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
            // 0750: cache entries may contain query results with PII.
            mkdir(self::$cacheDir, 0750, true);
        }
    }

    /**
     * Whether APCu is available and enabled in the current SAPI.
     * Memoised after first call.
     *
     * @return bool
     */
    protected static function apcuAvailable(): bool
    {
        static $available = null;
        if ($available === null) {
            $available = function_exists('apcu_store')
                && function_exists('apcu_fetch')
                && function_exists('apcu_enabled')
                && (bool) call_user_func('apcu_enabled');
        }
        return $available;
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

        $now = time();

        // Tier 1: in-process memory cache (fastest — no I/O)
        if (isset(self::$memoryCache[$key])) {
            $cached = self::$memoryCache[$key];
            if ($cached['expires'] > $now) {
                self::$stats['hits']++;
                return $cached['data'];
            }
            unset(self::$memoryCache[$key]);
        }

        // Tier 2: APCu (cross-worker shared memory — shared hosting friendly, no Redis needed)
        if (self::apcuAvailable()) {
            $apcuKey = self::$apcuPrefix . $key;
            $success  = false;
            $cached   = call_user_func('apcu_fetch', $apcuKey, $success);
            if ($success && is_array($cached) && ($cached['expires'] ?? 0) > $now) {
                self::addToMemoryCache($key, $cached['data'], $cached['expires']);
                self::$stats['hits']++;
                return $cached['data'];
            }
        }

        // Tier 3: file cache
        $filePath = self::getCacheFilePath($key);
        if (!file_exists($filePath)) {
            self::$stats['misses']++;
            return null;
        }

        $cached = self::safeUnserialize(file_get_contents($filePath));

        // Validate deserialized data
        if ($cached === null) {
            @unlink($filePath);
            self::$stats['misses']++;
            return null;
        }
        if ($cached['expires'] < $now) {
            @unlink($filePath);
            self::$stats['misses']++;
            return null;
        }

        // Promote to faster tiers
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

        // Tier 1: write to in-process memory cache
        self::addToMemoryCache($key, $data, $expires);

        // Tier 2: write to APCu when available (avoids file I/O for hot queries)
        if (self::apcuAvailable()) {
            call_user_func('apcu_store', self::$apcuPrefix . $key, $cached, $ttl);
        }

        // Tier 3: write to file cache with atomic rename to prevent partial reads
        $filePath = self::getCacheFilePath($key);
        $tmpPath  = $filePath . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $result   = file_put_contents($tmpPath, serialize($cached), LOCK_EX);
        if ($result !== false) {
            rename($tmpPath, $filePath);
        } else {
            @unlink($tmpPath);
        }

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

        // Remove from APCu when available
        if (self::apcuAvailable()) {
            call_user_func('apcu_delete', self::$apcuPrefix . $key);
        }

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
     * Retrieve the per-table version counter from APCu.
     * Incremented by invalidateTable() on every write (INSERT/UPDATE/DELETE).
     * When APCu is unavailable the version is always 1 (no cross-request busting).
     *
     * @param string $table Lowercase table name
     * @return int Current version (≥ 1)
     */
    protected static function tableVersion(string $table): int
    {
        if (!self::apcuAvailable()) {
            return 1;
        }
        $v = call_user_func('apcu_fetch', self::$apcuPrefix . 'tv:' . $table);
        return ($v !== false && is_int($v)) ? $v : 1;
    }

    /**
     * Invalidate all cached queries that touch the given table(s).
     *
     * Strategy: bump a per-table version counter in APCu (shared across all
     * workers).  Any subsequent generateKey() call that includes this table
     * will produce a different hash — old cache entries become unreachable and
     * expire naturally, while the hot path stays O(1).
     *
     * When APCu is unavailable the entire in-process memory cache is flushed
     * as a best-effort fallback (file-tier entries expire by TTL).
     *
     * @param string|string[] $tables Table name(s) written to
     * @return void
     */
    public static function invalidateTable(string|array $tables): void
    {
        self::initIfNeeded();

        if (!self::apcuAvailable()) {
            // Best-effort: drop in-process cache for this request
            self::$memoryCache = [];
            self::$stats['invalidations']++;
            return;
        }

        foreach ((array) $tables as $table) {
            $table = strtolower(trim($table));
            if ($table === '') {
                continue;
            }

            $apcuKey = self::$apcuPrefix . 'tv:' . $table;
            // Atomic increment — apcu_inc() returns false when key does not yet exist
            $result = call_user_func('apcu_inc', $apcuKey);
            if ($result === false) {
                // Key absent: seed version at 2 (1 is the implicit "never bumped" baseline)
                call_user_func('apcu_store', $apcuKey, 2, 86400);
            }

            self::$stats['invalidations']++;
        }

        // Drop in-process memory cache — we cannot cheaply identify which entries
        // belong to the affected table(s) without re-hashing every key.
        self::$memoryCache = [];
    }

    /**
     * Generate cache key from query and parameters.
     *
     * When $tables is supplied the key incorporates per-table version counters
     * (stored in APCu by invalidateTable()).  After a write to any of those
     * tables the counter increments, the key changes, and stale results are
     * never served — automatic write-through invalidation with O(1) cost.
     *
     * @param string   $query      SQL query
     * @param array    $binds      Query parameters
     * @param string   $connection Connection name
     * @param string[] $tables     Table names touched by this query (for version busting)
     * @return string Cache key (MD5 hex)
     */
    public static function generateKey($query, array $binds = [], $connection = 'default', array $tables = []): string
    {
        $tableVersions = '';
        foreach ($tables as $table) {
            $table = strtolower(trim($table));
            if ($table !== '') {
                $tableVersions .= $table . ':' . self::tableVersion($table) . '|';
            }
        }
        return md5($connection . $query . serialize($binds) . $tableVersions);
    }

    /**
     * Get cache file path with path traversal protection
     *
     * @param string $key Cache key
     * @return string File path
     * @throws \InvalidArgumentException If the key contains invalid characters
     */
    protected static function getCacheFilePath($key)
    {
        // Keys are derived from md5() in generateKey(); restrict the accepted
        // set to the characters md5 can actually produce so any malformed or
        // adversarial key is rejected early. Dots are disallowed to eliminate
        // any residual path-traversal concern on quirky filesystems.
        if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $key)) {
            throw new \InvalidArgumentException('Invalid cache key format: contains disallowed characters');
        }

        return self::$cacheDir . DIRECTORY_SEPARATOR . $key . '.cache';
    }

    /**
     * Safely unserialize cache data with validation
     *
     * @param string $data Raw serialized data
     * @return array|null The unserialized data or null if invalid
     */
    protected static function safeUnserialize($data)
    {
        if (empty($data)) {
            return null;
        }

        // Only allow arrays and scalar types during unserialization (no objects)
        $result = @unserialize($data, ['allowed_classes' => false]);

        // Validate structure
        if (!is_array($result) || !isset($result['expires']) || !isset($result['data'])) {
            return null;
        }

        return $result;
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
        // If this key already exists, refresh it in place (prevents duplicate
        // entries with different timestamps from bloating the cache).
        if (isset(self::$memoryCache[$key])) {
            self::$memoryCache[$key] = [
                'data' => $data,
                'expires' => $expires,
                'created' => time(),
            ];
            return;
        }

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
                    // Use filemtime for faster expiry check instead of reading+deserializing every file
                    $mtime = filemtime($file);
                    // Files older than max possible TTL are definitely expired
                    if ($mtime !== false && ($now - $mtime) > self::$defaultTTL * 2) {
                        @unlink($file);
                        $cleaned++;
                    } else {
                        $cached = self::safeUnserialize(file_get_contents($file));
                        if ($cached === null || $cached['expires'] < $now) {
                            @unlink($file);
                            $cleaned++;
                        }
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
