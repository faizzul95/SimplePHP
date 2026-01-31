<?php

use Core\Database\Database;
use Core\Database\QueryCache;

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
            // Skip non-connection config keys like 'cache'
            if ($connectionName === 'cache' || !is_array($envConfigs) || !isset($envConfigs[ENVIRONMENT])) {
                continue;
            }
            
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
    
    // Enable/disable query profiling based on config
    if (!empty($config['db']['profiling']['enabled'])) {
        foreach ($dbObject as $conn) {
            $conn->setProfilingEnabled(true);
        }
    }
    
    // Initialize QueryCache with config
    if (!empty($config['db']['cache']['path'])) {
        QueryCache::init($config['db']['cache']['path']);
    } else {
        QueryCache::init(); // Use default path
    }
    
    // Disable cache globally if config says so
    if (empty($config['db']['cache']['enabled'])) {
        QueryCache::disable();
    }
} catch (Exception $e) {
    $logger->logException('Connection : Failed to connect to database. :' . $e->getMessage());
}

if (!function_exists('db')) {
    function db($conn = 'default')
    {
        global $dbObject, $logger;

        $conn_db = null;

        if (!isset($dbObject) || empty($dbObject)) {
            $logger->log_error('Connection : Database object is not initialized.');
            return null;
        }

        try {
            $connectionName = strtolower($conn);
            $conn_db = $dbObject[$connectionName]->connect($connectionName);
        } catch (Exception $e) {
            $logger->logException($e);
        }

        if (!empty($conn_db)) {
            // This use to load the scope/macro db
            loadScopeMacroDBFunctions(
                $conn_db,
                [], // Put the file name here, Eg : ScopeQueryController.php
                ['ScopeMacroQuery'], // put the folder name here
                '../controllers/', // Base Path for the files or folder will be load. 
                false // The error log message
            );
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
