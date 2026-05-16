<?php

declare(strict_types=1);

namespace Core\Assets;

final class AssetVersioner
{
    /** @var array<string, string> */
    private static array $runtimeCache = [];

    public static function versionForPublicAsset(string $relativePath): string
    {
        $relativePath = ltrim(trim($relativePath), '/');
        if ($relativePath === '') {
            return self::fallbackVersion();
        }

        return self::hash(ROOT_DIR . 'public/' . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath));
    }

    public static function hash(string $filePath): string
    {
        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $filePath);
        if (!is_file($normalized) || !is_readable($normalized)) {
            return self::fallbackVersion();
        }

        $mtime = (int) (@filemtime($normalized) ?: 0);
        $size = (int) (@filesize($normalized) ?: 0);
        $fingerprint = $normalized . '|' . $mtime . '|' . $size;

        if (isset(self::$runtimeCache[$fingerprint])) {
            return self::$runtimeCache[$fingerprint];
        }

        $cacheKey = 'asset_version:' . md5($fingerprint);
        if (function_exists('cache')) {
            try {
                $cached = cache($cacheKey, null);
                if (is_string($cached) && $cached !== '') {
                    self::$runtimeCache[$fingerprint] = $cached;
                    return $cached;
                }
            } catch (\Throwable) {
                // Degrade to runtime-only caching when the configured cache store is unavailable.
            }
        }

        $hash = md5_file($normalized);
        $version = substr($hash !== false ? $hash : md5($fingerprint), 0, 12);
        self::$runtimeCache[$fingerprint] = $version;

        if (function_exists('cache')) {
            try {
                cache([$cacheKey => $version], max(1, (int) config('assets.cache_ttl', 3600)));
            } catch (\Throwable) {
                // Runtime cache already contains the value.
            }
        }

        return $version;
    }

    private static function fallbackVersion(): string
    {
        return trim((string) config('assets.fallback_version', 'dev')) ?: 'dev';
    }
}