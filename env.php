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

$config['csrf_protection']    = false;
$config['csrf_token_name']    = 'csrf_token';
$config['csrf_cookie_name']   = 'csrf_cookie';
$config['csrf_expire']        = 7200;
$config['csrf_regenerate']    = true;
$config['csrf_include_uris']  = [
    'UserController\save',
    // 'RoleController\save',
    // 'MasterEmailTemplateController\save',
    // 'UploadController\uploadImageCropper',
];
$config['csrf_secure_cookie'] = true;
$config['csrf_httponly']      = false;
$config['csrf_samesite']      = 'Strict';

/*
|--------------------------------------------------------------------------
| DEBUG CONFIGURATION
|--------------------------------------------------------------------------
*/
$config['error_debug']    = true; // set true or false
$config['error_log_path'] = 'logs/error.log';

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
            'driver'   => 'mysql',
            'host'     => 'localhost',
            'username' => 'root',
            'password' => '',
            'database' => 'example_db',
            'port'     => '3306',
            'charset'  => 'utf8mb4',
        ],
        'staging' => [
            'driver'   => 'mysql',
            'host'     => 'localhost',
            'username' => 'root',
            'password' => '',
            'database' => '',
            'port'     => '3306',
            'charset'  => 'utf8mb4',
        ],
        'production' => [
            'driver'   => 'mysql',
            'host'     => 'localhost',
            'username' => 'root',
            'password' => '',
            'database' => '',
            'port'     => '3306',
            'charset'  => 'utf8mb4',
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
| SESSIONS
|--------------------------------------------------------------------------
*/
$config['sess_regenerate_destroy'] = TRUE;
$config['sess_time_to_update'] = 300;

/*
|--------------------------------------------------------------------------
| API
|--------------------------------------------------------------------------
*/
$config['api'] =  [
    'cors' => [
        'allow_origin' => ['*'],
        'allow_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
        'allow_headers' => ['Content-Type', 'Authorization', 'X-Requested-With'],
    ],
    'ip_whitelist' => ['127.0.0.1', '::1'], // Localhost IPs
    'url_whitelist' => [
        '/v1/auth/login'
    ],
    'rate_limit' => [
        'enabled' => true,
        'max_requests' => 60,
        'window_seconds' => 60
    ],
    'auth' => [
        'required' => true,
    ],
    'token_table' => 'users_access_tokens',
    'rate_limit_table' => 'api_rate_limits',
    'log_errors' => true
];

/*
|--------------------------------------------------------------------------
| Mailer
|--------------------------------------------------------------------------
*/
$config['mail'] = [
    'driver'     => 'smtp',
    'host'       => 'smtp.gmail.com',
    'port'       => 587,
    'username'   => '',
    'password'   => '',  // need to set here https://myaccount.google.com/apppasswords
    'encryption' => 'tls',
    'from_email' => '',
    'from_name'  => APP_NAME,
    'debug'      => false,
];
