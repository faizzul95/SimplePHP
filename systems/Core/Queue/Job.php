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
    /**
     * The queue this job should be dispatched to.
     */
    public string $queue = 'default';

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
     * @return array{class: string, data: string, queue: string, delay: int, tries: int|null, timeout: int|null}
     */
    public function toPayload(): array
    {
        return [
            'class'   => static::class,
            'data'    => serialize($this),
            'queue'   => $this->queue,
            'delay'   => $this->delay,
            'tries'   => $this->tries,
            'timeout' => $this->timeout,
        ];
    }

    /**
     * Restore a Job instance from a serialized payload.
     */
    public static function fromPayload(array $payload): static
    {
        if (empty($payload['class']) || !is_subclass_of($payload['class'], self::class)) {
            throw new \RuntimeException("Invalid job class in payload.");
        }

        $job = unserialize($payload['data'], ['allowed_classes' => [$payload['class']]]);

        if (!$job instanceof static) {
            throw new \RuntimeException("Failed to unserialize job: expected " . static::class);
        }

        return $job;
    }
}
