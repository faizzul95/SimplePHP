<?php

namespace Core\Http;

class Response
{
    public static function sanitizeRedirectTarget(string $url, bool $allowExternal = false): string
    {
        $target = trim(str_replace(["\r", "\n", "\0"], '', $url));
        if ($target === '' || str_starts_with($target, '//')) {
            return '/';
        }

        if (preg_match('#^(javascript|data|vbscript|file|phar|php):#i', $target) === 1) {
            return '/';
        }

        $parts = parse_url($target);
        if ($parts === false) {
            return '/';
        }

        $isAbsolute = isset($parts['scheme']) || isset($parts['host']);
        if (!$isAbsolute) {
            return str_starts_with($target, '/') ? $target : '/' . ltrim($target, '/');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return '/';
        }

        $targetHost = self::normalizeHost((string) ($parts['host'] ?? ''));
        if ($targetHost === '') {
            return '/';
        }

        $requestHost = self::normalizedCurrentHost();
        if ($requestHost !== '' && hash_equals($requestHost, $targetHost)) {
            return $target;
        }

        // External host — require it to appear on the redirect allowlist even
        // when the caller passed $allowExternal = true. This prevents
        // away($userInput) from redirecting to attacker-controlled domains.
        if ($allowExternal && self::isAllowedExternalHost($targetHost)) {
            return $target;
        }

        return '/';
    }

    private static function isAllowedExternalHost(string $host): bool
    {
        if ($host === '' || !function_exists('config')) {
            return false;
        }

        try {
            $security = config('security');
        } catch (\Throwable $e) {
            return false;
        }

        $allowed = (array) ($security['redirects']['allowed_hosts'] ?? []);
        foreach ($allowed as $entry) {
            $normalized = self::normalizeHost((string) $entry);
            if ($normalized !== '' && hash_equals($normalized, $host)) {
                return true;
            }
        }

        return false;
    }

    public static function sendRedirectHeaders(string $url, int $status = 302, array $headers = [], bool $allowExternal = false): void
    {
        $safeUrl = self::sanitizeRedirectTarget($url, $allowExternal);

        foreach ($headers as $name => $value) {
            if (!is_string($name) || !is_scalar($value)) {
                continue;
            }

            $headerName = str_replace(["\r", "\n", "\0"], '', $name);
            $headerValue = str_replace(["\r", "\n", "\0"], '', (string) $value);
            if ($headerName === '') {
                continue;
            }

            header($headerName . ': ' . $headerValue, false);
        }

        header('Location: ' . $safeUrl, true, $status);
    }

    public static function cache(int $seconds, bool $public = true, bool $immutable = false): void
    {
        $seconds = max(0, $seconds);

        $directives = [
            $public ? 'public' : 'private',
            'max-age=' . $seconds,
            's-maxage=' . $seconds,
        ];

        if ($immutable && $seconds > 0) {
            $directives[] = 'immutable';
        }

        header('Cache-Control: ' . implode(', ', $directives));
        header('Pragma: cache');
        header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $seconds) . ' GMT');
    }

    public static function noCache(): void
    {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
    }

    public static function etag(string $etag): void
    {
        $safe = trim(str_replace(['"', "\r", "\n"], '', $etag));
        if ($safe === '') {
            return;
        }

        header('ETag: "' . $safe . '"');
    }

    public static function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($data);
        exit;
    }

    public static function redirect(string $url, int $status = 302): void
    {
        self::sendRedirectHeaders($url, $status);
        exit;
    }

    private static function normalizedCurrentHost(): string
    {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '');
        return self::normalizeHost($host);
    }

    private static function normalizeHost(string $host): string
    {
        $host = trim($host);
        if ($host === '') {
            return '';
        }

        if (function_exists('security')) {
            try {
                return (string) security()->normalizeHostHeader($host);
            } catch (\Throwable $e) {
                return '';
            }
        }

        $host = preg_replace('/:\d+$/', '', strtolower($host));
        return preg_match('/^[a-z0-9.-]+$/', $host) === 1 ? $host : '';
    }
}
