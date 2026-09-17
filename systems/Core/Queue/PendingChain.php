<?php

declare(strict_types=1);

namespace Core\Queue;

/**
 * A chain that has been described but not yet queued.
 *
 * Exists so the queue, delay and retry budget can be set before the chain is
 * pushed. It dispatches itself on destruction when nothing called go(), so the
 * common `Bus::chain([...]);` still does what it looks like it does — while
 * `->onQueue('x')->go()` stays explicit.
 */
final class PendingChain
{
    /** @var list<Job> */
    private array $jobs;

    private ?string $queue = null;

    private ?int $delay = null;

    private ?int $tries = null;

    private bool $dispatched = false;

    /** @param list<Job> $jobs */
    public function __construct(array $jobs)
    {
        $this->jobs = array_values($jobs);
    }

    public function onQueue(string $queue): self
    {
        $this->queue = $queue;

        return $this;
    }

    public function delay(int $seconds): self
    {
        $this->delay = max(0, $seconds);

        return $this;
    }

    public function tries(int $tries): self
    {
        $this->tries = max(1, $tries);

        return $this;
    }

    /** Append a link. */
    public function then(Job $job): self
    {
        $this->jobs[] = $job;

        return $this;
    }

    public function count(): int
    {
        return count($this->jobs);
    }

    /** @return string|null The queued job id, or null when there was nothing to run. */
    public function go(): ?string
    {
        if ($this->dispatched || $this->jobs === []) {
            $this->dispatched = true;

            return null;
        }

        $this->dispatched = true;

        if (!function_exists('dispatch')) {
            throw new \RuntimeException('Cannot dispatch the chain: no queue dispatcher is available.');
        }

        return dispatch($this->build());
    }

    /** The chain built, for tests and inspection, without queueing it. */
    public function toJob(): ChainedJob
    {
        $this->dispatched = true;

        return $this->build();
    }

    /**
     * Shared by go() and toJob(), so what gets inspected is what gets queued.
     * These diverged once and the settings were silently missing from toJob().
     */
    private function build(): ChainedJob
    {
        $chain = new ChainedJob($this->jobs);

        if ($this->queue !== null) { $chain->queue = $this->queue; }
        if ($this->delay !== null) { $chain->delay = $this->delay; }
        if ($this->tries !== null) { $chain->tries = $this->tries; }

        return $chain;
    }

    /**
     * `Bus::chain([...]);` with no further calls should still queue the work.
     *
     * A destructor is the only hook that fires for a statement whose result is
     * discarded. Failures are swallowed here on purpose: PHP treats an
     * exception thrown during destruction as fatal, and turning a queue blip
     * into a fatal error is worse than the dropped chain it would report.
     */
    public function __destruct()
    {
        if ($this->dispatched || $this->jobs === []) {
            return;
        }

        try {
            $this->go();
        } catch (\Throwable $e) {
            if (class_exists(\Core\Support\SafeLog::class)) {
                \Core\Support\SafeLog::error('Chain dispatch failed on destruct: ' . $e->getMessage());
            }
        }
    }
}
