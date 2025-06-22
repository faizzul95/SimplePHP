<?php

ob_start();

require_once __DIR__ . '/env.php';
require_once __DIR__ . '/systems/hooks.php';

define('ENVIRONMENT', $config['environment'] ?? 'development');
define('REDIRECT_LOGIN', 'views/auth/login.php');
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
switch (ENVIRONMENT)
{
	case 'development':
		error_reporting(-1);
		ini_set('display_errors', 1);
	break;

	case 'testing':
	case 'production':
		ini_set('display_errors', 0);
		if (version_compare(PHP_VERSION, '5.3', '>='))
		{
			error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_STRICT & ~E_USER_NOTICE & ~E_USER_DEPRECATED);
		}
		else
		{
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

define('ROOT_DIR', realpath(__DIR__) . DIRECTORY_SEPARATOR);
define('BASE_URL', getProjectBaseUrl());
define('APP_NAME', "SimplePHP");
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
| MENU SIDEBAR (Admin)
|--------------------------------------------------------------------------
*/

$menuList = [
    [
        'currentPage' => 'dashboard', // use in each file (without whitespace or any character)
        'desc' => 'Dashboard',
        'url' => url('views/dashboard/admin.php'),
        'icon' => 'tf-icons bx bx-home-smile',
        'permission' => null,
        'subpage' => [],
    ],
    [
        'currentPage' => 'directory', // use in each file (without whitespace or any character)
        'desc' => 'Directory',
        'url' => url('views/directory/users.php'),
        'icon' => 'tf-icons bx bx-user',
        'permission' => 'user-view',
        'subpage' => [],
    ],
    [
        'currentPage' => 'rbac', // use in each file (without whitespace or any character)
        'desc' => 'Roles Management',
        'url' => 'javascript:void(0);',
        'icon' => 'tf-icons bx bx-shield-quarter',
        'permission' => 'management-view',
        'subpage' => [
            [
                'currentSubPage' => 'roles', // use in each file (without whitespace or any character)
                'desc' => 'Roles',
                'url' => url('views/rbac/roles.php'),
                'permission' => null,
            ],
            [
                'currentSubPage' => 'abilities', // use in each file (without whitespace or any character)
                'desc' => 'Abilities',
                'url' => 'javascript:void(0);', // No specific page yet
                'permission' => null,
            ]
        ],
    ],
];

$redirectAuth = $menuList[0]['url']; // Default redirect after login, can be changed as needed

// Start connection to database, all configuration in env.php
require_once __DIR__ . '/systems/app.php';

ob_end_flush();