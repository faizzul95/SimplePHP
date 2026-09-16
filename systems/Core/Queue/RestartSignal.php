<?php

declare(strict_types=1);

namespace Core\Queue;

/**
 * Cooperative restart signal for queue workers.
 *
 * Long-running workers hold the application code they booted with. After a deploy
 * they keep executing the old classes until someone kills them by hand, which
 * means a deploy silently runs mixed versions — new web requests against new code,
 * background jobs against old. There was no `queue:restart`, so the only options
 * were `kill` (drops the job in flight) or waiting.
 *
 * The mechanism is a timestamp in the shared cache. Workers read it between jobs
 * and exit cleanly when it is newer than the one they started with; a supervisor
 * or systemd then restarts them on the new code. Because the check happens between
 * jobs rather than during one, no work is ever interrupted mid-flight.
 */
final class RestartSignal
{
    private const CACHE_KEY = 'queue:restart:requested_at';

    /**
     * Ask every running worker to finish its current job and exit.
     *
     * @return int The timestamp recorded, or 0 when no cache is available.
     */
    public static function request(?int $timestamp = null): int
    {
        $timestamp ??= time();

        if (!self::cacheAvailable()) {
            return 0;
        }

        try {
            // Kept effectively forever: a worker started before the signal must
            // still see it, however long it was idle.
            cache()->forever(self::CACHE_KEY, $timestamp);
        } catch (\Throwable) {
            return 0;
        }

        return $timestamp;
    }

    /**
     * The timestamp of the most recent restart request, or null if none.
     *
     * A worker captures this at boot and compares between jobs.
     */
    public static function lastRequestedAt(): ?int
    {
        if (!self::cacheAvailable()) {
            return null;
        }

        try {
            $value = cache()->get(self::CACHE_KEY);
        } catch (\Throwable) {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Whether a worker that booted with $bootedWith should now stop.
     *
     * A null $bootedWith means the worker started before any restart was ever
     * requested, so any recorded signal applies to it.
     */
    public static function shouldRestart(?int $bootedWith): bool
    {
        $current = self::lastRequestedAt();

        if ($current === null) {
            return false;
        }

        return $bootedWith === null || $current > $bootedWith;
    }

    /** Clear the signal — used by tests and by `queue:restart --clear`. */
    public static function clear(): void
    {
        if (!self::cacheAvailable()) {
            return;
        }

        try {
            cache()->forget(self::CACHE_KEY);
        } catch (\Throwable) {
            // Nothing to do: a missing signal is the desired end state anyway.
        }
    }

    private static function cacheAvailable(): bool
    {
        return function_exists('cache');
    }
}
