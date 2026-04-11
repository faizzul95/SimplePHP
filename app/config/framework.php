<?php

$config['framework'] = [
    'route_files' => [
        'web' => 'app/routes/web.php',
        'api' => 'app/routes/api.php',
        'console' => 'app/routes/console.php',
    ],
    'view_path' => 'app/views',
    'view_cache_path' => 'storage/cache/views',
    'error_views' => [
        '404' => 'app/views/errors/404.php',
        'general' => 'app/views/errors/general_error.php',
        'error_image' => 'general/images/nodata/403.png',
    ],
    'not_found_redirect' => [
        'web' => 'login',
    ],
    'scope_macro' => [
        'base_path' => 'app/http/controllers/',
        'folders' => ['ScopeControllers'],
        'files' => [],
    ],
    'middleware_aliases' => [
        'headers' => \App\Http\Middleware\SetSecurityHeaders::class,
        'csrf' => \App\Http\Middleware\VerifyCsrfToken::class,
        'guest' => \App\Http\Middleware\EnsureGuest::class,
        'auth' => \App\Http\Middleware\RequireAuth::class,
        'auth.web' => \App\Http\Middleware\RequireSessionAuth::class,
        'auth.api' => \App\Http\Middleware\RequireApiToken::class,
        'auth.token' => \App\Http\Middleware\RequireTokenAuth::class,
        'auth.jwt' => \App\Http\Middleware\RequireJwtAuth::class,
        'auth.api_key' => \App\Http\Middleware\RequireApiKeyAuth::class,
        'auth.oauth' => \App\Http\Middleware\RequireOAuthAuth::class,
        'auth.oauth2' => \App\Http\Middleware\RequireOAuth2Auth::class,
        'auth.basic' => \App\Http\Middleware\RequireBasicAuth::class,
        'auth.digest' => \App\Http\Middleware\RequireDigestAuth::class,
        'permission' => \App\Http\Middleware\RequirePermission::class,
        'permission.any' => \App\Http\Middleware\RequireAnyPermission::class,
        'role' => \App\Http\Middleware\RequireRole::class,
        'ability' => \App\Http\Middleware\RequireAbility::class,
        'throttle' => \App\Http\Middleware\RateLimit::class,
        'aggressive-throttle' => \App\Http\Middleware\ThrottleRequests::class,
        'xss' => \App\Http\Middleware\XssProtection::class,
        'api.log' => \App\Http\Middleware\ApiRequestLogger::class,
        'cache.headers' => \App\Http\Middleware\SetResponseCache::class,
        'request.safety' => \App\Http\Middleware\ValidateRequestSafety::class,
    ],
    'middleware_groups' => [
        'web' => ['headers', 'request.safety', 'csrf', 'throttle:web'],
        'api' => ['headers', 'request.safety', 'throttle:api', 'xss', 'api.log'],
    ],
    'middleware_override_aliases' => [
        'xss',
    ],
    'rate_limiters' => [
        'web' => [
            'max_attempts' => 120,
            'decay_seconds' => 60,
            'scope' => 'auth-route',
        ],
        'api' => [
            'max_attempts' => 120,
            'decay_seconds' => 60,
            'scope' => 'auth-route',
        ],
        'auth' => [
            'max_attempts' => 10,
            'decay_seconds' => 60,
            'scope' => 'ip-route',
        ],
    ],
];
