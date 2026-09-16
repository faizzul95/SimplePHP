<?php

declare(strict_types=1);

namespace Core\Server;

use Core\Http\Emitter;
use Core\Http\Request;
use Core\Http\Response;
use Core\Session\SessionCycle;

/**
 * Per-request state reset for Octane-style long-running workers.
 *
 * In FrankenPHP, RoadRunner or Swoole a single PHP process serves many requests,
 * so anything held in a static property or a resolved singleton survives into the
 * next one. This class puts the process back into a clean state.
 *
 * Call WorkerState::flush() as the FIRST operation in the request loop — not at
 * the end — so state is clean even when the previous request threw.
 *
 * Three categories have to be handled, and the original version only did the first:
 *
 *   1. Static caches on known classes (CspNonce, QueryCache, …).
 *   2. Resolved service singletons. Components\Auth caches the authenticated user
 *      and their ACL set on the instance, so reusing it serves one caller's
 *      identity to the next. This was the actual data-disclosure bug.
 *   3. Superglobals, the session, and the emission flag.
 *
 * To register a new stateful class:
 *   1. Add its fully-qualified name to STATEFUL_CLASSES (or call register()).
 *   2. Give it a static reset(), flushCache() or resetQueryLog() taking no arguments.
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
        \Core\Queue\Dispatcher::class,
        \Core\Support\LogContext::class,
    ];

    /**
     * $_SERVER keys captured at worker boot.
     *
     * The bridge overwrites the keys a request supplies but never removes the ones
     * it does not, so a request carrying X-Forwarded-For left that header visible
     * to the next one — which then resolved the wrong client IP for rate limiting,
     * IP blocklisting and the audit log.
     *
     * @var array<string, mixed>|null
     */
    private static ?array $serverBaseline = null;

    /** @var list<class-string> Additional classes registered at runtime */
    private static array $extraClasses = [];

    /**
     * Remember the ambient $_SERVER before any request is handled.
     *
     * Call once, from the worker entry point, before the loop starts.
     */
    public static function captureBaseline(): void
    {
        self::$serverBaseline = $_SERVER;
    }

    /**
     * Flush all per-request state.
     *
     * @param bool $resetSuperglobals False in tests, where $_SERVER is the fixture.
     */
    public static function flush(bool $resetSuperglobals = true): void
    {
        self::flushStatefulClasses();

        // Instances only. Clearing the resolvers too would unregister every
        // provider, and providers run once per worker, not once per request.
        if (function_exists('reset_framework_service_instances')) {
            reset_framework_service_instances();
        } elseif (function_exists('reset_framework_service')) {
            // Older bootstrap without the instance-only helper: at least drop the
            // singletons that carry identity.
            foreach (['auth', 'security', 'database.runtime', 'feature'] as $service) {
                reset_framework_service($service);
            }
        }

        Request::setCurrent(null);
        Response::resetLinkHeaders();
        Emitter::reset();

        SessionCycle::reset();

        // The flash bag tracks "already rotated this request" in a function
        // static, which outlives the request under a worker: without this the
        // second request a worker serves never rotates, so keys flashed for one
        // user stay readable by the next.
        if (function_exists('resetFlashSessionState')) {
            resetFlashSessionState();
        }

        if ($resetSuperglobals) {
            self::restoreServerBaseline();
        }
    }

    private static function flushStatefulClasses(): void
    {
        foreach (array_merge(self::CORE_STATEFUL_CLASSES, self::$extraClasses) as $class) {
            if (!class_exists($class)) {
                continue;
            }

            foreach (['reset', 'flushCache', 'resetQueryLog'] as $method) {
                if (!method_exists($class, $method)) {
                    continue;
                }

                try {
                    $class::$method();
                } catch (\Throwable $e) {
                    // One uncooperative class must not leave the rest of the
                    // process dirty — that would be worse than the original leak.
                    self::reportFlushFailure($class, $method, $e);
                }
            }
        }
    }

    /**
     * Report a failed reset without ever throwing.
     *
     * logger() itself throws when the logger service is not registered — which is
     * exactly the situation during a flush that has already cleared the service
     * instances. Letting that escape would abort the rest of the flush and leave
     * the process in the dirty state this class exists to prevent.
     */
    private static function reportFlushFailure(string $class, string $method, \Throwable $error): void
    {
        \Core\Support\SafeLog::error(sprintf(
            'WorkerState: %s::%s() failed: %s',
            $class,
            $method,
            $error->getMessage()
        ));
    }

    private static function restoreServerBaseline(): void
    {
        if (self::$serverBaseline === null) {
            return;
        }

        $_SERVER = self::$serverBaseline;
        $_GET = [];
        $_POST = [];
        $_FILES = [];
        $_COOKIE = [];
        $_REQUEST = [];

        // A sticky response code from the previous request would otherwise become
        // the default for this one.
        if (!headers_sent()) {
            http_response_code(200);
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

    /** @return list<class-string> */
    public static function registered(): array
    {
        return array_values(array_merge(self::CORE_STATEFUL_CLASSES, self::$extraClasses));
    }

    /**
     * Reset runtime-registered classes (for test isolation).
     */
    public static function reset(): void
    {
        self::$extraClasses = [];
        self::$serverBaseline = null;
    }
}
