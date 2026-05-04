<?php

namespace Core\Security;

/**
 * CspNonce — per-request Content-Security-Policy nonce manager.
 *
 * Holds a single cryptographically random nonce value for the lifetime of the
 * current HTTP request.  The same instance is shared by the security-headers
 * middleware (to emit the CSP header) and by the Blade view engine (to inject
 * the value into <script nonce="…"> and <style nonce="…"> elements).
 *
 * Usage:
 *   $nonce = \Core\Security\CspNonce::get();   // same value every call per request
 *   \Core\Security\CspNonce::reset();           // call between requests in workers/tests
 *
 * @package  Core\Security
 */
final class CspNonce
{
    /**
     * Lazily-generated, per-request nonce value.
     * NULL until the first call to get() in this request lifecycle.
     */
    private static ?string $value = null;

    /**
     * Return the nonce for the current request.
     * Generates a new cryptographically-random value on the first call;
     * subsequent calls return the same cached value.
     *
     * @return string  Base64-encoded 16-byte random string (24 chars, no padding stripped).
     */
    public static function get(): string
    {
        if (self::$value === null) {
            self::$value = base64_encode(random_bytes(16));
        }

        return self::$value;
    }

    /**
     * Clear the stored nonce.
     * Must be called between HTTP requests in long-running PHP-CLI workers
     * (e.g. ReactPHP, Swoole, RoadRunner) and in test tear-downs to prevent
     * one request's nonce leaking into the next.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$value = null;
    }
}
