<?php

$trustedHosts = env_list('TRUSTED_HOSTS', []);
$trustedProxies = env_list('TRUSTED_PROXIES', []);
$cspNonceEnabled = (bool) env('CSP_NONCE_ENABLED', false);
$cspAllowUnsafeInline = (bool) env('CSP_ALLOW_UNSAFE_INLINE', true);
$defaultWriteContentTypes = [
    'application/json',
    'application/x-www-form-urlencoded',
    'multipart/form-data',
    'text/plain',
];

/*
|--------------------------------------------------------------------------
| SECURITY
|--------------------------------------------------------------------------
*/

$config['security'] = [
    'csrf' => [
        'csrf_protection'    => (bool) env('CSRF_PROTECTION', true),
        'csrf_token_name'    => (string) env('CSRF_TOKEN_NAME', 'csrf_token'),
        'csrf_cookie_name'   => (string) env('CSRF_COOKIE_NAME', 'csrf_cookie'),
        'csrf_expire'        => (int) env('CSRF_EXPIRE', 7200),
        // Keep false by default so modal/AJAX-loaded forms do not go stale between requests.
        'csrf_regenerate'    => (bool) env('CSRF_REGENERATE', false),
        // Routes excluded from CSRF verification (API uses Bearer tokens instead)
        'csrf_exclude_uris'  => [
            'api/*',
        ],
        // Routes explicitly included for CSRF verification (supports wildcards)
        'csrf_include_uris'  => [],
        // Secure-by-default for HTTPS deployments; local HTTP dev can override via .env.
        'csrf_secure_cookie' => env('CSRF_SECURE_COOKIE', true),
        'csrf_httponly'      => true,
        'csrf_samesite'      => 'Lax',
        // Verify Origin/Referer on state-changing web requests.
        'csrf_origin_check'  => true,
        // Keep true to avoid breaking non-browser clients that do not send Origin/Referer.
        'csrf_allow_missing_origin' => true,
        // Additional trusted origins (scheme + host [+ optional port]).
        'csrf_trusted_origins' => [
            // 'https://example.com',
        ],
    ],

    // Basic request hardening to reduce injection/smuggling attack surface.
    'request_hardening' => [
        'enabled' => true,
        'max_uri_length' => 2000,
        'max_body_bytes' => 1048576, // 1 MB
        'max_user_agent_length' => 1024,
        'max_header_count' => 64,
        'max_input_vars' => 200,
        'max_json_fields' => 200,
        'max_multipart_parts' => 50,
        // Host allow-list is sourced from security.trusted.hosts so setup stays in one place.
        'allowed_hosts' => $trustedHosts,
        // Content types allowed for write requests when body is present.
        'allowed_write_content_types' => $defaultWriteContentTypes,
    ],

    // Central trust configuration. bootstrap.php mirrors these values into
    // request_hardening.allowed_hosts and the legacy trusted_proxies key so
    // older runtime consumers continue to work during the migration.
    'trusted' => [
        'hosts' => $trustedHosts,
        'proxies' => $trustedProxies,
    ],

    // Allow-list of external hosts that redirect()->away() may send users to.
    // Same-origin redirects are always permitted; any host listed here is
    // additionally accepted. Leave empty to refuse all cross-origin redirects.
    'redirects' => [
        'allowed_hosts' => env_list('REDIRECT_ALLOWED_HOSTS', []),
    ],

    /*
    |----------------------------------------------------------------------
    | Content Security Policy (CSP)
    |----------------------------------------------------------------------
    | Configure the Content-Security-Policy header directives.
    | Each directive is an array of allowed sources.
    | Set 'enabled' to false to disable CSP entirely.
    */
    'csp' => [
        'enabled'      => true,
        // Keep nonce mode opt-in until the views stop relying on inline scripts,
        // inline styles, and inline event handlers such as onclick.
        'nonce_enabled' => $cspNonceEnabled,
        'default-src' => ["'self'"],
        'script-src'  => [
            "'self'",
            ...($cspAllowUnsafeInline ? ["'unsafe-inline'"] : []),
            'https://cdn.datatables.net',
            'https://cdn.jsdelivr.net',
            'https://cdnjs.cloudflare.com',
        ],
        'style-src'   => [
            "'self'",
            ...($cspAllowUnsafeInline ? ["'unsafe-inline'"] : []),
            'https://fonts.googleapis.com',
            'https://cdn.datatables.net',
            'https://cdnjs.cloudflare.com',
        ],
        'font-src'    => [
            "'self'",
            'https://fonts.gstatic.com',
            'https://cdnjs.cloudflare.com',
        ],
        'img-src'     => ["'self'", 'data:'],
        'connect-src' => ["'self'"],
        'frame-ancestors' => ["'self'"],
        'base-uri'    => ["'self'"],
        'form-action' => ["'self'"],
    ],

    /*
    |----------------------------------------------------------------------
    | Permissions-Policy
    |----------------------------------------------------------------------
    | Control browser feature access. Use (self) to allow same-origin,
    | () to deny entirely, or (self "https://example.com") to allow specific origins.
    */
    'permissions_policy' => [
        'geolocation'    => [],
        'microphone'     => [],
        'camera'         => [],
        'payment'        => [],
        'usb'            => [],
        'magnetometer'   => [],
        'gyroscope'      => [],
        'accelerometer'  => [],
        'fullscreen'     => ['self'],
        'clipboard-read' => ['self'],
    ],

    // Security headers baseline (Laravel-inspired, lightweight defaults)
    'headers' => [
        'hsts' => [
            'enabled' => true,
            'max_age' => 31536000,
            'include_subdomains' => true,
            'preload' => true,
            'enforce_https_only' => true,
        ],
        'x_frame_options' => 'SAMEORIGIN',
        'x_content_type_options' => 'nosniff',
        'referrer_policy' => 'strict-origin-when-cross-origin',
        'cross_origin_opener_policy' => 'same-origin',
        'cross_origin_resource_policy' => 'same-origin',
        'x_dns_prefetch_control' => 'off',
    ],

    // Environment presets to apply in bootstrap.php
    // Each environment can override multiple top-level config sections.
    'presets' => [
        'development' => [
            'security' => [
                'headers' => [
                    'hsts' => [
                        'enabled' => false,
                    ],
                ],
            ],
            'api' => [
                'cors' => [
                    'allow_origin' => ['*'],
                ],
                'logging' => [
                    'enabled' => true,
                ],
            ],
            'framework' => [
                'rate_limiters' => [
                    'web' => ['max_attempts' => 240, 'decay_seconds' => 60, 'scope' => 'auth-route'],
                    'api' => ['max_attempts' => 240, 'decay_seconds' => 60, 'scope' => 'auth-route'],
                ],
            ],
            'db' => [
                'profiling' => ['enabled' => true],
            ],
        ],
        'staging' => [
            'api' => [
                'cors' => [
                    'allow_origin' => ['https://staging.example.com'],
                ],
                'logging' => [
                    'enabled' => true,
                ],
            ],
            'framework' => [
                'rate_limiters' => [
                    'web' => ['max_attempts' => 120, 'decay_seconds' => 60, 'scope' => 'auth-route'],
                    'api' => ['max_attempts' => 120, 'decay_seconds' => 60, 'scope' => 'auth-route'],
                    'auth' => ['max_attempts' => 8, 'decay_seconds' => 60, 'scope' => 'ip-route'],
                ],
            ],
            'db' => [
                'profiling' => ['enabled' => false],
                'cache' => ['enabled' => true],
            ],
        ],
        'production' => [
            'api' => [
                'cors' => [
                    'allow_origin' => ['https://example.com'],
                ],
                'logging' => [
                    'enabled' => false,
                ],
            ],
            'framework' => [
                'rate_limiters' => [
                    'web' => ['max_attempts' => 90, 'decay_seconds' => 60, 'scope' => 'auth-route'],
                    'api' => ['max_attempts' => 90, 'decay_seconds' => 60, 'scope' => 'auth-route'],
                    'auth' => ['max_attempts' => 5, 'decay_seconds' => 60, 'scope' => 'ip-route'],
                ],
            ],
            'db' => [
                'profiling' => ['enabled' => false],
                'cache' => ['enabled' => true],
            ],
        ],
    ],

    // Trusted proxy IPs — only trust forwarded headers from these IPs
    'trusted_proxies' => $trustedProxies,
];