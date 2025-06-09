<?php

use Core\Database\Database;
use Components\Logger;
use Components\Debug;

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
global $config, $dbObject, $debug, $logger;

// Initialize Debug and Logger instances
$debug = new Debug();
$logger = new Logger(__DIR__ . '/../logs/database/error.log');

// Set the connection to the database
try {
    $dbObj = new Database('mysql');
    $dbConfig = $config['db'];

    $environments = ['development', 'staging', 'production'];

    // Check if current environment is one of the allowed environments
    if (in_array(ENVIRONMENT, $environments)) {
        // Loop through all database connection types (default, slave, slave2, etc.)
        foreach ($dbConfig as $connectionName => $envConfigs) {
            // Register the connection for the current environment
            if (isset($envConfigs[ENVIRONMENT]) && is_array($envConfigs[ENVIRONMENT])) {
                $dbObj->addConnection($connectionName, $envConfigs[ENVIRONMENT]);
            }
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

        if (!isset($dbObject)) {
            $logger->log('Connection : Database object is not initialized.', Logger::LOG_LEVEL_ERROR);
            return null;
        }

        try {
            $conn_db = $dbObject->connect($conn);
        } catch (Exception $e) {
            $logger->logException($e);
        }

        return $conn_db;
    }
}
