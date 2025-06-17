<?php

use Core\Database\Database;
use Components\Logger;
use Components\Debug;

global $config, $dbObject, $debug, $logger;

if (!empty($config['timezone']))
    date_default_timezone_set($config['timezone']);

// Initialize Debug and Logger instances
$debug = new Debug();
$logger = new Logger(__DIR__ . '/../' . $config['error_log_path']);

/*
|--------------------------------------------------------------------------
| REQUEST 
|--------------------------------------------------------------------------
*/

if (!function_exists('request')) {
    function request()
    {
        return new \Components\Request();
    }
}

/*
|--------------------------------------------------------------------------
| DATABASE CONNECTION
|--------------------------------------------------------------------------
*/

// Set the connection to the database
try {
    $dbConfig = $config['db'];
    $environments = ['development', 'staging', 'production'];
    $dbObj = [];

    // Check if current environment is one of the allowed environments
    if (in_array(ENVIRONMENT, $environments)) {
        // Loop through all database connection types (default, slave, slave2, etc.)
        foreach ($dbConfig as $connectionName => $envConfigs) {
            if (!isset($dbObj[$connectionName]) && isset($envConfigs[ENVIRONMENT]) && is_array($envConfigs[ENVIRONMENT])) {
                $dbObj[$connectionName] = new Database(strtolower($envConfigs[ENVIRONMENT]['driver']));
            }

            // Register the connection for the current environment
            $dbObj[$connectionName]->addConnection($connectionName, $envConfigs[ENVIRONMENT]);
        }
    } else {
        $message = "Environment '" . ENVIRONMENT . "' is not recognized. Please check your configuration.";
        // If the environment is not recognized, log an error
        $logger->log_error($message);
        die($message);
    }

    $dbObject = $dbObj;
} catch (Exception $e) {
    $logger->logException('Connection : Failed to connect to database. :' . $e->getMessage());
}

if (!function_exists('db')) {
    function db($conn = 'default')
    {
        global $dbObject, $logger;

        $conn_db = null;

        if (!isset($dbObject) || empty($dbObject)) {
            $logger->log('Connection : Database object is not initialized.', Logger::LOG_LEVEL_ERROR);
            return null;
        }

        try {
            $connectionName = strtolower($conn);
            $conn_db = $dbObject[$connectionName]->connect($connectionName);
        } catch (Exception $e) {
            $logger->logException($e);
        }

        return $conn_db;
    }
}

/*
|--------------------------------------------------------------------------
| MIDDLEWARE
|--------------------------------------------------------------------------
*/

if (!function_exists('run_middlewares')) {
    function run_middlewares(array $middlewares, $args = null)
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

run_middlewares($config['middleware']);
