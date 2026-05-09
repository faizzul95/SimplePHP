<?php

$config['framework'] = [
    'bootstrap' => [
        // Session bootstrap policy by runtime:
        // - web: always stateful unless manually disabled
        // - api: stateless by default for token/oauth2/jwt/api_key/basic/digest clients
        // - cli: stateless by default for commands, workers, and maintenance scripts
        'session' => [
            'enabled' => true,
            'cli' => false,
            'api' => false,
        ],
    ],
    'maintenance' => [
        // Laravel-style bypass secret. Visit /{secret} while the app is down to receive
        // a temporary bypass cookie for this browser.
        'secret' => (string) env('MYTH_MAINTENANCE_SECRET', ''),
        'view' => 'app/views/errors/503.php',
        'bypass_cookie' => [
            'name' => (string) env('MYTH_MAINTENANCE_BYPASS_COOKIE', 'myth_maintenance'),
            'ttl' => (int) env('MYTH_MAINTENANCE_BYPASS_TTL', 43200),
            'same_site' => (string) env('MYTH_MAINTENANCE_BYPASS_SAME_SITE', 'Lax'),
        ],
    ],
    'route_files' => [
        'web' => 'app/routes/web.php',
        'api' => 'app/routes/api.php',
        'console' => 'app/routes/console.php',
    ],
    'providers' => [
        // Loaded on every request — web, api, and cli
        'always' => [
            \App\Providers\AppServiceProvider::class,
            \App\Providers\LogServiceProvider::class,
            \App\Providers\DatabaseServiceProvider::class,
            \App\Providers\CacheServiceProvider::class,
            \App\Providers\SecurityServiceProvider::class,
            \App\Providers\EventServiceProvider::class,
        ],
        // Only for browser-facing web requests
        'web' => [
            \App\Providers\FilesystemServiceProvider::class,
            \App\Providers\ViewServiceProvider::class,
            \App\Providers\ResponseServiceProvider::class,
            \App\Providers\RoutingServiceProvider::class,
            \App\Providers\MaintenanceServiceProvider::class,
            \App\Providers\FeatureServiceProvider::class,
            \App\Providers\AuthServiceProvider::class,
        ],
        // Only for stateless API requests (no view/session providers)
        'api' => [
            \App\Providers\AuthServiceProvider::class,
            \App\Providers\ResponseServiceProvider::class,
            \App\Providers\RoutingServiceProvider::class,
            \App\Providers\FeatureServiceProvider::class,
        ],
        // Only for CLI / Artisan-style commands
        'cli' => [
            \App\Providers\RoutingServiceProvider::class,
        ],
    ],
    'view_path' => 'app/views',
    'view_cache_path' => 'storage/cache/views',
    'view_compact_compiled_cache' => true,
    'view_minify_output' => true,
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
        'session.stateful' => \App\Http\Middleware\StartStatefulSession::class,
        'headers' => \App\Http\Middleware\SetSecurityHeaders::class,
        'trusted.hosts' => \App\Http\Middleware\ValidateTrustedHosts::class,
        'trusted.proxies' => \App\Http\Middleware\ValidateTrustedProxies::class,
        'payload.limits' => \App\Http\Middleware\ValidatePayloadLimits::class,
        'content.type' => \App\Http\Middleware\EnforceContentType::class,
        'origin.policy' => \App\Http\Middleware\EnforceOriginPolicy::class,
        'request.fingerprint' => \App\Http\Middleware\AttachRequestFingerprint::class,
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
        'feature' => \App\Http\Middleware\RequireFeature::class,
        'permission' => \App\Http\Middleware\RequirePermission::class,
        'permission.any' => \App\Http\Middleware\RequireAnyPermission::class,
        'role' => \App\Http\Middleware\RequireRole::class,
        'ability' => \App\Http\Middleware\RequireAbility::class,
        'throttle' => \App\Http\Middleware\RateLimit::class,
        'aggressive-throttle' => \App\Http\Middleware\ThrottleRequests::class,
        'upload.guard' => \App\Http\Middleware\ValidateUploadGuard::class,
        'xss' => \App\Http\Middleware\XssProtection::class,
        'api.log' => \App\Http\Middleware\ApiRequestLogger::class,
        'cache.headers' => \App\Http\Middleware\SetResponseCache::class,
        'request.safety' => \App\Http\Middleware\ValidateRequestSafety::class,
        'menu.access' => \App\Http\Middleware\EnforceMenuAccess::class,
        'idor'        => \Middleware\DetectIdor::class,
        'compress'    => \Middleware\CompressResponse::class,
    ],
    'middleware_groups' => [
        'web' => ['session.stateful', 'headers', 'trusted.hosts', 'trusted.proxies', 'payload.limits', 'request.fingerprint', 'request.safety', 'origin.policy', 'menu.access', 'csrf', 'throttle:web'],
        'api' => ['headers', 'trusted.hosts', 'trusted.proxies', 'payload.limits', 'content.type', 'request.fingerprint', 'request.safety', 'throttle:api', 'xss', 'api.log'],
        'api.public.submit' => ['throttle:auth'],
        'api.external.auth' => ['auth.api'],
        'api.app' => ['auth'],
        'api.upload.image' => ['api.app', 'content.type:multipart', 'upload.guard:image-cropper'],
        'api.upload.action' => ['api.app', 'upload.guard:delete'],
    ],
    'middleware_override_aliases' => [
        'xss',
        'content.type',
    ],
    'content_type_profiles' => [
        'json' => ['application/json', 'application/*+json'],
        'form' => ['application/x-www-form-urlencoded'],
        'multipart' => ['multipart/form-data'],
        'text' => ['text/plain'],
    ],
    'upload_guards' => [
        'image-cropper' => [
            'require_ajax' => true,
            'required_fields' => ['entity_id', 'entity_type', 'entity_file_type', 'image'],
            'entity_types' => ['users'],
            'entity_file_types' => ['USER_PROFILE', 'avatar'],
            'folder_groups' => ['directory'],
            'folder_types' => ['avatar'],
            'base64_image_field' => 'image',
            'base64_image_mime_types' => ['image/jpeg', 'image/png'],
        ],
        'delete' => [
            'require_ajax' => true,
            'required_fields' => ['id'],
        ],
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
