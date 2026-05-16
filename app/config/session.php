<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Session Driver
    |--------------------------------------------------------------------------
    |
    | Supported: "file", "redis"
    |
    */

    'driver' => (string) env('SESSION_DRIVER', 'file'),

    /*
    |--------------------------------------------------------------------------
    | Session Lifetime
    |--------------------------------------------------------------------------
    |
    | Minutes before the session expires server-side.
    |
    */

    'lifetime' => (int) env('SESSION_LIFETIME', 120),

    /*
    |--------------------------------------------------------------------------
    | File Driver Path
    |--------------------------------------------------------------------------
    |
    | Empty string uses PHP's default session save path.
    |
    */

    'file_path' => (string) env('SESSION_FILE_PATH', ''),

    /*
    |--------------------------------------------------------------------------
    | Fail Open
    |--------------------------------------------------------------------------
    |
    | When true, Redis session bootstrap failures fall back to the native file
    | session handler instead of breaking the request.
    |
    */

    'fail_open' => (bool) env('SESSION_FAIL_OPEN', true),

    'redis' => [
        'host' => (string) env('REDIS_HOST', '127.0.0.1'),
        'port' => (int) env('REDIS_PORT', 6379),
        'password' => env('REDIS_PASSWORD', null),
        'database' => (int) env('REDIS_SESSION_DB', 2),
        'timeout' => (float) env('REDIS_SESSION_TIMEOUT', 2.0),
        'prefix' => (string) env('REDIS_SESSION_PREFIX', 'myth_session:'),
        'lock_ttl' => (int) env('REDIS_SESSION_LOCK_TTL', 10),
        'lock_wait_ms' => (int) env('REDIS_SESSION_LOCK_WAIT_MS', 150),
        'lock_retry_us' => (int) env('REDIS_SESSION_LOCK_RETRY_US', 15000),
    ],

];