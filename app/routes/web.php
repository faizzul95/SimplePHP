<?php

$menuType = request()->input('_mt', 'main');
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

// Handle for main menu list config from bootstrap.php
if (isset($menuList) && is_array($menuList)) {

    $configList = $menuList[$menuType] ?? [];
    $configRoute = [
        'page' => $page ?? null,
        'subpage' => $spage ?? null,
        'desc' => !empty($page) ? (
            !empty($spage) && isset($configList[$page]['subpage'][$spage]['desc'])
            ? $configList[$page]['subpage'][$spage]['desc']
            : ($configList[$page]['desc'] ?? null)
        ) : null,
        'url' => !empty($page) ? (
            !empty($spage) && isset($configList[$page]['subpage'][$spage]['url'])
            ? $configList[$page]['subpage'][$spage]['url']
            : ($configList[$page]['url'] ?? null)
        ) : null,
        'file' => !empty($page) ? (
            !empty($spage) && isset($configList[$page]['subpage'][$spage]['file'])
            ? $configList[$page]['subpage'][$spage]['file']
            : ($configList[$page]['file'] ?? null)
        ) : null,
        'icon' => !empty($page) ? (
            !empty($spage) && isset($configList[$page]['subpage'][$spage]['icon'])
            ? $configList[$page]['subpage'][$spage]['icon']
            : ($configList[$page]['icon'] ?? null)
        ) : null,
        'permission' => !empty($page) ? (
            !empty($spage) && isset($configList[$page]['subpage'][$spage]['permission'])
            ? $configList[$page]['subpage'][$spage]['permission']
            : ($configList[$page]['permission'] ?? null)
        ) : null,
        'active' => !empty($page) ? (
            // First check if main page is active
            ($configList[$page]['active'] ?? false) ? (
                // If main page is active, then check subpage if it exists
                !empty($spage) && isset($configList[$page]['subpage'][$spage]['active'])
                ? $configList[$page]['subpage'][$spage]['active']
                : true
            ) : false // If main page is not active, force all to false
        ) : false,
        'authenticate' => !empty($page) ? (
            !empty($spage) && isset($configList[$page]['subpage'][$spage]['authenticate'])
            ? $configList[$page]['subpage'][$spage]['authenticate']
            : ($configList[$page]['authenticate'] ?? false)
        ) : false,
        'mainDesc' => !empty($spage) && isset($configList[$page]['subpage'][$spage]) && isset($configList[$page]['desc'])
            ? $configList[$page]['desc']
            : null
    ];
}

$router = new \Components\PageRouter($configRoute);
$router->route();
