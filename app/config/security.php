<?php

/*
|--------------------------------------------------------------------------
| SECURITY
|--------------------------------------------------------------------------
*/

$config['security'] = [
    'throttle_request'   => true,
    'xss_request'        => true,
    'permission_request' => true,

    'csrf' => [
        'csrf_protection'    => true,
        'csrf_token_name'    => 'csrf_token',
        'csrf_cookie_name'   => 'csrf_cookie',
        'csrf_expire'        => 7200,
        'csrf_regenerate'    => true,
        // Routes excluded from CSRF verification (API uses Bearer tokens instead)
        'csrf_exclude_uris'  => [
            'api/*',
        ],
        // Routes explicitly included for CSRF verification (supports wildcards)
        'csrf_include_uris'  => [],
        'csrf_secure_cookie' => true,
        'csrf_httponly'      => true,
        'csrf_samesite'      => 'Lax',
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
        'enabled'     => true,
        'default-src' => ["'self'"],
        'script-src'  => [
            "'self'",
            "'unsafe-inline'",
            "'unsafe-eval'",
            'https://cdn.datatables.net',
            'https://cdn.jsdelivr.net',
            'https://cdnjs.cloudflare.com',
        ],
        'style-src'   => [
            "'self'",
            "'unsafe-inline'",
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
        'geolocation' => '(self)',
        'microphone'  => '()',
        'camera'      => '()',
        'fullscreen'  => '(self)',
        'sync-xhr'    => '(self)',
        'usb'         => '()',
    ],

    // Trusted proxy IPs — only trust forwarded headers from these IPs
    'trusted_proxies' => [
        // '10.0.0.1',
        // '172.16.0.0/12',
    ],
];