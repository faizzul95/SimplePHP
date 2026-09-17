<?php

namespace Core\Cache;

/**
 * CacheManager — Unified cache façade.
 *
 * Usage:
 *   $cache = new CacheManager(config('cache'));
 *   $cache->put('key', $value, 300);      // 5 minutes
 *   $cache->get('key');
 *   $cache->remember('key', 600, fn() => expensiveQuery());
 *   $cache->forget('key');
 *
 * Or via the global helper:
 *   cache()->put('key', $value, 300);
 *   cache('key');                          // shortcut for get
 *   cache(['key' => $value], 300);         // shortcut for put
 */
class CacheManager
{
    private const LOCK_SUFFIX = ':__lock';
    private const POLL_INTERVAL_MICROSECONDS = 25000;

    private array $config;
    private string $prefix;

    /** @var array<string, FileStore|ArrayStore|ApcuStore|RedisDriver> Resolved store instances */
    private array $stores = [];

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->prefix = $config['prefix'] ?? 'MythPHP_';
    }

    // ─── Store Resolution ────────────────────────────────────

    /**
     * Get a cache store instance by name.
     */
    public function store(?string $name = null): FileStore|ArrayStore|ApcuStore|RedisDriver
    {
        $name = $name ?? $this->config['default'] ?? 'file';

        if (!isset($this->stores[$name])) {
            $this->stores[$name] = $this->resolve($name);
        }

        return $this->stores[$name];
    }

    /**
     * Resolve a store by its configuration.
     */
    private function resolve(string $name): FileStore|ArrayStore|ApcuStore|RedisDriver
    {
        $storeConfig = $this->config['stores'][$name] ?? null;

        if ($storeConfig === null) {
            throw new \InvalidArgumentException("Cache store [{$name}] is not defined.");
        }

        $driver = $storeConfig['driver'] ?? 'file';

        // APCu tier: fast in-process + cross-worker shared memory.
        // Degrades gracefully when APCu is unavailable (shared hosting safe).
        if ($driver === 'apcu') {
            if (function_exists('apcu_store') && function_exists('apcu_enabled') && (bool) call_user_func('apcu_enabled')) {
                return new ApcuStore($storeConfig['prefix'] ?? 'MythPHP_cache:');
            }
            // APCu requested but not available — fall through to file driver.
            $driver = 'file';
        }

        $path = $this->cachePath((string) ($storeConfig['path'] ?? 'storage/cache/app'));

        return match ($driver) {
            'file'  => new FileStore($path),
            'array' => new ArrayStore(),
            'redis' => extension_loaded('redis') ? new RedisDriver($storeConfig) : new FileStore($path),
            default => throw new \InvalidArgumentException("Cache driver [{$driver}] is not supported."),
        };
    }

    /**
     * Resolve a configured cache directory.
     *
     * An absolute path is a deliberate choice — a tmpfs mount, a shared volume,
     * a disk that is not the deployment. Prefixing ROOT_DIR onto it built a path
     * that could never exist, and because every write is @-suppressed the store
     * then failed silently: every get() a miss, every put() a no-op, and a cache
     * that looked configured while caching nothing.
     */
    private function cachePath(string $path): string
    {
        if ($this->isAbsolutePath($path)) {
            return $path;
        }

        return (defined('ROOT_DIR') ? ROOT_DIR : dirname(__DIR__, 2) . DIRECTORY_SEPARATOR) . $path;
    }

    private function isAbsolutePath(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        // POSIX root, UNC share, or a Windows drive letter.
        return $path[0] === '/'
            || $path[0] === '\\'
            || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1;
    }

    // ─── Proxy Methods (default store) ───────────────────────

    /**
     * Retrieve an item from the cache.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $started = microtime(true);

        /*
        | A sentinel rather than $default, so a hit on a stored null is not
        | mistaken for a miss. Both shipped stores already distinguish the two,
        | so this changes nothing about what is returned — it only makes the
        | difference visible to the telemetry line below.
        */
        $sentinel = self::$missSentinel ??= new \stdClass();
        $value = $this->store()->get($this->prefix . $key, $sentinel);
        $hit = $value !== $sentinel;

        $this->recordCacheEvent($hit ? 'hit' : 'miss', $key, $started);

        return $hit ? $value : $default;
    }

    /**
     * Store an item in the cache.
     *
     * @param int    $seconds  TTL in seconds (0 = forever)
     */
    public function put(string $key, mixed $value, int $seconds = 0): bool
    {
        $started = microtime(true);
        $stored = $this->store()->put($this->prefix . $key, $value, $seconds);

        $this->recordCacheEvent($stored ? 'put' : 'put-failed', $key, $started, ['ttl' => $seconds]);

        return $stored;
    }

    /**
     * Store an item forever.
     */
    public function forever(string $key, mixed $value): bool
    {
        return $this->store()->forever($this->prefix . $key, $value);
    }

    /**
     * Get an item from the cache, or execute the given Closure and store
     * the result.
     *
     * Two things this had to get right and did not:
     *
     * A cached null was indistinguishable from a miss, so remember() around a
     * lookup that legitimately returns null re-ran the callback on every single
     * request — the one call it was added to avoid. A sentinel separates the two.
     *
     * And the callback ran *before* the atomic add(), so when a hot key expired
     * every concurrent request recomputed it and only the write was deduplicated.
     * That is a cache stampede, and on an expensive query it is how an expiring
     * key takes the database down. One caller now takes a short lock and
     * computes; the others wait briefly for the result instead of repeating the
     * work, and fall back to computing if the holder is slow or dies — a delay
     * is acceptable, a hang is not.
     */
    public function remember(string $key, int $seconds, \Closure $callback): mixed
    {
        $sentinel = new \stdClass();

        $cached = $this->get($key, $sentinel);
        if ($cached !== $sentinel) {
            return $cached;
        }

        if (!$this->stampedeProtectionEnabled()) {
            $value = $callback();
            $this->put($key, $value, $seconds);

            return $value;
        }

        $lockKey = $key . self::LOCK_SUFFIX;

        if ($this->add($lockKey, 1, $this->lockSeconds())) {
            try {
                $value = $callback();
                $this->put($key, $value, $seconds);

                return $value;
            } finally {
                $this->forget($lockKey);
            }
        }

        $published = $this->awaitPublishedValue($key, $sentinel);
        if ($published !== $sentinel) {
            return $published;
        }

        // The holder is slower than we are willing to wait, or it died before
        // publishing. Recomputing duplicates work; not answering fails a request.
        $value = $callback();
        $this->put($key, $value, $seconds);

        return $value;
    }

    /**
     * Poll for the value the lock holder is computing.
     *
     * Bounded on purpose: every millisecond here is a request-handling worker
     * doing nothing, so waiting longer than the work itself would take is a
     * worse failure than the stampede.
     */
    private function awaitPublishedValue(string $key, object $sentinel): mixed
    {
        $waitMicroseconds = $this->waitMilliseconds() * 1000;
        $intervalMicroseconds = self::POLL_INTERVAL_MICROSECONDS;
        $waited = 0;

        while ($waited < $waitMicroseconds) {
            usleep($intervalMicroseconds);
            $waited += $intervalMicroseconds;

            $value = $this->get($key, $sentinel);
            if ($value !== $sentinel) {
                return $value;
            }
        }

        return $sentinel;
    }

    private function stampedeProtectionEnabled(): bool
    {
        return ($this->config['stampede']['enabled'] ?? true) === true;
    }

    /** How long a computation may hold the lock before another caller may take over. */
    private function lockSeconds(): int
    {
        return max(1, (int) ($this->config['stampede']['lock_seconds'] ?? 10));
    }

    /** How long a waiting caller blocks before giving up and computing itself. */
    private function waitMilliseconds(): int
    {
        return max(0, (int) ($this->config['stampede']['wait_ms'] ?? 250));
    }

    /**
     * Get an item from the cache, or execute the given Closure and
     * store the result forever.
     */
    public function rememberForever(string $key, \Closure $callback): mixed
    {
        return $this->remember($key, 0, $callback);
    }

    /**
     * Retrieve and then delete an item.
     */
    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->forget($key);
        return $value;
    }

    /**
     * Check if an item exists.
     */
    public function has(string $key): bool
    {
        return $this->store()->has($this->prefix . $key);
    }

    /**
     * Check if an item does NOT exist.
     */
    public function missing(string $key): bool
    {
        return !$this->has($key);
    }

    /**
     * Increment a numeric value.
     */
    /**
     * @param int|null $seconds TTL applied only when this call creates the key.
     *                          Pass it for anything windowed — a counter created
     *                          without one never expires on any driver.
     */
    public function increment(string $key, int $amount = 1, ?int $seconds = null): int
    {
        return $this->store()->increment($this->prefix . $key, $amount, $seconds);
    }

    /**
     * Decrement a numeric value.
     */
    public function decrement(string $key, int $amount = 1): int
    {
        return $this->store()->decrement($this->prefix . $key, $amount);
    }

    /**
     * Remove an item from the cache.
     */
    public function forget(string $key): bool
    {
        $started = microtime(true);
        $forgotten = $this->store()->forget($this->prefix . $key);

        $this->recordCacheEvent('forget', $key, $started);

        return $forgotten;
    }

    /** Shared miss marker; identity is the whole point, so one instance. */
    private static ?\stdClass $missSentinel = null;

    /**
     * Feed the debug bar.
     *
     * Only the key and the outcome — never the cached value. A cache holds
     * rendered pages and query results, and writing those to a telemetry file
     * on every read would dwarf everything else in it.
     *
     * @param array<string, mixed> $extra
     */
    private function recordCacheEvent(string $operation, string $key, float $started, array $extra = []): void
    {
        if (!function_exists('telemetry')) {
            return;
        }

        try {
            telemetry()->record(
                \Core\Telemetry\Entry::TYPE_CACHE,
                array_merge([
                    'operation' => $operation,
                    'key' => $key,
                    'store' => $this->currentStoreName(),
                ], $extra),
                (microtime(true) - $started) * 1000,
                [$operation]
            );
        } catch (\Throwable) {
            // Observing the cache must not break it.
        }
    }

    private function currentStoreName(): string
    {
        try {
            $store = $this->store();
            $name = substr(strrchr('\\' . $store::class, '\\') ?: '', 1);

            return strtolower(str_replace(['Store', 'Driver'], '', $name)) ?: 'unknown';
        } catch (\Throwable) {
            return 'unknown';
        }
    }

    /**
     * Remove all items from the default store.
     */
    public function flush(): bool
    {
        return $this->store()->flush();
    }

    /**
     * Store an item only if the key does NOT already exist.
     */
    public function add(string $key, mixed $value, int $seconds = 0): bool
    {
        $store = $this->store();

        // Prefer the driver-native atomic add() when available so concurrent
        // callers cannot both see the key as missing and both write.
        if (method_exists($store, 'add')) {
            return (bool) $store->add($this->prefix . $key, $value, $seconds);
        }

        if ($this->has($key)) {
            return false;
        }

        return $this->put($key, $value, $seconds);
    }

    /**
     * Get multiple items from the cache.
     *
     * @param array<string> $keys
     * @return array<string, mixed>
     */
    public function many(array $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key);
        }
        return $result;
    }

    /**
     * Store multiple items in the cache.
     *
     * @param array<string, mixed> $values
     */
    public function putMany(array $values, int $seconds = 0): bool
    {
        $ok = true;
        foreach ($values as $key => $value) {
            $ok = $this->put($key, $value, $seconds) && $ok;
        }
        return $ok;
    }

    /**
     * Return metadata for a cache key (e.g. TTL remaining).
     * Used by RateLimiter::availableIn() to determine retry-after seconds.
     *
     * @return array{expires_in?: int}
     */
    public function getMetadata(string $key): array
    {
        $store = $this->store();
        if (method_exists($store, 'getMetadata')) {
            return $store->getMetadata($this->prefix . $key);
        }
        return [];
    }
}
