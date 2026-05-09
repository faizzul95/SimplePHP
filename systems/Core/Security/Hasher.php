<?php

declare(strict_types=1);

namespace Core\Security;

/**
 * Argon2id Password Hasher.
 *
 * OWASP-recommended password hashing for PHP 8.2+.
 * Replaces all password_hash() / password_verify() calls in the codebase.
 *
 */
final class Hasher
{
    // OWASP 2024 recommended minimums for Argon2id
    private const MEMORY_COST = 65536;  // 64 MB
    private const TIME_COST   = 4;
    private const THREADS     = 2;

    /**
     * Dummy hash used when user is not found.
     * Prevents timing-based account enumeration by normalising response time.
     */
    private const DUMMY_HASH = '$argon2id$v=19$m=65536,t=4,p=2$ZHVtbXlzYWx0ZHVtbXlzYWx0$dummyhashvaluethatnevermatchesanythingXXXXXXX';

    /**
     * Hash a plaintext password using Argon2id.
     *
     * @throws \RuntimeException if hashing fails (should never happen on valid PHP install)
     */
    public static function make(string $value): string
    {
        $hash = password_hash($value, PASSWORD_ARGON2ID, [
            'memory_cost' => self::MEMORY_COST,
            'time_cost'   => self::TIME_COST,
            'threads'     => self::THREADS,
        ]);

        if ($hash === false) {
            throw new \RuntimeException('Password hashing failed.');
        }

        return $hash;
    }

    /**
     * Verify a plaintext value against a stored hash.
     * Works for both Argon2id hashes and legacy bcrypt hashes (transparent upgrade path).
     */
    public static function verify(string $value, string $hashedValue): bool
    {
        return password_verify($value, $hashedValue);
    }

    /**
     * Check whether a stored hash needs to be upgraded to the current algorithm/parameters.
     * Returns true for bcrypt hashes (upgrade to Argon2id on next successful login).
     */
    public static function needsRehash(string $hashedValue): bool
    {
        return password_needs_rehash($hashedValue, PASSWORD_ARGON2ID, [
            'memory_cost' => self::MEMORY_COST,
            'time_cost'   => self::TIME_COST,
            'threads'     => self::THREADS,
        ]);
    }

    /**
     * Run a dummy verify when no user record exists.
     * Prevents timing-based account enumeration — attacker cannot tell if user exists.
     *
     * Call this whenever the user lookup returns null:
     *   if ($user === null) { Hasher::dummyVerify($password); return null; }
     */
    public static function dummyVerify(string $value): void
    {
        password_verify($value, self::DUMMY_HASH);
    }

    /**
     * Hash an API token or secret for storage.
     * Tokens MUST be stored as hashes, never plaintext.
     *
     * Use SHA-256 (not Argon2id) for tokens — they are already high-entropy random values.
     */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Timing-safe string comparison.
     * Use for ALL token/secret comparisons — never use === for secrets.
     *
     * @param string $known     The expected / trusted value (e.g., stored token hash)
     * @param string $userInput The value supplied by the user / request
     */
    public static function equals(string $known, string $userInput): bool
    {
        return hash_equals($known, $userInput);
    }
}
