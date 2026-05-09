<?php

declare(strict_types=1);

namespace Core\Server;

/**
 * Per-request static state flusher for Octane-style long-running workers.
 *
 * In FrankenPHP, RoadRunner, or Swoole workers, static properties from one request
 * leak into the next. This class resets all known stateful singletons at the
 * beginning of every request cycle.
 *
 * Call WorkerState::flush() as the FIRST operation in the request loop —
 * not at the end — so state is always clean even if the previous request threw.
 *
 * To register a new stateful class:
 *   1. Add its fully-qualified class name to STATEFUL_CLASSES.
 *   2. Ensure the class implements one of: reset(), flushCache(), resetQueryLog() (static, void).
 *
 */
final class WorkerState
{
    /** @var list<class-string> Immutable core stateful classes */
    private const CORE_STATEFUL_CLASSES = [
        \Core\Security\CspNonce::class,
        \Core\Security\AuditLogger::class,
        \Core\Database\ConnectionPool::class,
        \Core\Database\PerformanceMonitor::class,
        \Core\Database\QueryCache::class,
        \Core\View\BladeEngine::class,
    ];

    /** @var list<class-string> Additional classes registered at runtime */
    private static array $extraClasses = [];

    /**
     * Flush all per-request static state.
     * Call this at the start of every request in worker mode.
     */
    public static function flush(): void
    {
        foreach (array_merge(self::CORE_STATEFUL_CLASSES, self::$extraClasses) as $class) {
            if (!class_exists($class)) {
                continue;
            }

            if (method_exists($class, 'reset')) {
                $class::reset();
            }

            if (method_exists($class, 'flushCache')) {
                $class::flushCache();
            }

            if (method_exists($class, 'resetQueryLog')) {
                $class::resetQueryLog();
            }
        }
    }

    /**
     * Register an additional stateful class at runtime.
     * Call this from service providers that have their own static state.
     *
     * @param class-string $className
     */
    public static function register(string $className): void
    {
        if (!in_array($className, self::CORE_STATEFUL_CLASSES, true)
            && !in_array($className, self::$extraClasses, true)
        ) {
            self::$extraClasses[] = $className;
        }
    }

    /**
     * Reset runtime-registered classes (for test isolation).
     */
    public static function reset(): void
    {
        self::$extraClasses = [];
    }
}
