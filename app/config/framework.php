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
        'base_path' => 'app/database/',
        'folders' => ['ScopeMacroQuery'],
        'files' => [],
    ],
    'middleware_aliases' => [
        'headers' => \App\Http\Middleware\SetSecurityHeaders::class,
        'guest' => \App\Http\Middleware\EnsureGuest::class,
        'auth' => \App\Http\Middleware\RequireAuth::class,
        'auth.web' => \App\Http\Middleware\RequireSessionAuth::class,
        'auth.api' => \App\Http\Middleware\RequireApiToken::class,
        'permission' => \App\Http\Middleware\RequirePermission::class,
        'throttle' => \App\Http\Middleware\RateLimit::class,
        'aggressive-throttle' => \App\Http\Middleware\ThrottleRequests::class,
        'xss' => \App\Http\Middleware\XssProtection::class,
        'api.log' => \App\Http\Middleware\ApiRequestLogger::class,
    ],
    'middleware_groups' => [
        'web' => ['headers', 'throttle:web'],
        'api' => ['headers', 'throttle:api', 'xss', 'api.log'],
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
