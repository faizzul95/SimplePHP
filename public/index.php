<?php

declare(strict_types=1);

// The ONLY file that should be in the web root.
// All other PHP files live above the public/ directory.

define('ROOT_DIR', dirname(__DIR__) . DIRECTORY_SEPARATOR);

require ROOT_DIR . 'bootstrap.php';

// ─── FrankenPHP Worker Mode ───────────────────────────────────────────────────
// When running under FrankenPHP in worker mode, the bootstrap runs ONCE per
// worker process. Each request is then handled by frankenphp_handle_request().
// WorkerState::flush() resets all per-request static state between cycles.
//
// Activate in Caddyfile:
//   php_server { worker { file public/index.php; num 4 } }
//
if (isset($_SERVER['FRANKENPHP_WORKER']) && function_exists('frankenphp_handle_request')) {
    $kernel = new \App\Http\Kernel();

    $maxRequests = (int) ($_SERVER['FRANKENPHP_MAX_REQUESTS'] ?? 500);
    $count = 0;

    while (frankenphp_handle_request(function () use ($kernel): void {
        // MUST be first — flushes all per-request statics from the previous cycle
        \Core\Server\WorkerState::flush();

        try {
            maintenance()->handleRequest();
            $request = \Core\Http\Request::capture();
            dispatch_event('request.captured', ['request' => $request]);
            $kernel->handle($request);
        } catch (\Throwable $e) {
            if (class_exists(\Core\Exceptions\ExceptionHandler::class)) {
                \Core\Exceptions\ExceptionHandler::handle($e);
            } else {
                http_response_code(500);
                echo '500 - Internal Server Error';
            }
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
