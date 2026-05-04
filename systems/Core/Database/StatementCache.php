<?php

namespace Core\Database;

/**
 * Prepared Statement Cache
 *
 * Two-tier strategy:
 *
 * TIER 1 — In-process LRU ($cache):
 *   PDOStatement objects are OS/C++ resources and CANNOT be serialised or shared
 *   across processes.  Within a single PHP-FPM request this LRU cache avoids
 *   repeated pdo->prepare() calls for the same SQL (e.g. chunk loops, eager
 *   loads).  Up to $maxSize handles are kept; extras are LRU-evicted.
 *
 * TIER 2 — Cross-worker warmth registry (APCu):
 *   When a statement is prepared it is recorded in APCu as a "warm" SQL hash.
 *   New FPM workers can call prewarmFromRegistry() after opening a connection to
 *   pre-populate their local LRU with the SQL that other workers have been using
 *   most — eliminating cold-start prepare() latency for common queries.
 *
 *   APCu registry entry: key = 'stmt_warm:<connectionName>:<md5(sql)>'
 *                         value = ['sql' => $sql, 'hits' => int, 'last_seen' => int]
 *                         TTL = 3600 s (refreshed on every hit)
 *
 *   If APCu is unavailable the cache degrades silently to Tier 1 only.
 *
 * @category Database
 * @package  Core\Database
 * @author   Mohd Fahmy Izwan Zulkhafri <faizzul14@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0.html GNU Public License
 * @version  1.1.0
 */
class StatementCache
{
    /** @var array<string, \PDOStatement> In-process LRU statement cache. */
    protected static $cache = [];

    /** @var array<string, array> Per-key usage stats (hits, misses, last_used). */
    protected static $stats = [];

    /** @var int Maximum statements kept in the in-process LRU. */
    protected static $maxSize = 100;

    /** @var bool Master on/off switch. */
    protected static $enabled = true;

    /** @var string APCu key prefix for the cross-worker warmth registry. */
    protected static $apcu_prefix = 'stmt_warm:';

    /** @var int APCu TTL (seconds) for warmth registry entries. Refreshed on each hit. */
    protected static $warmth_ttl = 3600;

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Return a prepared PDOStatement for $sql, from cache or freshly prepared.
     *
     * On a cache miss the statement is prepared and inserted into the local LRU.
     * The SQL hash is also recorded in the APCu warmth registry so other workers
     * can pre-warm this statement before their first request hits.
     *
     * @param \PDO   $pdo            Open PDO connection.
     * @param string $sql            SQL query string.
     * @param string $connectionName Logical connection name (for key namespacing).
     * @return \PDOStatement
     */
    public static function get(\PDO $pdo, string $sql, string $connectionName = 'default'): \PDOStatement
    {
        if (!self::$enabled) {
            return $pdo->prepare($sql);
        }

        $key = self::generateKey($sql, $connectionName);

        if (isset(self::$cache[$key])) {
            self::recordHit($key);
            self::touchApcuEntry($key, $sql, $connectionName);
            return self::$cache[$key];
        }

        // Prepare new statement
        $stmt = $pdo->prepare($sql);

        // Evict if at capacity before inserting
        if (count(self::$cache) >= self::$maxSize) {
            self::evictLeastUsed();
        }

        self::$cache[$key] = $stmt;
        self::recordMiss($key);
        self::registerApcuEntry($key, $sql, $connectionName);

        return $stmt;
    }

    /**
     * Pre-warm the in-process LRU from the APCu warmth registry.
     *
     * Call this once after a new PDO connection is established so that the
     * worker's first requests benefit from pre-prepared statements for the SQL
     * strings other workers have been using most.
     *
     * Only statements not already in the local cache are prepared; the number
     * of statements pre-warmed is capped at half $maxSize to leave room for
     * request-specific queries.
     *
     * Does nothing if APCu is unavailable or the registry is empty.
     *
     * @param \PDO   $pdo            The newly opened connection to prepare against.
     * @param string $connectionName Logical connection name.
     * @return int   Number of statements pre-warmed.
     */
    public static function prewarmFromRegistry(\PDO $pdo, string $connectionName = 'default'): int
    {
        if (!self::$enabled || !self::apcuAvailable()) {
            return 0;
        }

        $entries = self::fetchApcuRegistry($connectionName);
        if (empty($entries)) {
            return 0;
        }

        // Sort descending by hit count so the hottest statements go in first
        usort($entries, static fn($a, $b) => $b['hits'] <=> $a['hits']);

        $cap = (int) floor(self::$maxSize / 2);
        $warmed = 0;

        foreach ($entries as $entry) {
            if ($warmed >= $cap) {
                break;
            }

            $sql = $entry['sql'] ?? '';
            if ($sql === '') {
                continue;
            }

            $key = self::generateKey($sql, $connectionName);
            if (isset(self::$cache[$key])) {
                continue; // already warm
            }

            try {
                $stmt = $pdo->prepare($sql);
                self::$cache[$key] = $stmt;
                self::recordMiss($key); // counts as a cold prepare
                $warmed++;
            } catch (\PDOException $e) {
                // Stale registry entry (schema changed etc.) — skip silently
            }
        }

        return $warmed;
    }

    // -------------------------------------------------------------------------
    // Cache management
    // -------------------------------------------------------------------------

    /**
     * Clear the entire in-process cache.
     * Does NOT clear APCu registry entries.
     *
     * @return void
     */
    public static function clear(): void
    {
        self::$cache = [];
        self::$stats = [];
    }

    /**
     * Clear all cached statements for a specific connection.
     *
     * @param string $connectionName
     * @return int Number of statements cleared.
     */
    public static function clearConnection(string $connectionName): int
    {
        $cleared = 0;
        $prefix  = $connectionName . ':';

        foreach (array_keys(self::$cache) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset(self::$cache[$key], self::$stats[$key]);
                $cleared++;
            }
        }

        return $cleared;
    }

    // -------------------------------------------------------------------------
    // Stats / configuration
    // -------------------------------------------------------------------------

    /**
     * Return combined in-process and cross-worker statistics.
     *
     * @return array
     */
    public static function getStats(): array
    {
        $totalHits   = 0;
        $totalMisses = 0;

        foreach (self::$stats as $stat) {
            $totalHits   += $stat['hits'];
            $totalMisses += $stat['misses'];
        }

        $total = $totalHits + $totalMisses;

        return [
            'cached_statements' => count(self::$cache),
            'total_hits'        => $totalHits,
            'total_misses'      => $totalMisses,
            'hit_rate'          => $total > 0 ? round(($totalHits / $total) * 100, 2) : 0,
            'max_size'          => self::$maxSize,
            'enabled'           => self::$enabled,
            'apcu_available'    => self::apcuAvailable(),
        ];
    }

    /** @param int $size Maximum LRU size. */
    public static function setMaxSize(int $size): void
    {
        self::$maxSize = max(1, $size);
    }

    public static function enable(): void  { self::$enabled = true; }
    public static function disable(): void { self::$enabled = false; }
    public static function isEnabled(): bool { return self::$enabled; }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Generate a deterministic per-connection cache key for an SQL string.
     *
     * @param string $sql
     * @param string $connectionName
     * @return string
     */
    protected static function generateKey(string $sql, string $connectionName): string
    {
        return $connectionName . ':' . md5($sql);
    }

    protected static function recordHit(string $key): void
    {
        self::$stats[$key] ??= ['hits' => 0, 'misses' => 0, 'last_used' => 0.0];
        self::$stats[$key]['hits']++;
        self::$stats[$key]['last_used'] = microtime(true);
    }

    protected static function recordMiss(string $key): void
    {
        self::$stats[$key] ??= ['hits' => 0, 'misses' => 0, 'last_used' => 0.0];
        self::$stats[$key]['misses']++;
        self::$stats[$key]['last_used'] = microtime(true);
    }

    /**
     * Evict the statement with the lowest LRU score.
     * Score = hits − (seconds since last use / 10)
     *
     * @return void
     */
    protected static function evictLeastUsed(): void
    {
        if (empty(self::$cache)) {
            return;
        }

        $worstKey   = null;
        $worstScore = PHP_INT_MAX;

        foreach (self::$stats as $key => $stat) {
            $age   = microtime(true) - $stat['last_used'];
            $score = $stat['hits'] - ($age / 10);
            if ($score < $worstScore) {
                $worstScore = $score;
                $worstKey   = $key;
            }
        }

        if ($worstKey !== null) {
            unset(self::$cache[$worstKey], self::$stats[$worstKey]);
        }
    }

    // -------------------------------------------------------------------------
    // APCu warmth registry
    // -------------------------------------------------------------------------

    /**
     * Check whether APCu is available for the current SAPI.
     * Result is memoised per-process.
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

    protected static function apcuFetch(string $key): mixed
    {
        return call_user_func('apcu_fetch', $key);
    }

    protected static function apcuStore(string $key, mixed $value, int $ttl): bool
    {
        return (bool) call_user_func('apcu_store', $key, $value, $ttl);
    }

    protected static function apcuCacheInfo(): array
    {
        if (!function_exists('apcu_cache_info')) {
            return [];
        }

        $info = call_user_func('apcu_cache_info', false);
        return is_array($info) ? $info : [];
    }

    /**
     * Register a newly prepared statement in the APCu warmth registry.
     *
     * @param string $key            Cache key.
     * @param string $sql            Original SQL string.
     * @param string $connectionName Logical connection name.
     * @return void
     */
    protected static function registerApcuEntry(string $key, string $sql, string $connectionName): void
    {
        if (!self::apcuAvailable()) {
            return;
        }

        $apcuKey = self::$apcu_prefix . $connectionName . ':' . md5($sql);
        self::apcuStore($apcuKey, [
            'sql'       => $sql,
            'hits'      => 1,
            'last_seen' => time(),
        ], self::$warmth_ttl);
    }

    /**
     * Increment the hit counter for an existing APCu warmth entry.
     * Refreshes the TTL so active statements don't expire.
     *
     * @param string $key            Cache key.
     * @param string $sql            Original SQL string.
     * @param string $connectionName Logical connection name.
     * @return void
     */
    protected static function touchApcuEntry(string $key, string $sql, string $connectionName): void
    {
        if (!self::apcuAvailable()) {
            return;
        }

        $apcuKey = self::$apcu_prefix . $connectionName . ':' . md5($sql);
        $entry   = self::apcuFetch($apcuKey);

        if (is_array($entry)) {
            $entry['hits']++;
            $entry['last_seen'] = time();
            self::apcuStore($apcuKey, $entry, self::$warmth_ttl);
        } else {
            // Entry expired — re-register
            self::registerApcuEntry($key, $sql, $connectionName);
        }
    }

    /**
     * Fetch all APCu warmth entries for a given connection.
     *
     * Uses APCu's iterator if available (preferred, avoids full cache scan),
     * otherwise falls back to a prefix scan via apcu_cache_info.
     *
     * @param string $connectionName
     * @return array  Array of ['sql' => ..., 'hits' => ..., 'last_seen' => ...].
     */
    protected static function fetchApcuRegistry(string $connectionName): array
    {
        if (!self::apcuAvailable()) {
            return [];
        }

        $prefix  = self::$apcu_prefix . $connectionName . ':';
        $entries = [];

        // APCuIterator is the most efficient way to scan by key prefix
        $iteratorClass = '\APCuIterator';
        if (class_exists($iteratorClass)) {
            $iteratorFlag = defined('APC_ITER_VALUE') ? constant('APC_ITER_VALUE') : 1;
            $iterator = new $iteratorClass('/^' . preg_quote($prefix, '/') . '/', $iteratorFlag);
            foreach ($iterator as $item) {
                if (is_array($item['value']) && isset($item['value']['sql'])) {
                    $entries[] = $item['value'];
                }
            }
            return $entries;
        }

        // Fallback: apcu_cache_info (loads entire cache — use only when APCuIterator absent)
        $info = self::apcuCacheInfo();
        foreach ($info['cache_list'] ?? [] as $item) {
            $infoKey = $item['info'] ?? '';
            if (str_starts_with($infoKey, $prefix)) {
                $value = self::apcuFetch($infoKey);
                if (is_array($value) && isset($value['sql'])) {
                    $entries[] = $value;
                }
            }
        }

        return $entries;
    }
}
