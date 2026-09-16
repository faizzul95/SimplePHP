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

    /*
    |--------------------------------------------------------------------------
    | Stampede Protection
    |--------------------------------------------------------------------------
    |
    | When a hot key expires, every request that arrives before it is rewritten
    | is a cache miss, and without this they all run the callback at once — which
    | on an expensive query is how an expiring key takes the database with it.
    |
    | One caller takes the lock and computes; the rest poll for up to wait_ms and
    | then compute anyway rather than hang. lock_seconds should exceed how long
    | the slowest cached callback takes, or a second caller starts over while the
    | first is still working.
    |
    | Turn this off if every cached callback is cheap and the extra round trips
    | cost more than the duplicated work.
    */
    'stampede' => [
        'enabled'      => (bool) env('CACHE_STAMPEDE_PROTECTION', true),
        'lock_seconds' => (int) env('CACHE_STAMPEDE_LOCK_SECONDS', 10),
        'wait_ms'      => (int) env('CACHE_STAMPEDE_WAIT_MS', 250),
    ],
];
