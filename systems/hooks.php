<?php

/*
|--------------------------------------------------------------------------
| GET PROJECT BASE URL
|--------------------------------------------------------------------------
*/

if (!function_exists('getProjectBaseUrl')) {
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
}

/*
|--------------------------------------------------------------------------
| LOAD ALL COMPONENTS SYSTEMS
|--------------------------------------------------------------------------
*/

spl_autoload_register(function ($class) {
    $baseDir = __DIR__ . '/../systems/'; // root of class files
    $classPath = str_replace('\\', DIRECTORY_SEPARATOR, $class);
    $file = $baseDir . $classPath . '.php';

    try {
        if (is_readable($file)) {
            require_once $file;
        } else {
            throw new Exception("File not readable: $file");
        }
    } catch (Exception $e) {
        die("Error: Unable to resolve file path for $file. " . $e->getMessage());
    }
});

/*
|--------------------------------------------------------------------------
| LOAD ALL HELPERS FUNCTIONS
|--------------------------------------------------------------------------
*/

if (!function_exists('loadHelperFiles')) {
    function loadHelperFiles()
    {
        $helpersDir = __DIR__ . '/../public/helpers/'; // root of helper files

        // Get all PHP files in the General folder
        $helperFiles = glob($helpersDir . '*.php');

        foreach ($helperFiles as $file) {
            try {
                if (is_readable($file)) {
                    include_once $file;
                } else {
                    throw new Exception("File not readable: $file");
                }
            } catch (Exception $e) {
                die("Error: Unable to resolve file path for $file. " . $e->getMessage());
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| LOAD ALL MIDDLEWARES
|--------------------------------------------------------------------------
*/

if (!function_exists('loadMiddlewaresFiles')) {
    function loadMiddlewaresFiles(array $middlewares, $args = null)
    {
        foreach ($middlewares as $middleware) {
            $class = "Middleware\\$middleware";
            if (class_exists($class)) {
                $instance = new $class();
                if (method_exists($instance, 'run')) {
                    $instance->run($args);
                }
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| DEBUG COMPONENT 
|--------------------------------------------------------------------------
*/

if (!function_exists('debug')) {
    function debug()
    {
        return new \Components\Debug();
    }
}

/*
|--------------------------------------------------------------------------
| LOGGER COMPONENT 
|--------------------------------------------------------------------------
*/

if (!function_exists('logger')) {
    function logger()
    {
        global $config;
        return new \Components\Logger(__DIR__ . '/../' . $config['error_log_path']);
    }
}

/*
|--------------------------------------------------------------------------
| REQUEST COMPONENT
|--------------------------------------------------------------------------
*/

if (!function_exists('request')) {
    function request()
    {
        return new \Components\Request();
    }
}
