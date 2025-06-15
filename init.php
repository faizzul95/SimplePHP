<?php

require_once __DIR__ . '/env.php';

// Set error reporting
ini_set('display_errors', $config['error_debug'] ? 1 : 0);
ini_set('display_startup_errors', $config['error_debug'] ? 1 : 0);
error_reporting($config['error_debug'] ? E_ALL : 0);

// Start session only if it hasn't been started already
if (session_status() === PHP_SESSION_NONE) {
    session_start();
    session_regenerate_id(true); // Regenerate session to prevent fixation
}

define('ENVIRONMENT', $config['environment'] ?? 'development');
define('REDIRECT_LOGIN', 'views/auth/login.php');
define('REDIRECT_403', 'views/errors/general_error.php');

function getProjectBaseUrl()
{
    $protocol = isset($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];

    // Get the first directory from the script path
    $scriptDir = dirname($_SERVER['SCRIPT_NAME']);
    $pathSegments = explode('/', trim($scriptDir, '/'));
    $projectFolder = !empty($pathSegments[0]) ? '/' . $pathSegments[0] : '';

    return $protocol . '://' . $host . $projectFolder . '/';
}

define('ROOT_DIR', realpath(__DIR__) . DIRECTORY_SEPARATOR);
define('BASE_URL', getProjectBaseUrl());
define('APP_NAME', "SimplePHP");
define('APP_DIR', basename(BASE_URL));
define('APP_ENV', ENVIRONMENT);

$_ENV['APP_ENV'] = APP_ENV;

// Keep existing autoload for systems classes if needed
spl_autoload_register(function ($class) {
    $baseDir = __DIR__ . '/systems/'; // root of class files
    $classPath = str_replace('\\', DIRECTORY_SEPARATOR, $class);
    $file = $baseDir . $classPath . '.php';

    try {
        if (file_exists($file) && is_readable($file)) {
            require_once $file;
        } else {
            throw new Exception("File not found or not readable: $file");
        }
    } catch (Exception $e) {
        die("Error: Unable to resolve file path for $file. " . $e->getMessage());
    }
});

// Load all helper files from the helpers folder
function loadHelperFiles()
{
    $helpersDir = __DIR__ . '/public/helpers/'; // root of helper files

    // Get all PHP files in the General folder
    $helperFiles = glob($helpersDir . '*.php');

    foreach ($helperFiles as $file) {
        try {
            if (file_exists($file) && is_readable($file)) {
                include_once $file;
            } else {
                throw new Exception("File not found or not readable: $file");
            }
        } catch (Exception $e) {
            die("Error: Unable to resolve file path for $file. " . $e->getMessage());
        }
    }
}

// Call the function to load all helper files
loadHelperFiles();

// Start connection to database, all configuration in env.php
require_once __DIR__ . '/systems/app.php';

// USE TO ADD NEW MENU AT SIDEBAR
$menuList = [
    [
        'currentPage' => 'dashboard', // use in each file (without whitespace or any character)
        'desc' => 'Dashboard',
        'url' => 'views/dashboard/admin.php',
        'icon' => 'tf-icons bx bx-home-smile',
        'permission' => null,
        'subpage' => [],
    ],
    [
        'currentPage' => 'directory', // use in each file (without whitespace or any character)
        'desc' => 'Directory',
        'url' => 'views/directory/users.php',
        'icon' => 'tf-icons bx bx-user',
        'permission' => null,
        'subpage' => [],
    ],
    [
        'currentPage' => 'config', // use in each file (without whitespace or any character)
        'desc' => 'Roles Management',
        'url' => 'javascript:void(0);',
        'icon' => 'tf-icons bx bx-shield-quarter',
        'permission' => null,
        'subpage' => [
            [
                'currentSubPage' => 'roles', // use in each file (without whitespace or any character)
                'desc' => 'Roles',
                'url' => 'views/rbac/roles.php',
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

/*
|--------------------------------------------------------------------------
| FUNCTION TO USE IN CONTROLLER
|--------------------------------------------------------------------------
*/
if (isAjax()) {
    $action = request()->input('action');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if ($action != 'modal') {
            if (hasData($action) && function_exists($action)) {
                if (request()->detectXss()) {
                    jsonResponse(['code' => 422, 'message' => 'Protection against <b><i> Cross-site scripting (XSS) </i></b> activated!'], 422);
                }
                call_user_func($action, request()->unsafe()->all());
            } else if (!hasData($action)) {
                dd("action does not define in callApi.");
            } else if (function_exists($action)) {
                dd("Function '$action' does not exist");
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| LOAD MODAL DYNAMIC
|--------------------------------------------------------------------------
*/
if (hasData($_POST, 'fileName')) {
    $filename = request()->input('fileName');
    $data = hasData($_POST, 'dataArray', true);
    $filePath = $filename;

    // dd($filePath);
    if (file_exists($filePath)) {
        $opts = [
            'http' => [
                'method' => 'POST',
                'header' => 'Content-Type: application/x-www-form-urlencoded',
                'content' => hasData($data) ? http_build_query($data) : null,
            ],
        ];

        $context = stream_context_create($opts);
        echo file_get_contents($filePath, false, $context);
    } else {
        // echo "File does not exist.";
        echo '<div class="alert alert-danger" role="alert">
                File <b><i>' . $filePath . '</i></b> does not exist.
               </div>';
    }
}
