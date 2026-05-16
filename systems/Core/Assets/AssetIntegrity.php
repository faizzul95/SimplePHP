<?php

declare(strict_types=1);

namespace Core\Assets;

final class AssetIntegrity
{
    /** @var array<string, string> */
    private static array $runtimeCache = [];

    public static function attributes(string $asset, ?string $manualHash = null): string
    {
        $asset = trim($asset);
        if ($asset === '') {
            return '';
        }

        $manualHash = trim((string) $manualHash);
        if ($manualHash !== '') {
            return self::buildAttributes($manualHash, self::isCrossOriginAsset($asset));
        }

        if (self::isCrossOriginAsset($asset)) {
            return '';
        }

        $digest = self::digestForPublicAsset($asset);
        if ($digest === '') {
            return '';
        }

        return self::buildAttributes($digest, false);
    }

    public static function digestForPublicAsset(string $relativePath): string
    {
        $relativePath = ltrim(trim($relativePath), '/');
        if ($relativePath === '') {
            return '';
        }

        return self::digestForFile(ROOT_DIR . 'public/' . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath));
    }

    public static function digestForFile(string $filePath): string
    {
        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $filePath);
        if (!is_file($normalized) || !is_readable($normalized)) {
            return '';
        }

        $algorithm = self::algorithm();
        $mtime = (int) (@filemtime($normalized) ?: 0);
        $size = (int) (@filesize($normalized) ?: 0);
        $fingerprint = $algorithm . '|' . $normalized . '|' . $mtime . '|' . $size;

        if (isset(self::$runtimeCache[$fingerprint])) {
            return self::$runtimeCache[$fingerprint];
        }

        $cacheKey = 'asset_integrity:' . md5($fingerprint);
        if (function_exists('cache')) {
            try {
                $cached = cache($cacheKey, null);
                if (is_string($cached) && $cached !== '') {
                    self::$runtimeCache[$fingerprint] = $cached;
                    return $cached;
                }
            } catch (\Throwable) {
                // Fall through to file hashing.
            }
        }

        $hash = hash_file($algorithm, $normalized, true);
        if ($hash === false) {
            return '';
        }

        $digest = $algorithm . '-' . base64_encode($hash);
        self::$runtimeCache[$fingerprint] = $digest;

        if (function_exists('cache')) {
            try {
                cache([$cacheKey => $digest], max(1, (int) config('assets.cache_ttl', 3600)));
            } catch (\Throwable) {
                // Runtime cache is enough.
            }
        }

        return $digest;
    }

    public static function algorithm(): string
    {
        $configured = strtolower(trim((string) config('assets.sri_algorithm', 'sha384')));

        return in_array($configured, ['sha256', 'sha384', 'sha512'], true) ? $configured : 'sha384';
    }

    private static function buildAttributes(string $digest, bool $crossOrigin): string
    {
        $attributes = 'integrity="' . htmlspecialchars($digest, ENT_QUOTES, 'UTF-8') . '"';
        if ($crossOrigin) {
            $attributes .= ' crossorigin="anonymous"';
        }

        return $attributes;
    }

    private static function isCrossOriginAsset(string $asset): bool
    {
        return preg_match('#^(?:[a-z][a-z0-9+\-.]*:)?//#i', $asset) === 1;
    }
}