<?php

$apiPrefix = trim((string) env('API_PREFIX', '/api'));
$apiVersion = trim((string) env('API_VERSION', 'v1'), '/');
$apiVersioningEnabled = env('API_VERSIONING_ENABLED', true) === true;
$defaultApiWhitelist = rtrim($apiPrefix !== '' ? $apiPrefix : '/api', '/');

if ($apiVersioningEnabled && $apiVersion !== '') {
    $defaultApiWhitelist .= '/' . $apiVersion;
}

$defaultApiWhitelist .= '/auth/login';

/*
|--------------------------------------------------------------------------
| API
|--------------------------------------------------------------------------
*/

$config['api'] =  [
    'cors' => [
        'allow_origin' => env_list('API_CORS_ALLOW_ORIGIN', ['*']),  // Restrict in production: ['https://yourdomain.com']
        'allow_methods' => env_list('API_CORS_ALLOW_METHODS', ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS']),
        'allow_headers' => env_list('API_CORS_ALLOW_HEADERS', ['Content-Type', 'Authorization', 'X-Requested-With']),
        // Credentials should only be true when allow_origin is specific (not *).
        'allow_credentials' => (bool) env('API_CORS_ALLOW_CREDENTIALS', false),
        // If false, wildcard origins are rejected for authenticated endpoints.
        'allow_wildcard_with_auth' => (bool) env('API_CORS_ALLOW_WILDCARD_WITH_AUTH', false),
    ],
    'auth' => [
        'required' => (bool) env('API_AUTH_REQUIRED', true),
        // Supported values: session, token, jwt, api_key, oauth, oauth2, basic, digest
        // Keep token-only as default for least privilege on API endpoints.
        'methods' => env_list('API_AUTH_METHODS', ['token']),
    ],
    'versioning' => [
        'enabled' => (bool) env('API_VERSIONING_ENABLED', true),
        // Update this in one place when promoting a new stable API version.
        'current' => (string) env('API_VERSION', 'v1'),
        'prefix' => (string) env('API_PREFIX', '/api'),
    ],
    'token_table' => (string) env('API_TOKEN_TABLE', 'users_access_tokens'),
    'rate_limit_table' => (string) env('API_RATE_LIMIT_TABLE', 'api_rate_limits'),
    'rate_limit' => [
        'enabled' => (bool) env('API_RATE_LIMIT_ENABLED', true),
        'max_requests' => max(1, (int) env('API_RATE_LIMIT_MAX_REQUESTS', 60)),
        'window_seconds' => max(1, (int) env('API_RATE_LIMIT_WINDOW_SECONDS', 60)),
    ],
    'log_errors' => (bool) env('API_LOG_ERRORS', true),
    'ip_whitelist' => env_list('API_IP_WHITELIST', ['127.0.0.1', '::1']),
    // Accepts either full API paths (/api/v1/auth/login) or normalized internal paths (v1/auth/login).
    'url_whitelist' => env_list('API_URL_WHITELIST', [$defaultApiWhitelist]),

    /*
    |----------------------------------------------------------------------
    | API Request / Response Logging
    |----------------------------------------------------------------------
    | Enable per-request logging for API routes. Logs are written to
    | the configured path. Useful for debugging & auditing.
    */
    'logging' => [
        'enabled'  => (bool) env('API_LOGGING_ENABLED', false),
        'log_path' => (string) env('API_LOG_PATH', 'logs/api.log'),
    ],
];
