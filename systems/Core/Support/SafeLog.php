<?php

declare(strict_types=1);

namespace Core\Support;

/**
 * Logging that cannot itself throw.
 *
 * `logger()` resolves a service, and `framework_service()` throws when nothing is
 * registered under that name. That is fine in a normal request, but it is exactly
 * wrong inside a failure handler: the places that most need to log are the places
 * where the container may be half-built, already flushed, or never bootstrapped —
 * a failed error-view render, a worker state flush, a bootstrap failure.
 *
 * This has bitten twice: once in WorkerState::flush(), where a logging failure
 * aborted the rest of the flush and left the process dirty, and once in
 * Abort::view(), where the catch block meant to survive a broken view threw
 * instead. Both were caught by tests that ran without a logger registered.
 *
 * Every method here degrades: service → Logger::instance() → error_log().
 */
final class SafeLog
{
    public static function error(string $message): void
    {
        self::write('log_error', $message);
    }

    public static function warning(string $message): void
    {
        self::write('log_warning', $message);
    }

    public static function debug(string $message): void
    {
        self::write('log_debug', $message);
    }

    /**
     * Log a throwable with its origin, without depending on the exception handler.
     */
    public static function exception(\Throwable $e, string $context = ''): void
    {
        $message = sprintf(
            '%s%s: %s in %s:%d',
            $context !== '' ? $context . ' — ' : '',
            $e::class,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        );

        self::error($message);
    }

    private static function write(string $method, string $message): void
    {
        // Preferred: the configured logger service, which honours the app's log
        // path and formatting.
        try {
            if (function_exists('logger')) {
                $logger = logger();

                if (method_exists($logger, $method)) {
                    $logger->{$method}($message);

                    return;
                }
            }
        } catch (\Throwable) {
            // Fall through.
        }

        // Next: the logger singleton directly, which needs no container.
        try {
            $logger = \Components\Logger::instance();

            if (method_exists($logger, $method)) {
                $logger->{$method}($message);

                return;
            }
        } catch (\Throwable) {
            // Fall through.
        }

        // Last resort: PHP's own error log. Always available, never throws.
        error_log($message);
    }
}
