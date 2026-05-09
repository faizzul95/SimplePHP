<?php

namespace Core\Cache;

/**
 * ApcuStore — APCu-backed cache driver.
 *
 * Provides in-process + cross-worker shared memory caching via APCu.
 * Degrades transparently: if APCu is unavailable every write returns false
 * and every read returns the default — callers should check availability
 * before relying on this driver.
 *
 * Use CacheManager store('apcu') to obtain an instance via the config.
 * The manager will fall back to FileStore when APCu is not loaded.
 *
 * Shared hosting compatible: APCu is available on most cPanel/Plesk hosts
 * without requiring a separate service (unlike Redis/Memcached).
 */
class ApcuStore
{
    private string $prefix;

    public function __construct(string $prefix = 'MythPHP_cache:')
    {
        $this->prefix = $prefix;
    }

    // ─── Core Operations ─────────────────────────────────────────────────────

    public function get(string $key, mixed $default = null): mixed
    {
        $fullKey = $this->prefix . $key;

        // apcu_fetch() with the $success out-param is a single atomic call.
        // The old pattern (apcu_exists + apcu_fetch) had two races:
        //   1. TOCTOU: key could expire/evict between exists and fetch.
        //   2. False-value bug: if the cached value IS false, exists returns
        //      true but the `$value !== false` guard incorrectly returns $default.
        // Using the $success bool eliminates both problems.
        $success = false;
        $value   = call_user_func('apcu_fetch', $fullKey, $success);

        return $success ? $value : $default;
    }

    /**
     * @param int $seconds  0 = no expiry (stored until process restart or eviction)
     */
    public function put(string $key, mixed $value, int $seconds = 0): bool
    {
        return (bool) call_user_func('apcu_store', $this->prefix . $key, $value, $seconds);
    }

    public function forever(string $key, mixed $value): bool
    {
        return $this->put($key, $value, 0);
    }

    public function has(string $key): bool
    {
        return (bool) call_user_func('apcu_exists', $this->prefix . $key);
    }

    public function forget(string $key): bool
    {
        return (bool) call_user_func('apcu_delete', $this->prefix . $key);
    }

    public function flush(): bool
    {
        call_user_func('apcu_clear_cache');
        return true;
    }

    // ─── Atomic Operations ────────────────────────────────────────────────────

    /**
     * Atomically increment a numeric value.
     *
     * Increment-first strategy — no existence check before the operation.
     * apcu_inc() fails (returns false) only when the key doesn't exist yet.
     * In that case we atomically create it with apcu_add() (NX semantics).
     * If two threads race on creation, only one wins apcu_add(); the loser
     * retries apcu_inc() which now succeeds. This eliminates the classic
     * check-then-act (TOCTOU) race of apcu_exists() + apcu_store().
     */
    public function increment(string $key, int $amount = 1): int
    {
        $fullKey = $this->prefix . $key;

        // Fast path: key already exists — atomic single-syscall increment.
        $result = call_user_func('apcu_inc', $fullKey, $amount);
        if ($result !== false) {
            return (int) $result;
        }

        // Slow path: key does not exist — atomically create with apcu_add (NX).
        if (call_user_func('apcu_add', $fullKey, $amount, 0)) {
            return $amount;
        }

        // Another thread won the creation race — retry the increment.
        $result = call_user_func('apcu_inc', $fullKey, $amount);
        return (int) ($result !== false ? $result : $amount);
    }

    public function decrement(string $key, int $amount = 1): int
    {
        $fullKey = $this->prefix . $key;

        // Fast path: key already exists.
        $result = call_user_func('apcu_dec', $fullKey, $amount);
        if ($result !== false) {
            return (int) $result;
        }

        // Slow path: atomically create with apcu_add (NX).
        if (call_user_func('apcu_add', $fullKey, -$amount, 0)) {
            return -$amount;
        }

        // Retry after losing the creation race.
        $result = call_user_func('apcu_dec', $fullKey, $amount);
        return (int) ($result !== false ? $result : -$amount);
    }

    /**
     * Store only if key does not already exist.
     * apcu_add() is atomic — it fails without overwriting if the key exists.
     */
    public function add(string $key, mixed $value, int $seconds = 0): bool
    {
        return (bool) call_user_func('apcu_add', $this->prefix . $key, $value, $seconds);
    }

    /**
     * Return metadata for a cache key, including TTL remaining.
     * Used by RateLimiter::availableIn() to determine retry-after seconds.
     */
    public function getMetadata(string $key): array
    {
        $fullKey = $this->prefix . $key;
        if (!function_exists('apcu_key_info')) {
            return [];
        }
        $info = call_user_func('apcu_key_info', $fullKey);
        if (!is_array($info)) {
            return [];
        }
        $createdAt = (int) ($info['creation_time'] ?? time());
        $ttlSetting = (int) ($info['ttl'] ?? 0);
        $expiresIn = $ttlSetting === 0 ? 0 : max(0, $createdAt + $ttlSetting - time());
        return ['expires_in' => $expiresIn];
    }
}
