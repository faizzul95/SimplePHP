<?php

try {
    require_once __DIR__ . '/bootstrap.php';

    // Maintenance mode runs before routing, so its short-circuit lands here rather
    // than in the Kernel. It throws ResponseEmitted instead of exiting so worker
    // SAPIs survive a maintenance window.
    try {
        maintenance()->handleRequest();
    } catch (\Core\Http\ResponseEmitted $emitted) {
        \Core\Http\Emitter::send($emitted->response());
        return;
    }

    $request = \Core\Http\Request::capture();
    dispatch_event('request.captured', ['request' => $request]);
    $kernel = new \App\Http\Kernel();
    $kernel->handle($request);
} catch (\Throwable $e) {
    if (class_exists(\Core\Exceptions\ExceptionHandler::class)) {
        \Core\Exceptions\ExceptionHandler::handle($e);
        exit(1);
    }

    \Components\Logger::instance()->log_error('Router initialization error: ' . $e->getMessage());

    if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg' && !headers_sent()) {
        http_response_code(500);
    }

    echo '500 - Internal Server Error';
    exit(1);
}
