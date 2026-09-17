<?php

declare(strict_types=1);

namespace Core\Queue;

/**
 * Runs a list of jobs one after another, each starting only once the previous
 * one has finished successfully.
 *
 * Implemented as composition rather than as queue state: this job holds the
 * remaining links, runs the head, and dispatches the tail. Nothing has to be
 * tracked between workers, so a chain survives a worker restart the same way
 * any single queued job does.
 *
 * A failing link stops the chain. That is the point of a chain — if step two
 * did not happen, step three usually must not either — and it is why the
 * failure is rethrown rather than swallowed: the worker's retry budget then
 * applies to the failed link, and the tail is dispatched only if it eventually
 * succeeds.
 */
final class ChainedJob extends Job
{
    /** @var list<Job> */
    private array $jobs;

    /** @param list<Job> $jobs */
    public function __construct(array $jobs)
    {
        $this->jobs = array_values($jobs);

        /*
        | The chain inherits the head's settings, so `Bus::chain([...])->onQueue()`
        | is not needed for the common case where every link belongs on the same
        | queue as the first.
        */
        $head = $this->jobs[0] ?? null;

        if ($head instanceof Job) {
            $this->queue = $head->queue;
            $this->priority = $head->priority;
            $this->tries = $head->tries;
            $this->timeout = $head->timeout;
            $this->delay = $head->delay;
        }
    }

    /** @return list<Job> */
    public function jobs(): array
    {
        return $this->jobs;
    }

    public function remaining(): int
    {
        return count($this->jobs);
    }

    public function handle(): void
    {
        $jobs = $this->jobs;
        $current = array_shift($jobs);

        if (!$current instanceof Job) {
            return;
        }

        $started = microtime(true);
        $current->handle();

        $this->recordTelemetry($current, $started, count($jobs));

        if ($jobs !== []) {
            $this->dispatchNext(new self($jobs));
        }
    }

    /**
     * Push the tail.
     *
     * A chain whose tail cannot be queued has silently stopped half-way, which
     * is worse than failing: rethrowing lets the worker retry the whole link.
     */
    private function dispatchNext(self $next): void
    {
        if (!function_exists('dispatch')) {
            throw new \RuntimeException('Cannot continue the chain: no queue dispatcher is available.');
        }

        dispatch($next);
    }

    private function recordTelemetry(Job $completed, float $startedAt, int $remaining): void
    {
        if (!function_exists('telemetry')) {
            return;
        }

        try {
            telemetry()->recordQueue([
                'job' => $completed::class,
                'status' => 'chain-link-complete',
                'remaining' => $remaining,
            ], (microtime(true) - $startedAt) * 1000);
        } catch (\Throwable) {
            // Observing a job must not break it.
        }
    }
}
