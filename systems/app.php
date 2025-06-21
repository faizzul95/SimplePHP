<?php

use Core\Database\Database;

global $config, $dbObject, $logger;

$logger = logger();

if (!empty($config['timezone'])) {
    date_default_timezone_set($config['timezone']);
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

loadMiddlewaresFiles($config['middleware']);
