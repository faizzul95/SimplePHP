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
    'performance' => [
        'slow_query' => [
            'enabled' => (bool) env('DB_SLOW_QUERY_LOG_ENABLED', true),
            'threshold_ms' => (int) env('DB_SLOW_QUERY_THRESHOLD_MS', 750),
            'alert_ms' => (int) env('DB_SLOW_QUERY_ALERT_MS', 2000),
        ],
        'timeouts' => [
            'enabled' => (bool) env('DB_TIMEOUTS_ENABLED', true),
            'statement_timeout_ms' => (int) env('DB_STATEMENT_TIMEOUT_MS', 15000),
            'lock_wait_timeout_seconds' => (int) env('DB_LOCK_WAIT_TIMEOUT_SECONDS', 15),
        ],
    ],
    'retry' => [
        'enabled' => (bool) env('DB_RETRY_ENABLED', true),
        'attempts' => (int) env('DB_RETRY_ATTEMPTS', 3),
        'delay_ms' => (int) env('DB_RETRY_DELAY_MS', 50),
    ],
    'cache' => [
        'enabled' => (bool) env('DB_CACHE_ENABLED', false),  // Enable/disable query cache globally
        'ttl' => (int) env('DB_CACHE_TTL', 120),             // Default cache time in seconds
        'path' => env('DB_CACHE_PATH', null),                // null = auto (storage/cache/query)
    ],
    'pagination' => [
        'default_limit' => (int) env('DB_PAGINATION_DEFAULT_LIMIT', 10),
        'max_limit' => (int) env('DB_PAGINATION_MAX_LIMIT', 500),
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
            'write' => [
                'host' => (string) env('DB_HOST', 'localhost'),
                'username' => (string) env('DB_USERNAME', 'root'),
                'password' => (string) env('DB_PASSWORD', ''),
                'database' => (string) env('DB_DATABASE', 'example_db'),
                'port' => (string) env('DB_PORT', '3306'),
                'charset' => (string) env('DB_CHARSET', 'utf8mb4'),
            ],
            'read' => array_values(array_filter([
                env('DB_READ_HOST', '') !== '' ? [
                    'host' => (string) env('DB_READ_HOST', 'localhost'),
                    'username' => (string) env('DB_READ_USERNAME', env('DB_USERNAME', 'root')),
                    'password' => (string) env('DB_READ_PASSWORD', env('DB_PASSWORD', '')),
                    'database' => (string) env('DB_READ_DATABASE', env('DB_DATABASE', 'example_db')),
                    'port' => (string) env('DB_READ_PORT', env('DB_PORT', '3306')),
                    'charset' => (string) env('DB_READ_CHARSET', env('DB_CHARSET', 'utf8mb4')),
                ] : null,
                env('DB_READ_HOST_2', '') !== '' ? [
                    'host' => (string) env('DB_READ_HOST_2', 'localhost'),
                    'username' => (string) env('DB_READ_USERNAME_2', env('DB_USERNAME', 'root')),
                    'password' => (string) env('DB_READ_PASSWORD_2', env('DB_PASSWORD', '')),
                    'database' => (string) env('DB_READ_DATABASE_2', env('DB_DATABASE', 'example_db')),
                    'port' => (string) env('DB_READ_PORT_2', env('DB_PORT', '3306')),
                    'charset' => (string) env('DB_READ_CHARSET_2', env('DB_CHARSET', 'utf8mb4')),
                ] : null,
            ])),
            'sticky' => (bool) env('DB_STICKY_READS', true),
        ],
        'staging' => [
            'driver'   => (string) env('DB_STAGING_CONNECTION', env('DB_CONNECTION', 'mysql')),
            'host'     => (string) env('DB_STAGING_HOST', env('DB_HOST', '')),
            'username' => (string) env('DB_STAGING_USERNAME', env('DB_USERNAME', '')),
            'password' => (string) env('DB_STAGING_PASSWORD', env('DB_PASSWORD', '')),
            'database' => (string) env('DB_STAGING_DATABASE', env('DB_DATABASE', '')),
            'port'     => (string) env('DB_STAGING_PORT', env('DB_PORT', '3306')),
            'charset'  => (string) env('DB_STAGING_CHARSET', env('DB_CHARSET', 'utf8mb4')),
            'write' => [
                'host' => (string) env('DB_STAGING_HOST', env('DB_HOST', '')),
                'username' => (string) env('DB_STAGING_USERNAME', env('DB_USERNAME', '')),
                'password' => (string) env('DB_STAGING_PASSWORD', env('DB_PASSWORD', '')),
                'database' => (string) env('DB_STAGING_DATABASE', env('DB_DATABASE', '')),
                'port' => (string) env('DB_STAGING_PORT', env('DB_PORT', '3306')),
                'charset' => (string) env('DB_STAGING_CHARSET', env('DB_CHARSET', 'utf8mb4')),
            ],
            'read' => array_values(array_filter([
                env('DB_READ_STAGING_HOST', '') !== '' ? [
                    'host' => (string) env('DB_READ_STAGING_HOST', env('DB_STAGING_HOST', '')),
                    'username' => (string) env('DB_READ_STAGING_USERNAME', env('DB_STAGING_USERNAME', '')),
                    'password' => (string) env('DB_READ_STAGING_PASSWORD', env('DB_STAGING_PASSWORD', '')),
                    'database' => (string) env('DB_READ_STAGING_DATABASE', env('DB_STAGING_DATABASE', '')),
                    'port' => (string) env('DB_READ_STAGING_PORT', env('DB_STAGING_PORT', '3306')),
                    'charset' => (string) env('DB_READ_STAGING_CHARSET', env('DB_STAGING_CHARSET', 'utf8mb4')),
                ] : null,
            ])),
            'sticky' => (bool) env('DB_STAGING_STICKY_READS', env('DB_STICKY_READS', true)),
        ],
        'production' => [
            'driver'   => (string) env('DB_PRODUCTION_CONNECTION', env('DB_CONNECTION', 'mysql')),
            'host'     => (string) env('DB_PRODUCTION_HOST', env('DB_HOST', '')),
            'username' => (string) env('DB_PRODUCTION_USERNAME', env('DB_USERNAME', '')),
            'password' => (string) env('DB_PRODUCTION_PASSWORD', env('DB_PASSWORD', '')),
            'database' => (string) env('DB_PRODUCTION_DATABASE', env('DB_DATABASE', '')),
            'port'     => (string) env('DB_PRODUCTION_PORT', env('DB_PORT', '3306')),
            'charset'  => (string) env('DB_PRODUCTION_CHARSET', env('DB_CHARSET', 'utf8mb4')),
            'write' => [
                'host' => (string) env('DB_PRODUCTION_HOST', env('DB_HOST', '')),
                'username' => (string) env('DB_PRODUCTION_USERNAME', env('DB_USERNAME', '')),
                'password' => (string) env('DB_PRODUCTION_PASSWORD', env('DB_PASSWORD', '')),
                'database' => (string) env('DB_PRODUCTION_DATABASE', env('DB_DATABASE', '')),
                'port' => (string) env('DB_PRODUCTION_PORT', env('DB_PORT', '3306')),
                'charset' => (string) env('DB_PRODUCTION_CHARSET', env('DB_CHARSET', 'utf8mb4')),
            ],
            'read' => array_values(array_filter([
                env('DB_READ_PRODUCTION_HOST', '') !== '' ? [
                    'host' => (string) env('DB_READ_PRODUCTION_HOST', env('DB_PRODUCTION_HOST', '')),
                    'username' => (string) env('DB_READ_PRODUCTION_USERNAME', env('DB_PRODUCTION_USERNAME', '')),
                    'password' => (string) env('DB_READ_PRODUCTION_PASSWORD', env('DB_PRODUCTION_PASSWORD', '')),
                    'database' => (string) env('DB_READ_PRODUCTION_DATABASE', env('DB_PRODUCTION_DATABASE', '')),
                    'port' => (string) env('DB_READ_PRODUCTION_PORT', env('DB_PRODUCTION_PORT', '3306')),
                    'charset' => (string) env('DB_READ_PRODUCTION_CHARSET', env('DB_PRODUCTION_CHARSET', 'utf8mb4')),
                ] : null,
                env('DB_READ_PRODUCTION_HOST_2', '') !== '' ? [
                    'host' => (string) env('DB_READ_PRODUCTION_HOST_2', env('DB_PRODUCTION_HOST', '')),
                    'username' => (string) env('DB_READ_PRODUCTION_USERNAME_2', env('DB_PRODUCTION_USERNAME', '')),
                    'password' => (string) env('DB_READ_PRODUCTION_PASSWORD_2', env('DB_PRODUCTION_PASSWORD', '')),
                    'database' => (string) env('DB_READ_PRODUCTION_DATABASE_2', env('DB_PRODUCTION_DATABASE', '')),
                    'port' => (string) env('DB_READ_PRODUCTION_PORT_2', env('DB_PRODUCTION_PORT', '3306')),
                    'charset' => (string) env('DB_READ_PRODUCTION_CHARSET_2', env('DB_PRODUCTION_CHARSET', 'utf8mb4')),
                ] : null,
            ])),
            'sticky' => (bool) env('DB_PRODUCTION_STICKY_READS', env('DB_STICKY_READS', true)),
            // SSL/TLS for encrypted connections 
            'ssl' => [
                'enabled'     => (bool) env('DB_SSL_ENABLED', false),
                'ca'          => env('DB_SSL_CA', null),          // Path to CA cert
                'cert'        => env('DB_SSL_CERT', null),        // Path to client cert
                'key'         => env('DB_SSL_KEY', null),         // Path to client key
                'verify_peer' => (bool) env('DB_SSL_VERIFY_PEER', true),
            ],
        ],
    ],

    // Separate named connection for explicitly different database usage.
    // Read/write routing for the default connection lives inside default.read/default.write.
    'slave' => [
        'development' => [
            'driver'   => (string) env('DB_READ_CONNECTION', env('DB_CONNECTION', 'mysql')),
            'host'     => (string) env('DB_READ_HOST', env('DB_HOST', 'localhost')),
            'username' => (string) env('DB_READ_USERNAME', env('DB_USERNAME', 'root')),
            'password' => (string) env('DB_READ_PASSWORD', env('DB_PASSWORD', '')),
            'database' => (string) env('DB_READ_DATABASE', env('DB_DATABASE', 'example_db')),
            'port'     => (string) env('DB_READ_PORT', env('DB_PORT', '3306')),
            'charset'  => (string) env('DB_READ_CHARSET', env('DB_CHARSET', 'utf8mb4')),
        ],
        'staging' => [
            'driver'   => (string) env('DB_READ_STAGING_CONNECTION', env('DB_STAGING_CONNECTION', 'mysql')),
            'host'     => (string) env('DB_READ_STAGING_HOST', env('DB_STAGING_HOST', '')),
            'username' => (string) env('DB_READ_STAGING_USERNAME', env('DB_STAGING_USERNAME', '')),
            'password' => (string) env('DB_READ_STAGING_PASSWORD', env('DB_STAGING_PASSWORD', '')),
            'database' => (string) env('DB_READ_STAGING_DATABASE', env('DB_STAGING_DATABASE', '')),
            'port'     => (string) env('DB_READ_STAGING_PORT', env('DB_STAGING_PORT', '3306')),
            'charset'  => (string) env('DB_READ_STAGING_CHARSET', env('DB_STAGING_CHARSET', 'utf8mb4')),
        ],
        'production' => [
            'driver'   => (string) env('DB_READ_PRODUCTION_CONNECTION', env('DB_PRODUCTION_CONNECTION', 'mysql')),
            'host'     => (string) env('DB_READ_PRODUCTION_HOST', env('DB_PRODUCTION_HOST', '')),
            'username' => (string) env('DB_READ_PRODUCTION_USERNAME', env('DB_PRODUCTION_USERNAME', '')),
            'password' => (string) env('DB_READ_PRODUCTION_PASSWORD', env('DB_PRODUCTION_PASSWORD', '')),
            'database' => (string) env('DB_READ_PRODUCTION_DATABASE', env('DB_PRODUCTION_DATABASE', '')),
            'port'     => (string) env('DB_READ_PRODUCTION_PORT', env('DB_PRODUCTION_PORT', '3306')),
            'charset'  => (string) env('DB_READ_PRODUCTION_CHARSET', env('DB_PRODUCTION_CHARSET', 'utf8mb4')),
        ],
    ],
];
