<?php

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
    'log_errors' => true,
    'ip_whitelist' => ['127.0.0.1', '::1'], // Localhost IPs
    'url_whitelist' => [
        '/v1/auth/login'
    ],
];
