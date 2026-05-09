<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Cache Store
    |--------------------------------------------------------------------------
    |
    | Supported: "file", "array"
    |   file  – Persistent file-based cache in storage/cache/app
    |   array – In-memory only (lost after request ends)
    |
    */

    'default' => 'file',

    /*
    |--------------------------------------------------------------------------
    | Cache Stores
    |--------------------------------------------------------------------------
    */

    'stores' => [
        'file' => [
            'driver' => 'file',
            'path'   => 'storage/cache/app',
        ],

        'array' => [
            'driver' => 'array',
        ],

        'redis' => [
            'driver'   => 'redis',
            'host'     => env('REDIS_HOST', '127.0.0.1'),
            'port'     => (int) env('REDIS_PORT', 6379),
            'password' => env('REDIS_PASSWORD', null),
            'database' => (int) env('REDIS_CACHE_DB', 1),
            'timeout'  => 2.0,
            'prefix'   => env('REDIS_PREFIX', 'myth:'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Key Prefix
    |--------------------------------------------------------------------------
    |
    | Applied to every cache key to avoid collisions with other
    | applications sharing the same storage directory.
    |
    */

    'prefix' => 'MythPHP_',
];
