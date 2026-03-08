<?php

/*
|--------------------------------------------------------------------------
| API
|--------------------------------------------------------------------------
*/

$config['api'] =  [
    'cors' => [
        'allow_origin' => ['*'],  // Restrict in production: ['https://yourdomain.com']
        'allow_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
        'allow_headers' => ['Content-Type', 'Authorization', 'X-Requested-With'],
    ],
    'auth' => [
        'required' => true,
    ],
    'token_table' => 'users_access_tokens',
    'rate_limit_table' => 'api_rate_limits',
    'log_errors' => true,
    'ip_whitelist' => ['127.0.0.1', '::1'],
    'url_whitelist' => [
        '/v1/auth/login'
    ],

    /*
    |----------------------------------------------------------------------
    | API Request / Response Logging
    |----------------------------------------------------------------------
    | Enable per-request logging for API routes. Logs are written to
    | the configured path. Useful for debugging & auditing.
    */
    'logging' => [
        'enabled'  => false,
        'log_path' => 'logs/api.log',
    ],
];
