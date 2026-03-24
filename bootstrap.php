<?php

ob_start();

define('ROOT_DIR', realpath(__DIR__) . DIRECTORY_SEPARATOR);
define('APP_NAME', "MythPHP");

define('REDIRECT_LOGIN', 'login');
define('REDIRECT_403', 'app/views/errors/general_error.php');
define('REDIRECT_404', 'app/views/errors/404.php');

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

require_once __DIR__ . '/systems/hooks.php';

if (!class_exists('Myth', false)) {
    class_alias(\Core\Console\Myth::class, 'Myth');
}

/*
|--------------------------------------------------------------------------
| LOAD CONFIG FILES
|--------------------------------------------------------------------------
*/
foreach (glob(__DIR__ . '/app/config/*.php') as $file) {
    try {
        if (is_readable($file)) {
            $included = include_once $file;

            // Support config files that return arrays (e.g., cache.php)
            if (is_array($included)) {
                $key = pathinfo($file, PATHINFO_FILENAME);
                if (!isset($config[$key]) || !is_array($config[$key])) {
                    $config[$key] = [];
                }
                $config[$key] = array_replace_recursive($config[$key], $included);
            }
        } else {
            throw new Exception("File not readable: $file");
        }
    } catch (Exception $e) {
        die("Error: Unable to resolve file path for $file. " . $e->getMessage());
    }
}

define('ENVIRONMENT', $config['environment'] ?? 'development');

// Apply security/performance presets for the current environment.
if (!function_exists('applyConfigOverrides')) {
    function applyConfigOverrides(array &$target, array $overrides): void
    {
        foreach ($overrides as $key => $value) {
            if (is_array($value) && isset($target[$key]) && is_array($target[$key])) {
                applyConfigOverrides($target[$key], $value);
                continue;
            }

            $target[$key] = $value;
        }
    }
}

$environmentPresets = $config['security']['presets'][ENVIRONMENT] ?? [];
if (is_array($environmentPresets) && !empty($environmentPresets)) {
    foreach ($environmentPresets as $topLevelSection => $sectionOverrides) {
        if (!is_array($sectionOverrides)) {
            continue;
        }

        if (isset($sectionOverrides[$topLevelSection]) && is_array($sectionOverrides[$topLevelSection])) {
            $sectionOverrides = $sectionOverrides[$topLevelSection];
        }

        if (!isset($config[$topLevelSection]) || !is_array($config[$topLevelSection])) {
            $config[$topLevelSection] = [];
        }

        applyConfigOverrides($config[$topLevelSection], $sectionOverrides);
    }
}

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
        error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_STRICT & ~E_USER_NOTICE & ~E_USER_DEPRECATED);
        break;

    default:
        header('HTTP/1.1 503 Service Unavailable.', TRUE, 503);
        echo 'The application environment is not set correctly.';
        exit(1); // EXIT_ERROR
}

// Harden session configuration before starting
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure', ENVIRONMENT === 'production' ? '1' : '0');
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');

// Only set session ID settings on PHP < 8.4 (deprecated in 8.4)
if (PHP_VERSION_ID < 80400) {
    ini_set('session.sid_length', '48');
    ini_set('session.sid_bits_per_character', '6');
}

// Start session only if it hasn't been started already
if (session_status() === PHP_SESSION_NONE) {
    session_start();
    // Set regeneration timer on new sessions
    if (!isset($_SESSION['last_regeneration'])) {
        $_SESSION['last_regeneration'] = time();
    }
}

// Regenerate session ID periodically to prevent session fixation
if (
    !empty($config['sess_regenerate_destroy'])
    && session_status() === PHP_SESSION_ACTIVE
    && (!isset($_SESSION['last_regeneration']) || (time() - $_SESSION['last_regeneration']) > ($config['sess_time_to_update'] ?? 300))
) {
    session_regenerate_id(true);
    $_SESSION['last_regeneration'] = time();
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
            'url' => url('dashboard'),
            'file' => 'app/views/dashboard/admin.php',
            'icon' => 'tf-icons bx bx-home-smile',
            'permission' => null,
            'authenticate' => true,
            'active' => true,
            'subpage' => [],
        ],
        'directory' => [
            'desc' => 'Directory',
            'url' => url('directory'),
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
                    'url' => url('rbac/roles'),
                    'file' => 'app/views/rbac/roles.php',
                    'permission' => 'rbac-roles-view',
                    'active' => true,
                    'authenticate' => true,
                ],
                'email' => [
                    'desc' => 'Email Template',
                    'url' => url('rbac/email'),
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

// Start connection to database, all configuration in app/config/database.php
require_once __DIR__ . '/systems/app.php';

ob_end_flush();
