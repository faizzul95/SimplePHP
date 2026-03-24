<?php

/*
|--------------------------------------------------------------------------
| Database
|--------------------------------------------------------------------------
*/

$config['db'] = [
    'profiling' => [
        'enabled' => (bool) env('DB_PROFILING_ENABLED', false),  // Enable/disable query profiling (affects performance)
    ],
    'cache' => [
        'enabled' => (bool) env('DB_CACHE_ENABLED', false),  // Enable/disable query cache globally
        'ttl' => (int) env('DB_CACHE_TTL', 120),             // Default cache time in seconds
        'path' => env('DB_CACHE_PATH', null),                // null = auto (storage/cache/query)
    ],
    'default' => [
        'development' => [
            'driver'   => (string) env('DB_CONNECTION', 'mysql'),
            'host'     => (string) env('DB_HOST', 'localhost'),
            'username' => (string) env('DB_USERNAME', 'root'),
            'password' => (string) env('DB_PASSWORD', ''),
            'database' => (string) env('DB_DATABASE', 'example_db'),
            'port'     => (string) env('DB_PORT', '3306'),
            'charset'  => (string) env('DB_CHARSET', 'utf8mb4'),
        ],
        'staging' => [
            'driver'   => (string) env('DB_STAGING_CONNECTION', env('DB_CONNECTION', 'mysql')),
            'host'     => (string) env('DB_STAGING_HOST', env('DB_HOST', 'localhost')),
            'username' => (string) env('DB_STAGING_USERNAME', env('DB_USERNAME', 'root')),
            'password' => (string) env('DB_STAGING_PASSWORD', env('DB_PASSWORD', '')),
            'database' => (string) env('DB_STAGING_DATABASE', env('DB_DATABASE', '')),
            'port'     => (string) env('DB_STAGING_PORT', env('DB_PORT', '3306')),
            'charset'  => (string) env('DB_STAGING_CHARSET', env('DB_CHARSET', 'utf8mb4')),
        ],
        'production' => [
            'driver'   => (string) env('DB_PRODUCTION_CONNECTION', env('DB_CONNECTION', 'mysql')),
            'host'     => (string) env('DB_PRODUCTION_HOST', env('DB_HOST', 'localhost')),
            'username' => (string) env('DB_PRODUCTION_USERNAME', env('DB_USERNAME', 'root')),
            'password' => (string) env('DB_PRODUCTION_PASSWORD', env('DB_PASSWORD', '')),
            'database' => (string) env('DB_PRODUCTION_DATABASE', env('DB_DATABASE', '')),
            'port'     => (string) env('DB_PRODUCTION_PORT', env('DB_PORT', '3306')),
            'charset'  => (string) env('DB_PRODUCTION_CHARSET', env('DB_CHARSET', 'utf8mb4')),
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
