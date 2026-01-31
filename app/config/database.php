<?php

/*
|--------------------------------------------------------------------------
| Database
|--------------------------------------------------------------------------
*/

$config['db'] = [
    'profiling' => [
        'enabled' => false,  // Enable/disable query profiling (affects performance)
    ],
    'cache' => [
        'enabled' => false,  // Enable/disable query cache globally
        'ttl' => 120,        // Default cache time in seconds
        'path' => null,      // null = auto (storage/cache/query)
    ],
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
