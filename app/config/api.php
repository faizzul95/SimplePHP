<?php

/*
|--------------------------------------------------------------------------
| API
|--------------------------------------------------------------------------
*/

$config['api'] =  [
    'cors' => [
        'allow_origin' => ['*'],  // Restrict in production: ['https://yourdomain.com']
        'allow_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
        'allow_headers' => ['Content-Type', 'Authorization', 'X-Requested-With'],
        // Credentials should only be true when allow_origin is specific (not *).
        'allow_credentials' => false,
        // If false, wildcard origins are rejected for authenticated endpoints.
        'allow_wildcard_with_auth' => false,
    ],
    'auth' => [
        'required' => true,
        // Supported values: session, token, jwt, api_key, oauth, basic, digest
        // Keep token-only as default for least privilege on API endpoints.
        'methods' => ['token'],
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
