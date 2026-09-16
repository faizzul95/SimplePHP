<?php

declare(strict_types=1);

// The ONLY file that should be in the web root.
// All other PHP files live above the public/ directory.

define('ROOT_DIR', dirname(__DIR__) . DIRECTORY_SEPARATOR);

require ROOT_DIR . 'bootstrap.php';

// ─── FrankenPHP Worker Mode (EXPERIMENTAL — off by default) ───────────────────
// When running under FrankenPHP in worker mode, the bootstrap runs ONCE per
// worker process. Each request is then handled by frankenphp_handle_request().
//
// This loop is gated behind MYTH_EXPERIMENTAL_WORKER=1 because per-request
// isolation is incomplete. WorkerState::flush() resets six classes, but the
// framework_service() singletons for auth, csrf, security, logger and feature
// survive the cycle, and session_start() runs once per worker rather than once
// per request. Both mean one request's authenticated identity and session data
// can be served to the next caller on the same worker.
//
// See .dev/10-audit-findings.md#w-01. Do not remove this guard until that
// finding is closed and a Feature test proves isolation between cycles.
//
// Activate (only in a throwaway environment) with MYTH_EXPERIMENTAL_WORKER=1
// plus, in Caddyfile:
//   php_server { worker { file public/index.php; num 4 } }
//
if (
    (bool) env('MYTH_EXPERIMENTAL_WORKER', false)
    && isset($_SERVER['FRANKENPHP_WORKER'])
    && function_exists('frankenphp_handle_request')
) {
    $kernel = new \App\Http\Kernel();

    // FrankenPHP repopulates the superglobals itself, so only the ambient
    // process-level $_SERVER is worth snapshotting as the per-cycle baseline.
    \Core\Server\WorkerState::captureBaseline();

    $maxRequests = (int) ($_SERVER['FRANKENPHP_MAX_REQUESTS'] ?? 500);
    $count = 0;

    while (frankenphp_handle_request(function () use ($kernel): void {
        // MUST be first — clears static caches, the resolved service singletons
        // (Auth caches the authenticated user), the session and the emit flag.
        // $_SERVER is left alone: FrankenPHP has already populated it for this
        // request, unlike the PSR-7 bridge which merges into the previous set.
        \Core\Server\WorkerState::flush(resetSuperglobals: false);

        try {
            maintenance()->handleRequest();
            $request = \Core\Http\Request::capture();
            dispatch_event('request.captured', ['request' => $request]);
            $kernel->handle($request);
        } catch (\Core\Http\ResponseEmitted $emitted) {
            // Maintenance mode short-circuits before the kernel runs.
            \Core\Http\Emitter::send($emitted->response());
        } catch (\Throwable $e) {
            if (class_exists(\Core\Exceptions\ExceptionHandler::class)) {
                \Core\Exceptions\ExceptionHandler::handle($e);
            } else {
                http_response_code(500);
                echo '500 - Internal Server Error';
            }
        } finally {
            // Release the session lock at the end of the request rather than at
            // the end of the worker's life.
            \Core\Session\SessionCycle::end();
        }
    })) {
        // Graceful memory-limit restart: exit after N requests so PHP's memory
        // is freed and the worker process is replaced by a fresh one.
        if (++$count >= $maxRequests) {
            break;
        }
    }

    return;
}

// ─── Standard PHP-FPM / CLI Mode ─────────────────────────────────────────────
try {
    maintenance()->handleRequest();
    $request = \Core\Http\Request::capture();
    dispatch_event('request.captured', ['request' => $request]);
    $kernel = new \App\Http\Kernel();
    $kernel->handle($request);
} catch (\Throwable $e) {
    if (class_exists(\Core\Exceptions\ExceptionHandler::class)) {
        \Core\Exceptions\ExceptionHandler::handle($e);
        exit(1);
    }
    http_response_code(500);
    echo '500 - Internal Server Error';
    exit(1);
}
