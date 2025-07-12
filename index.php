<?php

require_once 'init.php';

// Initialize and run the router
try {
    
    if (!isset($menuList) || !is_array($menuList)) {
        throw new Exception("Menu list not properly initialized, Please configure in init files.");
    }

    $router = new \Components\PageRouter($menuList);
    $router->route();

} catch (Exception $e) {
    error_log("Router initialization error: " . $e->getMessage());
    http_response_code(500);
    echo "500 - Internal Server Error";
}
