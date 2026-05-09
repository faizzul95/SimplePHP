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
     * Uses an increment-first strategy to eliminate the check-then-act (TOCTOU)
     * race condition. The counter is atomically incremented before the comparison,
     * so two concurrent requests near the limit cannot both slip through.
     *
     * @param string $key          Key from resolveKey()
     * @param int    $maxAttempts  Maximum allowed attempts in the window
     * @param int    $decaySeconds Window duration in seconds
     */
    public static function attempt(string $key, int $maxAttempts, int $decaySeconds): bool
    {
        $cacheKey = self::CACHE_PREFIX . $key;

        // Increment-first: atomically create key at 1 (with TTL) on first request,
        // or increment existing counter without touching TTL on subsequent requests.
        // This means the comparison happens AFTER the counter is already committed,
        // preventing two concurrent requests from both reading the same "below limit"
        // value and both being permitted.
        if (!cache()->add($cacheKey, 1, $decaySeconds)) {
            cache()->increment($cacheKey);
        }

        $count = (int) cache()->get($cacheKey, 1);
        return $count <= $maxAttempts;
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
