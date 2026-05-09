<?php

declare(strict_types=1);

namespace Core\Cache;

/**
 * Redis cache driver backed by the PHP ext-redis extension.
 *
 * Provides atomic increment/decrement operations and O(1) key access.
 * Gracefully unavailable on hosts without ext-redis — use FileStore as fallback.
 *
 * Config (add to app/config/cache.php):
 *   'redis' => [
 *       'driver'   => 'redis',
 *       'host'     => env('REDIS_HOST', '127.0.0.1'),
 *       'port'     => (int) env('REDIS_PORT', 6379),
 *       'password' => env('REDIS_PASSWORD', null),
 *       'database' => (int) env('REDIS_CACHE_DB', 1),
 *       'timeout'  => 2.0,
 *       'prefix'   => 'myth:',
 *   ],
 *
 */
final class RedisDriver
{
    private \Redis $redis;
    private string $prefix;

    public function __construct(array $config)
    {
        if (!extension_loaded('redis')) {
            throw new \RuntimeException('ext-redis is not loaded. Install php-redis or use the file cache driver.');
        }

        $this->prefix = $config['prefix'] ?? 'myth:';
        $this->redis  = new \Redis();

        $connected = $this->redis->connect(
            $config['host'] ?? '127.0.0.1',
            (int) ($config['port'] ?? 6379),
            (float) ($config['timeout'] ?? 2.0)
        );

        if (!$connected) {
            throw new \RuntimeException(
                'Failed to connect to Redis at ' . ($config['host'] ?? '127.0.0.1') . ':' . ($config['port'] ?? 6379)
            );
        }

        if (!empty($config['password'])) {
            $authResult = $this->redis->auth((string) $config['password']);
            if ($authResult === false) {
                throw new \RuntimeException(
                    'Redis authentication failed. Verify REDIS_PASSWORD in .env.'
                );
            }
        }

        if (isset($config['database'])) {
            $this->redis->select((int) $config['database']);
        }

        $this->redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_JSON);
        $this->redis->setOption(\Redis::OPT_PREFIX, $this->prefix);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->redis->get($key);
        return $value === false ? $default : $value;
    }

    public function put(string $key, mixed $value, int $ttlSeconds = 3600): bool
    {
        // SETEX requires a strictly positive TTL — Redis rejects ttl=0 with an error.
        // When $ttlSeconds <= 0 (the "forever" case used by CacheManager), use SET.
        if ($ttlSeconds > 0) {
            return $this->redis->setex($key, $ttlSeconds, $value);
        }

        return (bool) $this->redis->set($key, $value);
    }

    public function forever(string $key, mixed $value): bool
    {
        return $this->redis->set($key, $value);
    }

    public function has(string $key): bool
    {
        return (bool) $this->redis->exists($key);
    }

    public function forget(string $key): bool
    {
        return (bool) $this->redis->del($key);
    }

    /**
     * Flush all keys with this driver's prefix.
     * Uses SCAN to avoid blocking the server with a single KEYS command.
     *
     * Note: OPT_PREFIX is applied automatically to GET/SET/DEL operations but
     * SCAN returns raw Redis key names (already containing the prefix).
     * We therefore temporarily remove OPT_PREFIX while calling DEL so the
     * prefix is not double-applied.
     */
    public function flush(): bool
    {
        $cursor = null;

        // Disable prefix during DEL: SCAN returns raw key names (prefix included),
        // and DEL would prepend the prefix again if OPT_PREFIX is active.
        $this->redis->setOption(\Redis::OPT_PREFIX, '');

        try {
            do {
                $keys = $this->redis->scan($cursor, $this->prefix . '*', 100);
                if ($keys !== false && !empty($keys)) {
                    $this->redis->del($keys);
                }
            } while ($cursor);
        } finally {
            // Always restore prefix, even if an exception occurs
            $this->redis->setOption(\Redis::OPT_PREFIX, $this->prefix);
        }

        return true;
    }

    /**
     * Atomic increment — uses Redis INCRBY (no race condition).
     */
    public function increment(string $key, int $by = 1): int
    {
        return (int) $this->redis->incrBy($key, $by);
    }

    /**
     * Atomic decrement — uses Redis DECRBY.
     */
    public function decrement(string $key, int $by = 1): int
    {
        return (int) $this->redis->decrBy($key, $by);
    }

    /**
     * Add key only if it does not exist (atomic SET NX).
     * Returns true if set, false if already exists.
     */
    public function add(string $key, mixed $value, int $ttlSeconds = 3600): bool
    {
        // SET NX without EX stores the key forever when ttlSeconds <= 0.
        // Including 'ex' => 0 would cause a Redis ERR invalid expire time.
        $options = ['nx'];
        if ($ttlSeconds > 0) {
            $options['ex'] = $ttlSeconds;
        }

        return (bool) $this->redis->set($key, $value, $options);
    }

    /**
     * Return TTL in seconds (-1 = no expiry, -2 = key not found).
     */
    public function ttl(string $key): int
    {
        return $this->redis->ttl($key);
    }
}
