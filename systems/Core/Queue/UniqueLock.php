<?php

declare(strict_types=1);

namespace Core\Queue;

/**
 * At-most-one-in-flight enforcement for jobs that declare a uniqueness key.
 *
 * Without this, anything that dispatches from a request handler queues one job per
 * request: a user double-clicking "generate report" gets two reports, a webhook
 * retried by the sender gets processed twice, and a scheduled task overlapping
 * with its previous run does the work twice concurrently.
 *
 * The lock is taken at dispatch time with an atomic add() — SET NX on Redis,
 * apcu_add() on APCu, an exclusive create on the file store — so N parallel
 * producers racing on the same key produce exactly one winner. It is released
 * after the job finishes, successfully or not.
 *
 * The TTL is a safety net, not the mechanism: if a worker is killed between
 * claiming and releasing, the lock expires rather than blocking the key forever.
 */
final class UniqueLock
{
    /** Fallback lifetime when a job does not declare one. */
    public const DEFAULT_TTL = 3600;

    private const PREFIX = 'queue:unique:';

    /**
     * Try to claim the key.
     *
     * @return bool True when this caller won and the job may be queued.
     */
    public static function acquire(string $key, int $ttlSeconds = self::DEFAULT_TTL): bool
    {
        if ($key === '' || !function_exists('cache')) {
            // No cache means no coordination is possible. Allowing the dispatch is
            // the right failure mode: dropping jobs silently is worse than
            // occasionally running one twice.
            return true;
        }

        try {
            return (bool) cache()->add(self::PREFIX . $key, time(), max(1, $ttlSeconds));
        } catch (\Throwable) {
            return true;
        }
    }

    public static function release(string $key): void
    {
        if ($key === '' || !function_exists('cache')) {
            return;
        }

        try {
            cache()->forget(self::PREFIX . $key);
        } catch (\Throwable) {
            // The TTL will clean it up.
        }
    }

    public static function isLocked(string $key): bool
    {
        if ($key === '' || !function_exists('cache')) {
            return false;
        }

        try {
            return (bool) cache()->has(self::PREFIX . $key);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Derive the cache key for a job.
     *
     * A job opts in by implementing uniqueId(). The class name is always part of
     * the key so two different job types cannot collide on the same id.
     */
    public static function keyFor(Job $job): ?string
    {
        if (!method_exists($job, 'uniqueId')) {
            return null;
        }

        $id = $job->uniqueId();

        if ($id === null || $id === '') {
            return null;
        }

        return static::keyForClass($job::class, (string) $id);
    }

    public static function keyForClass(string $jobClass, string $uniqueId): string
    {
        // Hashed so an arbitrarily long or awkward id cannot produce an invalid
        // cache key, while staying stable across processes.
        return hash('xxh128', $jobClass . '|' . $uniqueId);
    }

    /** Lifetime declared by the job, falling back to the default. */
    public static function ttlFor(Job $job): int
    {
        if (method_exists($job, 'uniqueFor')) {
            $ttl = (int) $job->uniqueFor();

            if ($ttl > 0) {
                return $ttl;
            }
        }

        return self::DEFAULT_TTL;
    }
}
