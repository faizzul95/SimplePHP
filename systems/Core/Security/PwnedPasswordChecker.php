<?php

declare(strict_types=1);

namespace Core\Security;

/**
 * HaveIBeenPwned k-anonymity password breach checker.
 *
 * Sends only the first 5 characters of the SHA-1 hash to HIBP.
 * The full hash never leaves the server — k-anonymity guarantees privacy.
 *
 * Fails open: if the API is unreachable, returns 0 (safe) so logins are
 * never blocked by third-party downtime.
 *
 * Enable in .env: PWNED_PASSWORD_CHECK=true
 *
 */
final class PwnedPasswordChecker
{
    private const API_URL    = 'https://api.pwnedpasswords.com/range/';
    private const TIMEOUT_S  = 3;

    /**
     * Return the number of times this password appears in breach databases.
     * 0 = not found (safe). >0 = breached (warn user but do not block login).
     *
     * @param string $password Plaintext password (only SHA-1 prefix sent to API)
     */
    public static function timesBreached(string $password): int
    {
        if (!config('security.password.pwned_check', false)) {
            return 0;
        }

        $sha1   = strtoupper(sha1($password));
        $prefix = substr($sha1, 0, 5);
        $suffix = substr($sha1, 5);

        $response = self::fetchRange($prefix);
        if ($response === null) {
            return 0; // Fail open — API unreachable
        }

        foreach (explode("\n", $response) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            [$hashSuffix, $count] = array_pad(explode(':', $line, 2), 2, '0');

            if (strtoupper($hashSuffix) === $suffix) {
                return (int) $count;
            }
        }

        return 0;
    }

    /**
     * Returns true if the password is found in any breach database.
     * Fails open (returns false) if the API is unreachable.
     */
    public static function isCompromised(string $password): bool
    {
        return self::timesBreached($password) > 0;
    }

    /**
     * Fetch the HIBP range response for a given 5-char SHA-1 prefix.
     * Returns null on any HTTP or network error (fail open).
     */
    private static function fetchRange(string $prefix): ?string
    {
        if (!function_exists('curl_init')) {
            return null; // cURL not available — fail open
        }

        try {
            $ch = curl_init(self::API_URL . $prefix);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => self::TIMEOUT_S,
                // Separate connect timeout — prevents hanging on slow DNS or
                // unreachable hosts for the full TIMEOUT_S duration.
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT      => 'MythPHP-Security/1.0',
                CURLOPT_HTTPHEADER     => ['Add-Padding: true'],  // Prevents traffic analysis
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($response === false || $httpCode !== 200) {
                return null;
            }

            return (string) $response;
        } catch (\Throwable) {
            return null;
        }
    }
}
