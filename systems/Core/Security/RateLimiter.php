<?php

declare(strict_types=1);

namespace Core\Security;

/**
 * Per-user (and per-IP fallback) rate limiter backed by the application cache.
 *
 * Prefers user ID over IP — prevents IP-rotation bypass while protecting
 * offices/NATs that share a single IP.
 *
 */
final class RateLimiter
{
    private const CACHE_PREFIX = 'rate_limiter_';

    /**
     * Build a rate limit cache key.
     * Prefers authenticated user ID; falls back to trusted-proxy-aware client IP.
     */
    public static function resolveKey(string $action, ?int $userId = null): string
    {
        if ($userId !== null) {
            return "rl:{$action}:user:{$userId}";
        }

        $ip = AuditLogger::resolveIp();
        return "rl:{$action}:ip:{$ip}";
    }

    /**
     * Increment counter and check against limit.
     * Returns true if the request is allowed, false if rate-limited.
     *
     * @param string $key          Key from resolveKey()
     * @param int    $maxAttempts  Maximum allowed attempts in the window
     * @param int    $decaySeconds Window duration in seconds
     */
    public static function attempt(string $key, int $maxAttempts, int $decaySeconds): bool
    {
        $cacheKey = self::CACHE_PREFIX . $key;
        $current  = (int) cache()->get($cacheKey, 0);

        if ($current >= $maxAttempts) {
            return false;
        }

        // Atomic first-write using SET NX (add returns false if key already existed).
        // This prevents a race where two concurrent requests both see $current === 0,
        // both call put(), and the counter is stuck at 1 instead of 2.
        if (!cache()->add($cacheKey, 1, $decaySeconds)) {
            // Key existed (another request won the race) — increment atomically
            cache()->increment($cacheKey);
        }

        return true;
    }

    /**
     * Check if the key has exceeded its limit without incrementing.
     */
    public static function tooManyAttempts(string $key, int $maxAttempts): bool
    {
        return (int) cache()->get(self::CACHE_PREFIX . $key, 0) >= $maxAttempts;
    }

    /**
     * Current attempt count for a key.
     */
    public static function attempts(string $key): int
    {
        return (int) cache()->get(self::CACHE_PREFIX . $key, 0);
    }

    /**
     * Reset (clear) the counter for a key.
     * Call this on successful authentication to clear login attempt counters.
     */
    public static function resetAttempts(string $key): void
    {
        cache()->forget(self::CACHE_PREFIX . $key);
    }

    /**
     * How many more attempts are permitted before the key is blocked.
     */
    public static function remainingAttempts(string $key, int $maxAttempts): int
    {
        return max(0, $maxAttempts - self::attempts($key));
    }

    /**
     * Remaining TTL in seconds until the rate limit window resets.
     * Returns 0 if not limited.
     */
    public static function availableIn(string $key): int
    {
        $meta = cache()->getMetadata(self::CACHE_PREFIX . $key);
        return (int) ($meta['expires_in'] ?? 0);
    }

    /**
     * Convenience: rate-limit a login attempt and optionally log brute force.
     *
     * @return bool true = allowed, false = rate-limited
     */
    public static function throttleLogin(string $identifier, ?int $userId = null): bool
    {
        $config  = config('security.rate_limiting.login', []);
        $max     = (int) ($config['max_attempts'] ?? 5);
        $decay   = (int) ($config['decay_seconds'] ?? 300);

        $key     = self::resolveKey('login', $userId);
        $allowed = self::attempt($key, $max, $decay);

        if (!$allowed) {
            AuditLogger::bruteForce($identifier, self::attempts($key));
        }

        return $allowed;
    }
}
