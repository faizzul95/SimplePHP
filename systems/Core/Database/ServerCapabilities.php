<?php

declare(strict_types=1);

namespace Core\Database;

/**
 * What the connected server can actually do.
 *
 * The queue's job-claim query uses `FOR UPDATE SKIP LOCKED`, which is the correct
 * way to let N workers each take a different row instead of serialising behind the
 * same one. It is also a syntax error on MySQL below 8.0 and MariaDB below 10.6 —
 * so on those servers the queue did not degrade, it stopped working entirely.
 *
 * Version strings are cached per connection: the answer cannot change while the
 * process is running, and SELECT VERSION() on every job claim would defeat the
 * point of the optimisation.
 */
final class ServerCapabilities
{
    /** @var array<string, bool> connection => supports SKIP LOCKED */
    private static array $skipLockedCache = [];

    /**
     * Whether `SKIP LOCKED` / `NOWAIT` row-locking modifiers are available.
     *
     * MySQL     >= 8.0.1
     * MariaDB   >= 10.6.0
     * Anything else: assume not.
     */
    public static function supportsSkipLocked(string $versionString, string $cacheKey = ''): bool
    {
        if ($cacheKey !== '' && array_key_exists($cacheKey, self::$skipLockedCache)) {
            return self::$skipLockedCache[$cacheKey];
        }

        $supported = self::evaluateSkipLocked($versionString);

        if ($cacheKey !== '') {
            self::$skipLockedCache[$cacheKey] = $supported;
        }

        return $supported;
    }

    private static function evaluateSkipLocked(string $versionString): bool
    {
        $version = self::numericVersion($versionString);

        if ($version === null) {
            return false;
        }

        // MariaDB advertises itself inside the version string, and its numbering
        // is unrelated to MySQL's — 10.5 is older than MySQL 8.0 in capability
        // terms despite the larger major number.
        if (stripos($versionString, 'mariadb') !== false) {
            return version_compare($version, '10.6.0', '>=');
        }

        return version_compare($version, '8.0.1', '>=');
    }

    /**
     * Pull the dotted numeric version out of a server banner.
     *
     * Real examples this has to survive:
     *   "8.0.36"
     *   "5.5.5-10.11.6-MariaDB-1:10.11.6+maria~ubu2204"
     *   "10.6.16-MariaDB-log"
     *
     * The MariaDB 5.5.5 prefix is a compatibility lie the server tells old
     * clients; the real version is the second component.
     */
    public static function numericVersion(string $versionString): ?string
    {
        $versionString = trim($versionString);

        if ($versionString === '') {
            return null;
        }

        if (stripos($versionString, 'mariadb') !== false
            && preg_match('/(?:^|[^\d.])(\d+\.\d+\.\d+)[^\d]*mariadb/i', $versionString, $matches) === 1
        ) {
            return $matches[1];
        }

        if (preg_match('/^(\d+\.\d+(?:\.\d+)?)/', $versionString, $matches) === 1) {
            // Skip MariaDB's 5.5.5 compatibility prefix.
            if ($matches[1] === '5.5.5' && preg_match('/5\.5\.5-(\d+\.\d+\.\d+)/', $versionString, $real) === 1) {
                return $real[1];
            }

            return $matches[1];
        }

        return null;
    }

    /** Reset the per-connection cache (worker cycles and tests). */
    public static function reset(): void
    {
        self::$skipLockedCache = [];
    }
}
