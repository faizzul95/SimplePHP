<?php

require_once 'init.php';

// Initialize and run the router
try {
    $page = request()->input('_p');
    $spage = request()->input('_sp');

    if (empty($page)) {
        if (!isLogin(false))
            redirect('?_p=' . REDIRECT_LOGIN, true);
        else
            header('Location: ' . $redirectAuth, true, 301);
        exit;
    }

    // default value
    $configRoute = [
        'page' => null,
        'subpage' => null,
        'permission' => null,
        'desc' => null,
        'url' => null,
        'file' => null,
        'icon' => null,
        'active' => false,
        'authenticate' => false
    ];

    // Handle for main menu list config from init.php
    if (isset($menuList) && is_array($menuList)) {
        $menuList = $menuList['main'];
        $configRoute = [
            'page' => $page ?? null,
            'subpage' => $spage ?? null,
            'desc' => !empty($page) ? (
                !empty($spage) && isset($menuList[$page]['subpage'][$spage]['desc'])
                ? $menuList[$page]['subpage'][$spage]['desc']
                : ($menuList[$page]['desc'] ?? null)
            ) : null,
            'url' => !empty($page) ? (
                !empty($spage) && isset($menuList[$page]['subpage'][$spage]['url'])
                ? $menuList[$page]['subpage'][$spage]['url']
                : ($menuList[$page]['url'] ?? null)
            ) : null,
            'file' => !empty($page) ? (
                !empty($spage) && isset($menuList[$page]['subpage'][$spage]['file'])
                ? $menuList[$page]['subpage'][$spage]['file']
                : ($menuList[$page]['file'] ?? null)
            ) : null,
            'icon' => !empty($page) ? (
                !empty($spage) && isset($menuList[$page]['subpage'][$spage]['icon'])
                ? $menuList[$page]['subpage'][$spage]['icon']
                : ($menuList[$page]['icon'] ?? null)
            ) : null,
            'permission' => !empty($page) ? (
                !empty($spage) && isset($menuList[$page]['subpage'][$spage]['permission'])
                ? $menuList[$page]['subpage'][$spage]['permission']
                : ($menuList[$page]['permission'] ?? null)
            ) : null,
            'active' => !empty($page) ? (
                // First check if main page is active
                ($menuList[$page]['active'] ?? false) ? (
                    // If main page is active, then check subpage if it exists
                    !empty($spage) && isset($menuList[$page]['subpage'][$spage]['active'])
                    ? $menuList[$page]['subpage'][$spage]['active']
                    : true
                ) : false // If main page is not active, force all to false
            ) : false,
            'authenticate' => !empty($page) ? (
                !empty($spage) && isset($menuList[$page]['subpage'][$spage]['authenticate'])
                ? $menuList[$page]['subpage'][$spage]['authenticate']
                : ($menuList[$page]['authenticate'] ?? false)
            ) : false,
        ];
    }

    $router = new \Components\PageRouter($configRoute);
    $router->route();
} catch (Exception $e) {
    error_log("Router initialization error: " . $e->getMessage());
    http_response_code(500);
    echo "500 - Internal Server Error";
}
