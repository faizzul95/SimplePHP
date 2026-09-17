<?php

declare(strict_types=1);

namespace Core\Queue;

/**
 * Entry point for composing queued work.
 *
 *     Bus::chain([new A(), new B(), new C()]);        // B waits for A
 *     Bus::chain([...])->onQueue('reports')->go();    // configure, then push
 *
 * chain() dispatches immediately when called with jobs and nothing else; the
 * fluent form is there for when the chain needs its own queue or delay.
 */
final class Bus
{
    /**
     * Run jobs in sequence, each starting only after the previous one succeeds.
     *
     * @param list<Job> $jobs
     */
    public static function chain(array $jobs): PendingChain
    {
        return new PendingChain(array_values(array_filter(
            $jobs,
            static fn(mixed $job): bool => $job instanceof Job
        )));
    }
}
