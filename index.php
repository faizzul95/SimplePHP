<?php


require_once 'init.php';

// Initialize and run the router
try {
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    if (strpos($requestUri, '/api/') !== false) {
        require_once 'app/routes/api.php';
    } else {
        require_once 'app/routes/web.php';
    }
} catch (Exception $e) {
    error_log("Router initialization error: " . $e->getMessage());
    http_response_code(500);
    echo "500 - Internal Server Error";
}
