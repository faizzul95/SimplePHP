<?php

declare(strict_types=1);

namespace Core\Queue;

/**
 * Redis Queue Driver
 *
 * Uses Redis data structures:
 *   - ZADD queue:{name}:delayed   score=available_at  value=payload  (delayed jobs)
 *   - LPUSH queue:{name}           value=payload                      (ready jobs)
 *   - RPOPLPUSH queue:{name} queue:{name}:reserved                   (reserve job)
 *
 * Configure in config/queue.php:
 *   'default' => 'redis',
 *   'connections' => [
 *       'redis' => [
 *           'host'     => '127.0.0.1',
 *           'port'     => 6379,
 *           'database' => 1,        // DB index (optional, default 0)
 *           'password' => null,      // (optional)
 *           'timeout'  => 2.0,       // connection timeout (optional)
 *           'prefix'   => 'mythphp_queue:',
 *       ],
 *   ],
 */
final class RedisQueue
{
    private \Redis $redis;
    private string $prefix;

    /**
     * @param array $config  The 'connections.redis' config block
     * @throws \RuntimeException if the Redis extension is not loaded
     */
    public function __construct(array $config = [])
    {
        if (!extension_loaded('redis')) {
            throw new \RuntimeException('Redis extension is required to use the Redis queue driver.');
        }

        $host     = (string) ($config['host']     ?? '127.0.0.1');
        $port     = (int)    ($config['port']     ?? 6379);
        $database = (int)    ($config['database'] ?? 0);
        $password = $config['password'] ?? null;
        $timeout  = (float)  ($config['timeout']  ?? 2.0);
        $this->prefix = (string) ($config['prefix'] ?? 'mythphp_queue:');

        $this->redis = new \Redis();

        if (!$this->redis->connect($host, $port, $timeout)) {
            throw new \RuntimeException("Could not connect to Redis at {$host}:{$port}.");
        }

        if (!empty($password)) {
            if (!$this->redis->auth($password)) {
                throw new \RuntimeException('Redis authentication failed.');
            }
        }

        if ($database !== 0) {
            $this->redis->select($database);
        }
    }

    /**
     * Push a job onto the named queue (or delayed set).
     *
     * @param  Job    $job
     * @param  string $queue  Queue name (e.g. 'default', 'emails')
     * @param  int    $delay  Seconds before the job becomes available
     * @return string         Unique job ID
     */
    public function push(Job $job, string $queue = 'default', int $delay = 0): string
    {
        $payload = json_encode($job->toPayload(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $id = bin2hex(random_bytes(16));

        if ($delay > 0) {
            $score = time() + $delay;
            $this->redis->zAdd($this->delayedKey($queue), $score, $id . '|' . $payload);
        } else {
            $this->redis->lPush($this->queueKey($queue), $id . '|' . $payload);
        }

        return $id;
    }

    /**
     * Reserve the next available job from the queue (FIFO).
     * Moves delayed jobs whose score <= now() into the ready list first.
     *
     * @param  string $queue
     * @return array{id: string, payload: array}|null  null if no job available
     */
    public function pop(string $queue = 'default'): ?array
    {
        // Migrate any delayed jobs that are now ready
        $this->migrateDelayed($queue);

        // RPOPLPUSH: atomically move from queue to reserved set
        $raw = $this->redis->rPopLPush($this->queueKey($queue), $this->reservedKey($queue));
        if ($raw === false || $raw === null) {
            return null;
        }

        [$id, $jsonPayload] = $this->parseRaw((string) $raw);

        $payload = json_decode($jsonPayload, true, 512, JSON_THROW_ON_ERROR);

        return ['id' => $id, 'payload' => $payload];
    }

    /**
     * Acknowledge a job as completed (remove from reserved set).
     */
    public function ack(string $queue, string $id, string $rawPayload): void
    {
        $this->redis->lRem($this->reservedKey($queue), $id . '|' . $rawPayload, 1);
    }

    /**
     * Release a reserved job back onto the queue (retry).
     */
    public function release(string $queue, string $id, string $rawPayload, int $delay = 0): void
    {
        // Remove from reserved
        $this->redis->lRem($this->reservedKey($queue), $id . '|' . $rawPayload, 1);

        if ($delay > 0) {
            $this->redis->zAdd($this->delayedKey($queue), time() + $delay, $id . '|' . $rawPayload);
        } else {
            $this->redis->lPush($this->queueKey($queue), $id . '|' . $rawPayload);
        }
    }

    /**
     * Return the number of pending jobs in the ready queue.
     */
    public function size(string $queue = 'default'): int
    {
        return (int) $this->redis->lLen($this->queueKey($queue));
    }

    /**
     * Flush all pending jobs from a queue (caution: destructive).
     */
    public function flush(string $queue = 'default'): void
    {
        $this->redis->del(
            $this->queueKey($queue),
            $this->delayedKey($queue),
            $this->reservedKey($queue)
        );
    }

    // ------------------------------------------------------------------
    // Internal helpers
    // ------------------------------------------------------------------

    /**
     * Move any delayed jobs with score <= now into the ready queue.
     *
     * Uses a Lua script executed atomically on the Redis server to prevent
     * two workers from both migrating the same delayed job (double-dispatch).
     * The script performs ZRANGEBYSCORE + ZREM + LPUSH as a single atomic unit.
     */
    private function migrateDelayed(string $queue): void
    {
        $now  = time();
        $lua  = <<<'LUA'
            local jobs = redis.call('ZRANGEBYSCORE', KEYS[1], '-inf', ARGV[1])
            for _, job in ipairs(jobs) do
                local removed = redis.call('ZREM', KEYS[1], job)
                if removed == 1 then
                    redis.call('LPUSH', KEYS[2], job)
                end
            end
            return #jobs
        LUA;

        try {
            $this->redis->eval(
                $lua,
                [$this->delayedKey($queue), $this->queueKey($queue), (string) $now],
                2  // number of KEYS arguments
            );
        } catch (\Throwable) {
            // Lua scripting unavailable — fall back to best-effort per-job migrate.
            $jobs = $this->redis->zRangeByScore($this->delayedKey($queue), '-inf', (string) $now);
            foreach ((array) $jobs as $raw) {
                $removed = $this->redis->zRem($this->delayedKey($queue), $raw);
                if ($removed) {
                    $this->redis->lPush($this->queueKey($queue), $raw);
                }
            }
        }
    }

    private function queueKey(string $queue): string
    {
        return $this->prefix . 'queue:' . $queue;
    }

    private function delayedKey(string $queue): string
    {
        return $this->prefix . 'delayed:' . $queue;
    }

    private function reservedKey(string $queue): string
    {
        return $this->prefix . 'reserved:' . $queue;
    }

    /** Split a stored "{id}|{payload}" string. */
    private function parseRaw(string $raw): array
    {
        $pos = strpos($raw, '|');
        if ($pos === false) {
            return ['unknown', $raw];
        }
        return [substr($raw, 0, $pos), substr($raw, $pos + 1)];
    }
}
