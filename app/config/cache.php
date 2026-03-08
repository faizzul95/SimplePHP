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

    'prefix' => 'simplephp_',
];
