<?php

/*
| How the application's own API authenticates — see api.php for what each mode
| means. Read from the already-loaded api config where possible so the value is
| defined in exactly one place, with the env as a fallback in case load order
| ever changes.
*/
$apiAuthDriver = strtolower(trim((string) (
    $config['api']['driver'] ?? env('API_AUTH_DRIVER', 'token')
)));

$apiAppMiddleware = match ($apiAuthDriver) {
    // Cookies: the browser attaches them by itself, so the origin check and the
    // CSRF token are what stop another site from driving the API.
    'session' => ['api', 'origin.policy:strict', 'session.stateful:force', 'auth.web', 'csrf:force'],

    // Either credential. CSRF applies only to callers that got in on the cookie;
    // `csrf:stateful` decides that from the authentication that actually
    // succeeded, not from the presence of an Authorization header.
    'hybrid' => ['api', 'session.stateful:force', 'auth:token,session', 'csrf:stateful'],

    // Token-first default. Nothing here is ambient: no session is started and no
    // CSRF token is required, because a bearer credential cannot be replayed by
    // a browser the caller does not control.
    default => ['api', 'auth.api'],
};

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
        /*
        | Paths that stay reachable while the app is down. Matched with fnmatch(),
        | so wildcards work. A load balancer that cannot reach the health endpoint
        | pulls the node out of rotation, and a payment provider that receives a
        | 503 usually stops retrying — both turn a planned window into an outage.
        */
        'allowed_paths' => env_list('MYTH_MAINTENANCE_ALLOWED_PATHS', [
            'api/v1/health',
            'up',
        ]),
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
            \App\Providers\MaintenanceServiceProvider::class,
            \App\Providers\ViewServiceProvider::class,
        ],
        // Only for browser-facing web requests
        'web' => [
            \App\Providers\FilesystemServiceProvider::class,
            \App\Providers\ResponseServiceProvider::class,
            \App\Providers\RoutingServiceProvider::class,
            \App\Providers\FeatureServiceProvider::class,
            \App\Providers\AuthServiceProvider::class,
        ],
        // Only for stateless API requests (no view/session providers)
        'api' => [
            \App\Providers\AuthServiceProvider::class,
            \App\Providers\FilesystemServiceProvider::class,
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
    'preload' => [
        ['path' => 'sneat/assets/css/core.css', 'as' => 'style'],
        ['path' => 'sneat/assets/js/helpers.js', 'as' => 'script'],
    ],
    'profiling' => [
        'memory_alert_mb' => (int) env('PROFILE_MEMORY_ALERT_MB', 32),
        'memory_log_enabled' => (bool) env('PROFILE_MEMORY_LOG', true),
        'slow_request_ms' => (int) env('PROFILE_SLOW_REQUEST_MS', 2000),
    ],
    'middleware_aliases' => [
        'session.stateful' => \App\Http\Middleware\StartStatefulSession::class,
        'headers' => \App\Http\Middleware\SetSecurityHeaders::class,
        'telemetry' => \App\Http\Middleware\RecordTelemetry::class,
        'preload.assets' => \App\Http\Middleware\PreloadCriticalAssets::class,
        'trusted.hosts' => \App\Http\Middleware\ValidateTrustedHosts::class,
        'trusted.proxies' => \App\Http\Middleware\ValidateTrustedProxies::class,
        'ip.blocklist' => \App\Http\Middleware\BlocklistIp::class,
        'payload.limits' => \App\Http\Middleware\ValidatePayloadLimits::class,
        'content.type' => \App\Http\Middleware\EnforceContentType::class,
        'origin.policy' => \App\Http\Middleware\EnforceOriginPolicy::class,
        'request.fingerprint' => \App\Http\Middleware\AttachRequestFingerprint::class,
        'csrf' => \App\Http\Middleware\VerifyCsrfToken::class,
        // The token-client counterpart to csrf: replay and tamper protection.
        // Runs after auth — the signing key is the bearer token presented.
        'signed' => \App\Http\Middleware\VerifyRequestSignature::class,
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
        'cache.response' => \App\Http\Middleware\CacheResponse::class,
        'timing.normalize' => \App\Http\Middleware\NormalizeResponseTime::class,
        'request.safety' => \App\Http\Middleware\ValidateRequestSafety::class,
        'menu.access' => \App\Http\Middleware\EnforceMenuAccess::class,
        'idor'        => \Middleware\DetectIdor::class,
        'compress'    => \Middleware\CompressResponse::class,
    ],
    /*
    | Applied to every route ahead of its own stack, so a route registered outside
    | the web/api groups still gets these. Keep it to middleware that is safe and
    | cheap everywhere — anything stateful or route-specific belongs in a group.
    */
    'middleware_global' => [
        // First, so it observes the whole request including anything the
        // middleware below it rejects. A no-op unless telemetry.enabled.
        'telemetry',
        'headers',
        'trusted.hosts',
        'trusted.proxies',
        'ip.blocklist',
    ],
    'middleware_groups' => [
        // `xss` sits on the web group as well as the api group so every form
        // submit is scanned, not just the ones that go through the API. It logs
        // rather than blocks unless security.xss_input_blocking is on — see the
        // note there for why a blocklist cannot be the primary defence.
        'web' => ['session.stateful', 'headers', 'preload.assets', 'trusted.hosts', 'trusted.proxies', 'ip.blocklist', 'throttle:web', 'request.fingerprint', 'request.safety', 'origin.policy', 'menu.access', 'xss', 'csrf'],
        'api' => ['headers', 'trusted.hosts', 'trusted.proxies', 'ip.blocklist', 'throttle:api', 'content.type', 'request.fingerprint', 'request.safety', 'xss', 'api.log'],
        'api.public.submit' => ['api', 'throttle:auth'],
        'api.external.auth' => ['api', 'auth.api'],
        'api.app' => $apiAppMiddleware,
        'api.upload.image' => ['api.app', 'content.type:multipart', 'upload.guard:image-cropper'],
        'api.upload.action' => ['api.app', 'upload.guard:delete'],
    ],
    'middleware_override_aliases' => [
        'xss',
        'content.type',
        'origin.policy',
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
    /*
    | Two windows per limiter.
    |
    | max_attempts / decay_seconds / scope is the per-route budget: it stops one
    | client hammering one endpoint, and it is close to useless against a flood,
    | because the key includes the path. An attacker walking a thousand URLs gets
    | a thousand fresh budgets, so 120/minute becomes 120,000/minute.
    |
    | burst_max_attempts / burst_decay_seconds is a coarse per-IP ceiling across
    | every route the limiter guards, checked first and costing one counter. Size
    | it above what a real session generates in a minute — page loads, XHR polls,
    | assets served through PHP — and below what a flood generates. Set it to 0 to
    | turn the coarse gate off.
    */
    'rate_limiters' => [
        'web' => [
            'max_attempts' => (int) env('RATE_LIMIT_WEB_MAX', 120),
            'decay_seconds' => (int) env('RATE_LIMIT_WEB_DECAY', 60),
            'scope' => 'auth-route',
            'burst_max_attempts' => (int) env('RATE_LIMIT_WEB_BURST', 600),
            'burst_decay_seconds' => (int) env('RATE_LIMIT_WEB_BURST_DECAY', 60),
        ],
        'api' => [
            'max_attempts' => (int) env('RATE_LIMIT_API_MAX', 120),
            'decay_seconds' => (int) env('RATE_LIMIT_API_DECAY', 60),
            'scope' => 'auth-route',
            'burst_max_attempts' => (int) env('RATE_LIMIT_API_BURST', 300),
            'burst_decay_seconds' => (int) env('RATE_LIMIT_API_BURST_DECAY', 60),
        ],
        // Credential endpoints. The per-route budget is already tight, and the
        // burst ceiling stops the same address working through a list of accounts
        // one login attempt at a time.
        'auth' => [
            'max_attempts' => (int) env('RATE_LIMIT_AUTH_MAX', 10),
            'decay_seconds' => (int) env('RATE_LIMIT_AUTH_DECAY', 60),
            'scope' => 'ip-route',
            'burst_max_attempts' => (int) env('RATE_LIMIT_AUTH_BURST', 30),
            'burst_decay_seconds' => (int) env('RATE_LIMIT_AUTH_BURST_DECAY', 300),
        ],
    ],
];
