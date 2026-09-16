<?php

$apiPrefix = trim((string) env('API_PREFIX', '/api'));
$apiVersion = trim((string) env('API_VERSION', 'v1'), '/');
$apiVersioningEnabled = env('API_VERSIONING_ENABLED', true) === true;
$defaultApiWhitelist = rtrim($apiPrefix !== '' ? $apiPrefix : '/api', '/');

if ($apiVersioningEnabled && $apiVersion !== '') {
    $defaultApiWhitelist .= '/' . $apiVersion;
}

$defaultApiWhitelist .= '/auth/login';

$apiDriver = strtolower(trim((string) env('API_AUTH_DRIVER', 'token')));
if (!in_array($apiDriver, ['token', 'session', 'hybrid'], true)) {
    $apiDriver = 'token';
}

/*
|--------------------------------------------------------------------------
| API
|--------------------------------------------------------------------------
*/

$config['api'] =  [
    /*
    |----------------------------------------------------------------------
    | Auth Driver
    |----------------------------------------------------------------------
    |
    | The one switch that decides how the application's own API authenticates.
    | A fresh clone is token-first; change this (or API_AUTH_DRIVER) to opt back
    | into cookies. It only governs the `api.app` middleware group — the external
    | API under `api.external.auth` is always credential-based.
    |
    |   token    Bearer credentials only. No session is started and no CSRF token
    |            is needed, which is what a mobile app or a separately hosted SPA
    |            actually wants. Which credentials count is `api.auth.methods`.
    |
    |   session  Cookie session, CSRF, and a strict origin check. Correct when the
    |            front-end is served from the same host as the API.
    |
    |   hybrid   Accepts either. CSRF is enforced only for callers that
    |            authenticated with the cookie — a bearer token cannot be attached
    |            to a cross-site request by the browser, so it carries no CSRF
    |            risk. Use while migrating an existing cookie front-end to tokens.
    */
    'driver' => $apiDriver,

    'cors' => [
        // Fail-secure default: no origins allowed. Set API_CORS_ALLOW_ORIGIN explicitly in .env (e.g. ['https://yourdomain.com']).
        'allow_origin' => env_list('API_CORS_ALLOW_ORIGIN', []),
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
