<?php

global $config;

/*
|--------------------------------------------------------------------------
| ENVIRONMENT CONFIGURATION
|--------------------------------------------------------------------------
*/
$config['environment'] = 'development'; // development, staging, production

/*
|--------------------------------------------------------------------------
| TIMEZONE CONFIGURATION
|--------------------------------------------------------------------------
*/
$config['timezone'] = 'Asia/Kuala_Lumpur'; 

/*
|--------------------------------------------------------------------------
| SECURITY
|--------------------------------------------------------------------------
*/
$config['security'] = [
    'throttle_request'   => false,
    'xss_request'        => true,
    'permission_request' => false,
];

/*
|--------------------------------------------------------------------------
| DEBUG CONFIGURATION
|--------------------------------------------------------------------------
*/
$config['error_debug'] = true; // set true or false
$config['error_log_path'] = 'logs/database/error.log';

/*
|--------------------------------------------------------------------------
| MIDDLEWARE
|--------------------------------------------------------------------------
*/
$config['middleware'] = [
    'XMLHttpRequestMiddleware',
    'DynamicModalRequestMiddleware',
];

/*
|--------------------------------------------------------------------------
| Database
|--------------------------------------------------------------------------
*/
$config['db'] = [
    'default' => [
        'development' => [
            'driver' => 'mysql',
            'host' => 'localhost',
            'username' => 'root',
            'password' => '',
            'database' => 'example_db',
            'port' => '3306',
            'charset' => 'utf8mb4',
        ],
        'staging' => [
            'driver' => 'mysql',
            'host' => 'localhost',
            'username' => 'root',
            'password' => '',
            'database' => '',
            'port' => '3306',
            'charset' => 'utf8mb4',
        ],
        'production' => [
            'driver' => 'mysql',
            'host' => '',
            'username' => 'root',
            'password' => '',
            'database' => '',
            'port' => '3306',
            'charset' => 'utf8mb4',
        ]
    ],

    // 'slave' => [
    //     'development' => [
    //         'driver' => 'mysql',
    //         'host' => '127.0.0.1',
    //         'username' => 'root',
    //         'password' => '',
    //         'database' => '',
    //         'port' => '3306',
    //         'charset' => 'utf8mb4',
    //     ],
    //     'staging' => [
    //         'driver' => 'mysql',
    //         'host' => '127.0.0.1',
    //         'username' => 'root',
    //         'password' => '',
    //         'database' => '',
    //         'port' => '3306',
    //         'charset' => 'utf8mb4',
    //     ],
    //     'production' => [
    //         'driver' => 'mysql',
    //         'host' => '127.0.0.1',
    //         'username' => 'root',
    //         'password' => '',
    //         'database' => '',
    //         'port' => '3306',
    //         'charset' => 'utf8mb4',
    //     ]
    // ]
];

/*
|--------------------------------------------------------------------------
| Mailer
|--------------------------------------------------------------------------
*/
$config['mail'] = [
    'driver' => 'smtp', // smtp 
    'host' => 'smtp.gmail.com',
    'port' => 587,
    'username' => '',
    'password' => '',
    'encryption' => 'TLS',
    'from_email' => '',
    'from_name' => '',
    'debug' => TRUE, // TRUE/FALSE, use for smtp driver only
];
