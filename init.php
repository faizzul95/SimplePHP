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
define('REDIRECT_LOGIN', 'login');
define('REDIRECT_403', 'app/views/errors/general_error.php');
define('REDIRECT_404', 'app/views/errors/404.php');

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
} else {
    if ($config['sess_regenerate_destroy'] && (!isset($_SESSION['last_regeneration']) || (time() - $_SESSION['last_regeneration']) > $config['sess_time_to_update'])) {
        session_regenerate_id(true);
        $_SESSION['last_regeneration'] = time();
    }
}

define('BASE_URL', getProjectBaseUrl());
define('APP_DIR', basename(BASE_URL));
define('APP_ENV', ENVIRONMENT);
define('TEMPLATE_DIR', __DIR__ . DIRECTORY_SEPARATOR);

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
    'main' => [
        'dashboard' => [
            'desc' => 'Dashboard',
            'url' => paramUrl(['_p' => "dashboard"], true),
            'file' => 'app/views/dashboard/admin.php',
            'icon' => 'tf-icons bx bx-home-smile',
            'permission' => null,
            'authenticate' => true,
            'active' => true,
            'subpage' => [],
        ],
        'directory' => [
            'desc' => 'Directory',
            'url' => paramUrl(['_p' => "directory"], true),
            'file' => 'app/views/directory/users.php',
            'icon' => 'tf-icons bx bx-user',
            'permission' => 'user-view',
            'authenticate' => true,
            'active' => true,
            'subpage' => [],
        ],
        'rbac' => [
            'desc' => 'App Management',
            'url' => 'javascript:void(0);',
            'icon' => 'tf-icons bx bx-shield-quarter',
            'permission' => 'management-view',
            'active' => true,
            'subpage' => [
                'roles' => [
                    'desc' => 'Roles',
                    'url' => paramUrl(
                        ['_p' => "rbac", '_sp' => "roles"],
                        true
                    ),
                    'file' => 'app/views/rbac/roles.php',
                    'permission' => 'rbac-roles-view',
                    'active' => true,
                    'authenticate' => true,
                ],
                'email' => [
                    'desc' => 'Email Template',
                    'url' => paramUrl(
                        ['_p' => "rbac", '_sp' => "email"],
                        true
                    ),
                    'file' => 'app/views/rbac/emailTemplate.php',
                    'permission' => 'rbac-email-view',
                    'active' => true,
                    'authenticate' => true,
                ]
            ],
        ],
    ]
];

$redirectAuth = $menuList['main']['dashboard']['url']; // Default redirect after login, can be changed as needed

// Start connection to database, all configuration in env.php
require_once __DIR__ . '/systems/app.php';

ob_end_flush();
