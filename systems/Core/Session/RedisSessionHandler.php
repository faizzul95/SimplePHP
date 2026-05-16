<?php

declare(strict_types=1);

namespace Core\Session;

final class RedisSessionHandler implements \SessionHandlerInterface, \SessionUpdateTimestampHandlerInterface
{
    private object $redis;
    private string $prefix;
    private int $ttlSeconds;
    private int $lockTtl;
    private int $lockWaitMs;
    private int $lockRetryUs;

    public function __construct(array $config = [], ?object $redis = null)
    {
        $redisConfig = (array) ($config['redis'] ?? $config);

        $this->prefix = (string) ($redisConfig['prefix'] ?? 'myth_session:');
        $this->ttlSeconds = max(60, (int) (($config['lifetime'] ?? 120) * 60));
        $this->lockTtl = max(1, (int) ($redisConfig['lock_ttl'] ?? 10));
        $this->lockWaitMs = max(0, (int) ($redisConfig['lock_wait_ms'] ?? 150));
        $this->lockRetryUs = max(1000, (int) ($redisConfig['lock_retry_us'] ?? 15000));
        $this->redis = $redis ?? $this->connect($redisConfig);
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string
    {
        try {
            $value = $this->redis->get($this->dataKey($id));
        } catch (\Throwable) {
            return '';
        }

        return is_string($value) ? $value : '';
    }

    public function write(string $id, string $data): bool
    {
        $lockKey = $this->lockKey($id);

        if (!$this->acquireLock($lockKey)) {
            return false;
        }

        try {
            return (bool) $this->redis->setex($this->dataKey($id), $this->ttlSeconds, $data);
        } catch (\Throwable) {
            return false;
        } finally {
            $this->releaseLock($lockKey);
        }
    }

    public function destroy(string $id): bool
    {
        try {
            $this->redis->del($this->dataKey($id), $this->lockKey($id));
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function gc(int $max_lifetime): int|false
    {
        return 0;
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        try {
            return (bool) $this->redis->expire($this->dataKey($id), $this->ttlSeconds);
        } catch (\Throwable) {
            return false;
        }
    }

    public function validateId(string $id): bool
    {
        try {
            return (bool) $this->redis->exists($this->dataKey($id));
        } catch (\Throwable) {
            return false;
        }
    }

    private function acquireLock(string $lockKey): bool
    {
        $deadline = microtime(true) + ($this->lockWaitMs / 1000);

        do {
            try {
                $acquired = $this->redis->set($lockKey, '1', ['nx', 'ex' => $this->lockTtl]);
                if ($acquired) {
                    return true;
                }
            } catch (\Throwable) {
                return false;
            }

            usleep($this->lockRetryUs);
        } while (microtime(true) < $deadline);

        return false;
    }

    private function releaseLock(string $lockKey): void
    {
        try {
            $this->redis->del($lockKey);
        } catch (\Throwable) {
            // Best effort only.
        }
    }

    private function dataKey(string $id): string
    {
        return $this->prefix . 'data:' . $id;
    }

    private function lockKey(string $id): string
    {
        return $this->prefix . 'lock:' . $id;
    }

    private function connect(array $config): object
    {
        if (!extension_loaded('redis')) {
            throw new \RuntimeException('ext-redis is not loaded. Install php-redis or keep SESSION_DRIVER=file.');
        }

        $redisClass = 'Redis';
        $redis = new $redisClass();
        $connected = $redis->connect(
            (string) ($config['host'] ?? '127.0.0.1'),
            (int) ($config['port'] ?? 6379),
            (float) ($config['timeout'] ?? 2.0)
        );

        if ($connected !== true) {
            throw new \RuntimeException('Failed to connect to Redis session backend.');
        }

        if (!empty($config['password']) && $redis->auth((string) $config['password']) !== true) {
            throw new \RuntimeException('Redis session authentication failed.');
        }

        if (isset($config['database'])) {
            $redis->select((int) $config['database']);
        }

        return $redis;
    }
}