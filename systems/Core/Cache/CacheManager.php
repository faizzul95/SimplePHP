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
    private array $config;
    private string $prefix;

    /** @var array<string, FileStore|ArrayStore> Resolved store instances */
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
    public function store(?string $name = null): FileStore|ArrayStore|ApcuStore
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
    private function resolve(string $name): FileStore|ArrayStore|ApcuStore
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

        return match ($driver) {
            'file'  => new FileStore(
                (defined('ROOT_DIR') ? ROOT_DIR : dirname(__DIR__, 2) . DIRECTORY_SEPARATOR)
                . ($storeConfig['path'] ?? 'storage/cache/app')
            ),
            'array' => new ArrayStore(),
            default => throw new \InvalidArgumentException("Cache driver [{$driver}] is not supported."),
        };
    }

    // ─── Proxy Methods (default store) ───────────────────────

    /**
     * Retrieve an item from the cache.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->store()->get($this->prefix . $key, $default);
    }

    /**
     * Store an item in the cache.
     *
     * @param string $key
     * @param mixed  $value
     * @param int    $seconds  TTL in seconds (0 = forever)
     */
    public function put(string $key, mixed $value, int $seconds = 0): bool
    {
        return $this->store()->put($this->prefix . $key, $value, $seconds);
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
     */
    public function remember(string $key, int $seconds, \Closure $callback): mixed
    {
        if ($this->has($key)) {
            return $this->get($key);
        }

        $value = $callback();
        $this->put($key, $value, $seconds);

        return $value;
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
    public function increment(string $key, int $amount = 1): int
    {
        return $this->store()->increment($this->prefix . $key, $amount);
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
        return $this->store()->forget($this->prefix . $key);
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
     * @param int $seconds
     */
    public function putMany(array $values, int $seconds = 0): bool
    {
        $ok = true;
        foreach ($values as $key => $value) {
            $ok = $this->put($key, $value, $seconds) && $ok;
        }
        return $ok;
    }
}
