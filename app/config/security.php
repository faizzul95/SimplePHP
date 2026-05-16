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
            '_myth/csp-report',
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
        'mode'         => (string) env('CSP_MODE', 'enforce'),
        'report_uri'   => (string) env('CSP_REPORT_URI', '/_myth/csp-report'),
        'report_to'    => env('CSP_REPORT_TO', null),
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
        'report_only_directives' => [
            'script-src' => ["'self'", "'nonce-{nonce}'"],
            'style-src' => ["'self'"],
        ],
    ],

    'trusted_types' => [
        'enabled' => (bool) env('TRUSTED_TYPES_ENABLED', false),
        'policies' => env_list('TRUSTED_TYPES_POLICIES', ['default']),
        'report_only' => (bool) env('TRUSTED_TYPES_REPORT_ONLY', true),
    ],

    'http_client' => [
        'post_connect_ip_check' => (bool) env('HTTP_CLIENT_POST_CONNECT_IP_CHECK', true),
        'force_ipv4' => (bool) env('HTTP_CLIENT_FORCE_IPV4', true),
        'connect_timeout_sec' => (int) env('HTTP_CLIENT_CONNECT_TIMEOUT_SEC', 5),
        'dns_cache_timeout' => (int) env('HTTP_CLIENT_DNS_CACHE_TIMEOUT', 0),
        'allowed_private_hosts' => env_list('HTTP_CLIENT_ALLOWED_PRIVATE_HOSTS', []),
        // Optional SPKI pins keyed by external host. Keep this empty unless you
        // actively manage pin rotation for a named third-party integration.
        'pins' => [
            // 'api.example.com' => ['sha256//base64PrimaryPin', 'sha256//base64BackupPin'],
        ],
        'pin_on_error' => (string) env('HTTP_CLIENT_PIN_ON_ERROR', 'block'),
    ],

    'blocklist' => [
        'enabled' => (bool) env('IP_BLOCKLIST_ENABLED', true),
        'cache_ttl' => (int) env('IP_BLOCKLIST_CACHE_TTL', 60),
        'ips' => env_list('IP_BLOCKLIST_IPS', []),
        'cidrs' => env_list('IP_BLOCKLIST_CIDRS', []),
        'auto' => [
            'enabled' => (bool) env('IP_BLOCKLIST_AUTO_ENABLED', true),
            'events' => [
                \Core\Security\AuditLogger::E_BRUTE_FORCE => [
                    'threshold' => (int) env('IP_BLOCKLIST_BRUTE_FORCE_THRESHOLD', 3),
                    'window_seconds' => (int) env('IP_BLOCKLIST_BRUTE_FORCE_WINDOW', 3600),
                    'ttl_seconds' => (int) env('IP_BLOCKLIST_BRUTE_FORCE_TTL', 86400),
                    'reason' => 'Repeated brute-force activity',
                ],
                \Core\Security\AuditLogger::E_CSRF_FAILURE => [
                    'threshold' => (int) env('IP_BLOCKLIST_CSRF_THRESHOLD', 10),
                    'window_seconds' => (int) env('IP_BLOCKLIST_CSRF_WINDOW', 300),
                    'ttl_seconds' => (int) env('IP_BLOCKLIST_CSRF_TTL', 3600),
                    'reason' => 'Repeated CSRF failures',
                ],
                \Core\Security\AuditLogger::E_SUSPICIOUS_INPUT => [
                    'threshold' => (int) env('IP_BLOCKLIST_SUSPICIOUS_THRESHOLD', 5),
                    'window_seconds' => (int) env('IP_BLOCKLIST_SUSPICIOUS_WINDOW', 3600),
                    'ttl_seconds' => (int) env('IP_BLOCKLIST_SUSPICIOUS_TTL', 21600),
                    'reason' => 'Repeated suspicious input events',
                ],
            ],
        ],
    ],

    'cookies' => [
        'session_name' => (string) env('SESSION_COOKIE_NAME', 'myth_session'),
        'session_same_site' => (string) env('COOKIE_SESSION_SAMESITE', 'Lax'),
        'session_secure' => env('COOKIE_SESSION_SECURE', true),
        'session_http_only' => true,
        'use_host_prefix' => (bool) env('COOKIE_HOST_PREFIX', false),
        'partitioned' => (bool) env('COOKIE_PARTITIONED', false),
    ],

    'timing' => [
        'auth_min_ms' => (int) env('AUTH_TIMING_MIN_MS', 200),
        'api_auth_min_ms' => (int) env('API_AUTH_TIMING_MIN_MS', 100),
    ],

    'query_allowlist' => [
        'enabled' => (bool) env('QUERY_ALLOWLIST_AUDIT_ENABLED', true),
        'controller_paths' => [
            'app/http/controllers',
        ],
        'model_paths' => [
            'app/Models',
        ],
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