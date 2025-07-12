<?php

ob_start();

define('ROOT_DIR', realpath(__DIR__) . DIRECTORY_SEPARATOR);
define('APP_NAME', "SimplePHP");

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

require_once __DIR__ . '/env.php';
require_once __DIR__ . '/systems/hooks.php';

define('ENVIRONMENT', $config['environment'] ?? 'development');
define('REDIRECT_LOGIN', '?_rp=login');
define('REDIRECT_403', 'views/errors/general_error.php');
define('REDIRECT_404', 'views/errors/404.php');

/*
 *---------------------------------------------------------------
 * ERROR REPORTING
 *---------------------------------------------------------------
 *
 * Different environments will require different levels of error reporting.
 * By default development will show errors but testing and live will hide them.
 */
switch (ENVIRONMENT) {
    case 'development':
        error_reporting(-1);
        ini_set('display_errors', 1);
        break;

    case 'testing':
    case 'production':
        ini_set('display_errors', 0);
        if (version_compare(PHP_VERSION, '5.3', '>=')) {
            error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_STRICT & ~E_USER_NOTICE & ~E_USER_DEPRECATED);
        } else {
            error_reporting(E_ALL & ~E_NOTICE & ~E_STRICT & ~E_USER_NOTICE);
        }
        break;

    default:
        header('HTTP/1.1 503 Service Unavailable.', TRUE, 503);
        echo 'The application environment is not set correctly.';
        exit(1); // EXIT_ERROR
}

// Start session only if it hasn't been started already
if (session_status() === PHP_SESSION_NONE) {
    session_start();
    if (!isset($_SESSION['last_regeneration']) || (time() - $_SESSION['last_regeneration']) > 300) {
        session_regenerate_id(true);
        $_SESSION['last_regeneration'] = time();
    }
}

define('BASE_URL', getProjectBaseUrl());
define('APP_DIR', basename(BASE_URL));
define('APP_ENV', ENVIRONMENT);

$_ENV['APP_ENV'] = APP_ENV;

/*
|--------------------------------------------------------------------------
| HELPER FUNCTIONS
|--------------------------------------------------------------------------
*/

loadHelperFiles();

/*
|--------------------------------------------------------------------------
| MENU SIDEBAR 
|--------------------------------------------------------------------------
*/

$menuList = [
    'dashboard' => [
        'desc' => 'Dashboard',
        'url' => base_url("?_rp=dashboard"),
        'file' => 'views/dashboard/admin.php',
        'icon' => 'tf-icons bx bx-home-smile',
        'permission' => null,
        'authenticate' => true,
        'subpage' => [],
    ],
    'directory' => [
        'desc' => 'Directory',
        'url' => base_url("?_rp=directory"),
        'file' => 'views/directory/users.php',
        'icon' => 'tf-icons bx bx-user',
        'permission' => 'user-view',
        'authenticate' => true,
        'subpage' => [],
    ],
    'rbac' => [
        'desc' => 'App Management',
        'url' => 'javascript:void(0);',
        'icon' => 'tf-icons bx bx-shield-quarter',
        'permission' => 'management-view',
        'subpage' => [
            'roles' => [
                'desc' => 'Roles',
                'url' => base_url("?_rp=rbac&_sp=roles"),
                'file' => 'views/rbac/roles.php',
                'permission' => 'rbac-roles-view',
                'authenticate' => true,
            ],
            'email' => [
                'desc' => 'Email Template',
                'url' => base_url("?_rp=rbac&_sp=email"),
                'file' => 'views/rbac/emailTemplate.php',
                'permission' => 'rbac-email-view',
                'authenticate' => true,
            ],
            // 'abilities' => [
            //     'desc' => 'Abilities',
            //     'url' => 'javascript:void(0);', // No specific page yet
            //     'file' => 'views/rbac/abilities.php',
            //     'permission' => null,
            //     'authenticate' => true,
            // ]
        ],
    ],
];

$redirectAuth = $menuList['dashboard']['url']; // Default redirect after login, can be changed as needed

// Start connection to database, all configuration in env.php
require_once __DIR__ . '/systems/app.php';

ob_end_flush();
