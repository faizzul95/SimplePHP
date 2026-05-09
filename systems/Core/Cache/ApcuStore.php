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
        if (!call_user_func('apcu_exists', $fullKey)) {
            return $default;
        }
        $value = call_user_func('apcu_fetch', $fullKey);
        return $value !== false ? $value : $default;
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
     * APCu's apcu_inc() is a single syscall — no read-compute-write race.
     */
    public function increment(string $key, int $amount = 1): int
    {
        $fullKey = $this->prefix . $key;
        if (!call_user_func('apcu_exists', $fullKey)) {
            call_user_func('apcu_store', $fullKey, $amount, 0);
            return $amount;
        }
        $result = call_user_func('apcu_inc', $fullKey, $amount);
        return (int) ($result !== false ? $result : $amount);
    }

    public function decrement(string $key, int $amount = 1): int
    {
        $fullKey = $this->prefix . $key;
        if (!call_user_func('apcu_exists', $fullKey)) {
            call_user_func('apcu_store', $fullKey, -$amount, 0);
            return -$amount;
        }
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
}
