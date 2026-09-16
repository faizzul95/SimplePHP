<?php

declare(strict_types=1);

namespace Core\Security;

/**
 * Replay and tamper protection for API requests.
 *
 * This is the control a mobile client actually needs, and it is not CSRF. CSRF
 * defends against *ambient* credentials: the browser attaches a cookie to a
 * cross-site request all by itself, so the server cannot tell the victim's own
 * click from an attacker's page. A bearer token is not ambient — nothing attaches
 * it but the code that read it out of storage — so an attacker's page cannot
 * make an authenticated call in the first place, and a CSRF token added to a
 * bearer request protects nothing. It is a value the client fetches and echoes
 * back, and anyone able to make the request can fetch it too.
 *
 * What a token client is genuinely exposed to is *replay*: a request captured
 * once — from a proxied debug build, a logging middlebox, a rooted device — can
 * be sent again. TLS stops an outsider reading it; it does nothing once someone
 * has a copy. So each request carries a timestamp, a one-time nonce, and an HMAC
 * over the method, path and body. A replay fails on the nonce; a stale capture
 * fails on the clock; an altered body fails on the digest.
 *
 * The key is the caller's own access token, which arrives on the same request.
 * That makes the secret per-device and rotatable by revoking the token, with no
 * shared app secret shipped inside the binary for someone to extract.
 */
final class RequestSignature
{
    public const VERSION = 'v1';

    /** Bytes of separator-safe canonical form; the newline cannot appear in any part. */
    private const SEPARATOR = "\n";

    /**
     * The exact string both sides hash.
     *
     * The body is hashed rather than included so a large upload does not have to
     * be held in memory twice, and so the canonical form stays one line per part.
     */
    public static function canonical(
        string $method,
        string $path,
        string $timestamp,
        string $nonce,
        string $body
    ): string {
        return implode(self::SEPARATOR, [
            self::VERSION,
            strtoupper(trim($method)),
            '/' . ltrim(trim($path), '/'),
            trim($timestamp),
            trim($nonce),
            hash('sha256', $body),
        ]);
    }

    public static function sign(string $canonical, string $key, string $algorithm = 'sha256'): string
    {
        return self::VERSION . '=' . hash_hmac($algorithm, $canonical, $key);
    }

    /**
     * Constant-time comparison of a presented signature against the expected one.
     *
     * hash_equals, not ===: a byte-by-byte comparison leaks how much of the
     * signature was correct through timing, which is enough to forge one.
     */
    public static function matches(string $presented, string $canonical, string $key, string $algorithm = 'sha256'): bool
    {
        $presented = trim($presented);
        if ($presented === '') {
            return false;
        }

        // Accept a bare hex digest as well as the `v1=` form, so a client that
        // omits the prefix fails on the signature rather than on the format.
        if (!str_contains($presented, '=')) {
            $presented = self::VERSION . '=' . $presented;
        }

        return hash_equals(self::sign($canonical, $key, $algorithm), $presented);
    }

    /**
     * Whether a timestamp is close enough to now.
     *
     * Two-sided: a clock ahead of the server is as suspicious as one behind, and
     * accepting future timestamps would let a captured request be stockpiled for
     * later use with an arbitrarily long life.
     */
    public static function withinTolerance(string $timestamp, int $toleranceSeconds, ?int $now = null): bool
    {
        if (!ctype_digit(ltrim(trim($timestamp), '-'))) {
            return false;
        }

        $now ??= time();

        return abs($now - (int) $timestamp) <= max(0, $toleranceSeconds);
    }

    /**
     * A nonce has to be unguessable enough that an attacker cannot pre-burn the
     * one a legitimate client is about to use, and long enough not to collide.
     */
    public static function isWellFormedNonce(string $nonce, int $minimumLength = 16, int $maximumLength = 128): bool
    {
        $nonce = trim($nonce);
        $length = strlen($nonce);

        if ($length < $minimumLength || $length > $maximumLength) {
            return false;
        }

        // Printable ASCII without the separator, so it cannot forge a canonical
        // string by embedding a newline.
        return preg_match('/^[A-Za-z0-9._~-]+$/', $nonce) === 1;
    }

    /** Cache key for a burned nonce, scoped by the key so clients cannot burn each other's. */
    public static function nonceCacheKey(string $nonce, string $key): string
    {
        return 'reqsig_' . hash_hmac('sha256', $nonce, $key);
    }
}
