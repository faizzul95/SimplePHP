<?php

require_once 'bootstrap.php';

try {
    $request = \Core\Http\Request::capture();
    $kernel = new \App\Http\Kernel();
    $kernel->handle($request);
} catch (Exception $e) {
    error_log("Router initialization error: " . $e->getMessage());
    http_response_code(500);
    echo "500 - Internal Server Error";
}
