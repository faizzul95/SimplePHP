<?php

namespace Components;

/**
 * SecurityHeaders - Enterprise-grade security headers management
 * 
 * Provides comprehensive security header configuration with modern
 * web application security best practices and performance optimization.
 * 
 * @example Basic Usage:
 * ```php
 * // Initialize with custom configuration
 * Components\SecurityHeaders::init([
 *     'environment' => 'production',
 *     'hsts_preload' => true,
 *     'report_uri' => 'https://example.com/csp-report'
 * ]);
 * 
 * // Automatic environment-based headers
 * Components\SecurityHeaders::setAppropriateHeaders();
 * ```
 * 
 * @example Development Environment:
 * ```php
 * // Set minimal security headers for development
 * Components\SecurityHeaders::setDevelopmentHeaders();
 * 
 * // Or set environment and use automatic detection
 * $_ENV['APP_ENV'] = 'development';
 * Components\SecurityHeaders::setAppropriateHeaders();
 * ```
 * 
 * @example Production Environment:
 * ```php
 * // Standard production security headers
 * Components\SecurityHeaders::setSecurityHeaders();
 * 
 * // Maximum security with all hardening headers
 * Components\SecurityHeaders::setAllSecurityHeaders();
 * 
 * // Custom CSP with nonce support
 * $nonce = Components\SecurityHeaders::generateNonce();
 * Components\SecurityHeaders::setSecurityHeaders([
 *     'script-src' => "'self' cdn.example.com 'strict-dynamic'"
 * ], $nonce);
 * 
 * // Use nonce in your templates
 * echo "<script nonce='" . Components\SecurityHeaders::getCurrentNonce() . "'>";
 * ```
 * 
 * @example Custom CSP Configuration:
 * ```php
 * // Override specific CSP directives
 * Components\SecurityHeaders::setCSP([
 *     'script-src' => "'self' 'unsafe-inline' cdn.example.com",
 *     'style-src' => "'self' 'unsafe-inline' fonts.googleapis.com",
 *     'img-src' => "'self' data: https:",
 *     'connect-src' => "'self' api.example.com"
 * ]);
 * ```
 * 
 * @example HSTS Configuration:
 * ```php
 * // Custom HSTS settings (2 years, include subdomains, enable preload)
 * Components\SecurityHeaders::setHSTS(63072000, true, true);
 * 
 * // HSTS is automatically applied in setSecurityHeaders() for HTTPS connections
 * ```
 * 
 * @example Cache Control:
 * ```php
 * // Prevent caching for sensitive pages
 * Components\SecurityHeaders::setNoCacheHeaders();
 * 
 * // Secure caching for public assets (1 hour)
 * Components\SecurityHeaders::setSecureCacheHeaders(3600);
 * ```
 * 
 * @example Emergency Security Mode:
 * ```php
 * // Lockdown headers for compromised applications
 * Components\SecurityHeaders::setEmergencySecurityHeaders();
 * ```
 * 
 * @example Environment Variables Setup:
 * ```bash
 * # .env file
 * APP_ENV=development
 * CSP_REPORT_URI=https://your-domain.com/csp-report
 * ```
 * 
 * @example Integration with Framework:
 * ```php
 * // In your application bootstrap or middleware
 * class SecurityMiddleware {
 *     public function handle($request, $next) {
 *         Components\SecurityHeaders::setAppropriateHeaders([
 *             'connect-src' => "'self' " . env('API_BASE_URL')
 *         ]);
 *         return $next($request);
 *     }
 * }
 * ```
 */
class SecurityHeaders
{
    private static bool $headersSet = false;
    private static array $config = [];

    // Security configuration constants
    private const DEFAULT_CSP_DIRECTIVES = [
        'default-src' => "'self'",
        'script-src' => "'self' 'strict-dynamic'",
        'style-src' => "'self' 'unsafe-hashes'",
        'img-src' => "'self' data: https:",
        'font-src' => "'self'",
        'connect-src' => "'self'",
        'media-src' => "'none'",
        'object-src' => "'none'",
        'child-src' => "'none'",
        'frame-src' => "'none'",
        'worker-src' => "'none'",
        'frame-ancestors' => "'none'",
        'form-action' => "'self'",
        'base-uri' => "'self'",
        'manifest-src' => "'self'",
        'upgrade-insecure-requests' => ''
    ];

    private const SECURITY_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'X-XSS-Protection' => '0', // Disabled as modern CSP is more effective
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Cross-Origin-Embedder-Policy' => 'require-corp',
        'Cross-Origin-Opener-Policy' => 'same-origin',
        'Cross-Origin-Resource-Policy' => 'same-origin'
    ];

    /**
     * Initialize security configuration
     * 
     * @param array $customConfig Custom configuration overrides
     * @return void
     */
    public static function init(array $customConfig = []): void
    {
        self::$config = array_merge([
            'environment' => $_ENV['APP_ENV'] ?? 'production',
            'enable_hsts' => true,
            'hsts_max_age' => 63072000, // 2 years
            'hsts_include_subdomains' => true,
            'hsts_preload' => false,
            'csp_nonce_length' => 32,
            'report_uri' => null,
            'report_to' => null
        ], $customConfig);
    }

    /**
     * Reset headers state (useful for testing)
     * 
     * @return void
     */
    public static function reset(): void
    {
        self::$headersSet = false;
        self::$config = [];
    }

    /**
     * Set comprehensive security headers optimized for modern web apps
     * 
     * @param array $cspOverrides Custom CSP directive overrides
     * @param string|null $nonce Optional CSP nonce for inline scripts/styles
     * @return void
     */
    public static function setSecurityHeaders(array $cspOverrides = [], ?string $nonce = null): void
    {
        if (self::$headersSet || headers_sent()) {
            return;
        }

        self::init();

        // Generate nonce if not provided
        if ($nonce === null && self::isDevelopment()) {
            $nonce = self::generateNonce();
        }

        // Set CSP with nonce support
        self::setCSP($cspOverrides, $nonce);

        // Set core security headers
        foreach (self::SECURITY_HEADERS as $header => $value) {
            header("{$header}: {$value}");
        }

        // Set HSTS in production with HTTPS
        if (self::isSecureConnection() && self::$config['enable_hsts']) {
            self::setHSTS(
                self::$config['hsts_max_age'],
                self::$config['hsts_include_subdomains'],
                self::$config['hsts_preload']
            );
        }

        // Additional security headers
        self::setAdditionalSecurityHeaders();

        // Set cache control for security-sensitive responses
        self::setSecureCacheHeaders();

        self::$headersSet = true;
    }

    /**
     * Set Content Security Policy with advanced configuration
     * 
     * @param array $overrides CSP directive overrides
     * @param string|null $nonce Optional nonce for inline content
     * @return void
     */
    public static function setCSP(array $overrides = [], ?string $nonce = null): void
    {
        $directives = array_merge(self::DEFAULT_CSP_DIRECTIVES, $overrides);

        // Add nonce to script and style sources if provided
        if ($nonce) {
            $directives['script-src'] = self::addNonceToDirective($directives['script-src'], $nonce);
            $directives['style-src'] = self::addNonceToDirective($directives['style-src'], $nonce);
        }

        // Add reporting endpoints if configured
        if (self::$config['report_uri']) {
            $directives['report-uri'] = self::$config['report_uri'];
        }

        if (self::$config['report_to']) {
            $directives['report-to'] = self::$config['report_to'];
        }

        $cspString = self::buildCSPString($directives);
        header("Content-Security-Policy: {$cspString}");
    }

    /**
     * Set HTTP Strict Transport Security header
     * 
     * @param int $maxAge Maximum age in seconds
     * @param bool $includeSubDomains Include subdomains
     * @param bool $preload Enable preload
     * @return void
     */
    public static function setHSTS(int $maxAge = 63072000, bool $includeSubDomains = true, bool $preload = false): void
    {
        if (!self::isSecureConnection()) {
            return;
        }

        $hsts = "max-age={$maxAge}";

        if ($includeSubDomains) {
            $hsts .= "; includeSubDomains";
        }

        if ($preload) {
            $hsts .= "; preload";
        }

        header("Strict-Transport-Security: {$hsts}");
    }

    /**
     * Set headers to prevent caching of sensitive content
     * 
     * @return void
     */
    public static function setNoCacheHeaders(): void
    {
        header("Cache-Control: no-cache, no-store, must-revalidate, private");
        header("Pragma: no-cache");
        header("Expires: 0");
        header("Last-Modified: " . gmdate('D, d M Y H:i:s') . ' GMT');
    }

    /**
     * Set secure cache headers for public content
     * 
     * @param int $maxAge Cache max age in seconds
     * @return void
     */
    public static function setSecureCacheHeaders(int $maxAge = 3600): void
    {
        header("Cache-Control: public, max-age={$maxAge}, must-revalidate");
        header("Vary: Accept-Encoding, Accept");
    }

    /**
     * Generate cryptographically secure nonce
     * 
     * @return string Base64 encoded nonce
     */
    public static function generateNonce(): string
    {
        $length = self::$config['csp_nonce_length'] ?? 32;
        return base64_encode(random_bytes($length));
    }

    /**
     * Set minimal security headers for development environment
     * 
     * Provides basic protection without breaking development workflow.
     * Allows inline scripts/styles and external resources commonly used in dev.
     * 
     * @return void
     */
    public static function setDevelopmentHeaders(): void
    {
        if (self::$headersSet || headers_sent()) {
            return;
        }

        // Relaxed CSP for development
        $devCSP = [
            'default-src' => "'self' 'unsafe-inline' 'unsafe-eval' data: blob:",
            'script-src' => "'self' 'unsafe-inline' 'unsafe-eval' data: blob: *",
            'style-src' => "'self' 'unsafe-inline' data: *",
            'img-src' => "'self' data: blob: *",
            'font-src' => "'self' data: *",
            'connect-src' => "'self' *",
            'media-src' => "'self' data: blob: *",
            'object-src' => "'self'",
            'child-src' => "'self' blob:",
            'frame-src' => "'self'",
            'worker-src' => "'self' blob:",
            'form-action' => "'self'"
        ];

        // Build and set relaxed CSP
        $cspString = self::buildCSPString($devCSP);
        header("Content-Security-Policy: {$cspString}");

        // Minimal security headers that don't break development
        header("X-Content-Type-Options: nosniff");
        header("X-Frame-Options: SAMEORIGIN"); // Less restrictive than DENY
        header("Referrer-Policy: strict-origin-when-cross-origin");

        // Allow development tools and debugging
        header("X-XSS-Protection: 0"); // Disabled to prevent false positives

        // Don't cache during development
        header("Cache-Control: no-cache, no-store, must-revalidate");
        header("Pragma: no-cache");
        header("Expires: 0");

        self::$headersSet = true;
    }

    /**
     * Set all security headers for maximum protection
     * 
     * @param array $cspOverrides Custom CSP overrides
     * @return void
     */
    public static function setAllSecurityHeaders(array $cspOverrides = []): void
    {
        $nonce = self::generateNonce();
        self::setSecurityHeaders($cspOverrides, $nonce);

        // Additional hardening headers
        header("Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=(), usb=()");
        header("X-Permitted-Cross-Domain-Policies: none");
        header("X-Robots-Tag: noindex, nofollow, nosnippet, noarchive");

        // Server information hiding
        if (function_exists('header_remove')) {
            header_remove('X-Powered-By');
            header_remove('Server');
        }
    }

    /**
     * Emergency security headers for compromised applications
     * 
     * @return void
     */
    public static function setEmergencySecurityHeaders(): void
    {
        self::setNoCacheHeaders();
        header("Content-Security-Policy: default-src 'none'");
        header("X-Frame-Options: DENY");
        header("X-Content-Type-Options: nosniff");
        header("Referrer-Policy: no-referrer");
        header("Clear-Site-Data: \"cache\", \"cookies\", \"storage\"");
    }

    // Private helper methods

    private static function addNonceToDirective(string $directive, string $nonce): string
    {
        return $directive . " 'nonce-{$nonce}'";
    }

    private static function buildCSPString(array $directives): string
    {
        $cspParts = [];
        foreach ($directives as $directive => $value) {
            if ($value === '') {
                $cspParts[] = $directive;
            } else {
                $cspParts[] = "{$directive} {$value}";
            }
        }
        return implode('; ', $cspParts);
    }

    private static function setAdditionalSecurityHeaders(): void
    {
        // Feature Policy / Permissions Policy
        $permissions = [
            'geolocation' => '()',
            'microphone' => '()',
            'camera' => '()',
            'payment' => '()',
            'usb' => '()',
            'magnetometer' => '()',
            'gyroscope' => '()',
            'accelerometer' => '()'
        ];

        $permissionsString = implode(', ', array_map(
            fn($key, $value) => "{$key}={$value}",
            array_keys($permissions),
            array_values($permissions)
        ));

        header("Permissions-Policy: {$permissionsString}");

        // Additional security headers
        header("X-Permitted-Cross-Domain-Policies: none");
        header("Expect-CT: max-age=86400, enforce");
    }

    private static function isSecureConnection(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
            (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
            (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
    }

    private static function isDevelopment(): bool
    {
        return (self::$config['environment'] ?? 'production') === 'development';
    }

    /**
     * Get the current nonce for use in templates
     * 
     * @return string|null Current nonce or null if not set
     */
    public static function getCurrentNonce(): ?string
    {
        return $_SERVER['CSP_NONCE'] ?? null;
    }

    /**
     * Store nonce in server globals for template access
     * 
     * @param string $nonce The nonce to store
     * @return void
     */
    public static function storeNonce(string $nonce): void
    {
        $_SERVER['CSP_NONCE'] = $nonce;
    }

    /**
     * Automatically set appropriate headers based on environment
     * 
     * @param array $cspOverrides Custom CSP overrides (only for production)
     * @return void
     */
    public static function setAppropriateHeaders(array $cspOverrides = []): void
    {
        self::init();

        if (self::isDevelopment()) {
            self::setDevelopmentHeaders();
        } else {
            self::setSecurityHeaders($cspOverrides);
        }
    }
}
