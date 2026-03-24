<?php

require_once 'bootstrap.php';

try {
    $request = \Core\Http\Request::capture();
    $kernel = new \App\Http\Kernel();
    $kernel->handle($request);
} catch (Exception|Throwable $e) {
    if (class_exists('\Core\Exceptions\ExceptionHandler')) {
        \Core\Exceptions\ExceptionHandler::handle($e);
    } else {
        error_log("Router initialization error: " . $e->getMessage());
        http_response_code(500);
        echo "500 - Internal Server Error";
    }
}
