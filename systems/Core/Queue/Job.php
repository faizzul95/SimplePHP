<?php

namespace Core\Queue;

/**
 * Base Job Class
 *
 * Extend this class to create dispatchable background jobs.
 *
 * Usage:
 *   class SendWelcomeEmail extends Job {
 *       public function __construct(private int $userId) {}
 *       public function handle(): void { ... }
 *   }
 *
 *   dispatch(new SendWelcomeEmail($userId));
 *
 * @see \Core\Queue\Worker
 */
abstract class Job
{
    public const PRIORITY_CRITICAL = 0;
    public const PRIORITY_HIGH = 2;
    public const PRIORITY_NORMAL = 5;
    public const PRIORITY_LOW = 8;
    public const PRIORITY_BULK = 10;

    /**
     * The queue this job should be dispatched to.
     */
    public string $queue = 'default';

    /**
     * Numeric priority for this job. Lower numbers are processed first.
     */
    public int $priority = self::PRIORITY_NORMAL;

    /**
     * Number of seconds to delay before the job becomes available.
     */
    public int $delay = 0;

    /**
     * Maximum number of attempts for this job (overrides worker default).
     */
    public ?int $tries = null;

    /**
     * Maximum seconds this job may run (overrides worker default).
     */
    public ?int $timeout = null;

    /**
     * Execute the job.
     */
    abstract public function handle(): void;

    /**
     * Opt into at-most-one-in-flight by returning a stable key.
     *
     * While a job with this id is queued or running, further dispatches of the
     * same class and id are dropped. Use it for work that must not overlap or
     * double up: a report a user can trigger twice by double-clicking, a webhook
     * the sender may retry, a scheduled task that could overlap its own previous
     * run.
     *
     *     public function uniqueId(): string
     *     {
     *         return 'invoice-' . $this->invoiceId;
     *     }
     *
     * Returning null (the default) means no uniqueness constraint.
     *
     * The claim is released when the job succeeds or fails permanently, but NOT
     * between retries — a retrying job is still in flight.
     */
    public function uniqueId(): ?string
    {
        return null;
    }

    /**
     * How long the uniqueness claim may survive without being released, in
     * seconds. This is a safety net for a worker killed mid-job, not the
     * mechanism — normal completion releases the claim immediately.
     *
     * Set it comfortably above the job's worst-case runtime.
     */
    public function uniqueFor(): int
    {
        return UniqueLock::DEFAULT_TTL;
    }

    /**
     * Handle a job failure (called after all retries are exhausted).
     */
    public function failed(\Throwable $e): void
    {
        // Override in subclass for custom failure handling
    }

    /**
     * Set the queue name.
     */
    public function onQueue(string $queue): static
    {
        $this->queue = $queue;
        return $this;
    }

    /**
     * Set a numeric priority between 0 (highest) and 10 (lowest).
     */
    public function priority(int $priority): static
    {
        $this->priority = $this->normalizePriority($priority);
        return $this;
    }

    public function critical(): static
    {
        return $this->priority(self::PRIORITY_CRITICAL);
    }

    public function high(): static
    {
        return $this->priority(self::PRIORITY_HIGH);
    }

    public function normal(): static
    {
        return $this->priority(self::PRIORITY_NORMAL);
    }

    public function low(): static
    {
        return $this->priority(self::PRIORITY_LOW);
    }

    public function bulk(): static
    {
        return $this->priority(self::PRIORITY_BULK);
    }

    /**
     * Set the delay in seconds.
     */
    public function delay(int $seconds): static
    {
        $this->delay = $seconds;
        return $this;
    }

    /**
     * Serialize the job payload for storage.
     *
     * @return array{class: string, data: string, queue: string, delay: int, tries: int|null, timeout: int|null, priority: int}
     */
    public function toPayload(): array
    {
        $payload = [
            'class'   => static::class,
            'data'    => serialize($this),
            'queue'   => $this->queue,
            'delay'   => $this->delay,
            'tries'   => $this->tries,
            'timeout' => $this->timeout,
            'priority' => $this->normalizePriority($this->priority),
        ];

        // Carried with the job so the worker can release the uniqueness claim once
        // the work is actually done, rather than waiting for the TTL to lapse.
        $uniqueKey = UniqueLock::keyFor($this);

        if ($uniqueKey !== null) {
            $payload['unique_key'] = $uniqueKey;
        }

        return $payload;
    }

    /**
     * Restore a Job instance from a serialized payload.
     */
    public static function fromPayload(array $payload): static
    {
        if (empty($payload['class']) || !is_subclass_of($payload['class'], self::class)) {
            throw new \RuntimeException("Invalid job class in payload.");
        }

        if (!array_key_exists('data', $payload) || !is_string($payload['data']) || $payload['data'] === '') {
            throw new \RuntimeException('Invalid job payload data.');
        }

        $job = unserialize($payload['data'], ['allowed_classes' => [$payload['class']]]);

        if (!$job instanceof static) {
            throw new \RuntimeException("Failed to unserialize job: expected " . static::class);
        }

        // Defence in depth: make sure the serialized object's real class matches
        // the class declared in the payload. Without this check, an attacker
        // could pass payload['class'] = JobA while the serialized blob contains
        // JobB (also on the allowlist), side-stepping expectations upstream.
        if (get_class($job) !== $payload['class']) {
            throw new \RuntimeException('Job class mismatch between payload and serialized data.');
        }

        $job->priority = $job->normalizePriority((int) ($payload['priority'] ?? $job->priority ?? self::PRIORITY_NORMAL));

        return $job;
    }

    protected function normalizePriority(int $priority): int
    {
        return max(self::PRIORITY_CRITICAL, min(self::PRIORITY_BULK, $priority));
    }
}
