<?php

declare(strict_types=1);

namespace Core\Security;

/**
 * HMAC-signed time-limited URL generator / verifier.
 *
 * Use for password-reset links, email-verification links, and private file downloads.
 * Requires APP_KEY to be set in .env.
 *
 * Usage:
 *   $url = SignedUrl::generate('/password/reset?email=user@example.com', 3600);
 *   if (!SignedUrl::verify($url)) { abort(403); }
 *
 */
final class SignedUrl
{
    private const ALGO = 'sha256';

    /**
     * Generate a signed URL with an expiry timestamp.
     *
     * @param string $url              Base URL (absolute or relative)
     * @param int    $expiresInSeconds Validity window in seconds (default: 1 hour)
     * @return string                  URL with ?expires=&signature= appended
     */
    public static function generate(string $url, int $expiresInSeconds = 3600): string
    {
        $expires   = time() + $expiresInSeconds;
        $signature = self::sign($url, $expires);

        $separator = str_contains($url, '?') ? '&' : '?';

        return $url . $separator . http_build_query([
            'expires'   => $expires,
            'signature' => $signature,
        ]);
    }

    /**
     * Verify a signed URL.
     * Returns false if expired or signature doesn't match.
     *
     * @param string $url Full URL including signature parameters
     */
    public static function verify(string $url): bool
    {
        $parsed = parse_url($url);
        parse_str($parsed['query'] ?? '', $params);

        $expires   = (int) ($params['expires'] ?? 0);
        $signature = (string) ($params['signature'] ?? '');

        // Reject expired URLs
        if ($expires < time()) {
            return false;
        }

        // Reconstruct the base URL without signature parameters.
        // Use explode instead of strtok — strtok() modifies global internal state.
        $baseUrl = explode('?', $url, 2)[0];
        unset($params['expires'], $params['signature']);

        $baseUrl .= empty($params) ? '' : '?' . http_build_query($params);

        $expected = self::sign($baseUrl, $expires);

        // Timing-safe comparison
        return hash_equals($expected, $signature);
    }

    /**
     * Generate a signed URL for a temporary file download.
     *
     * @param string $relativePath Relative path within storage/uploads/
     * @param int    $expiresInSeconds
     */
    public static function forFile(string $relativePath, int $expiresInSeconds = 300): string
    {
        $baseUrl = '/files/serve/' . ltrim($relativePath, '/');
        return self::generate($baseUrl, $expiresInSeconds);
    }

    /**
     * HMAC-sign the base URL + expiry.
     *
     * @throws \RuntimeException if APP_KEY is not configured
     */
    private static function sign(string $url, int $expires): string
    {
        $appKey = config('app.key') ?? null;

        if ($appKey === null || $appKey === '') {
            throw new \RuntimeException('APP_KEY is not set. Run: php myth key:generate');
        }

        return hash_hmac(self::ALGO, $url . '|' . $expires, (string) $appKey);
    }
}
