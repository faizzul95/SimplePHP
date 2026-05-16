<?php

declare(strict_types=1);

namespace Core\Http;

final class CookieFactory
{
    public static function normalizeSameSite(string $sameSite, string $default = 'Lax'): string
    {
        $sameSite = ucfirst(strtolower(trim($sameSite)));
        if (!in_array($sameSite, ['Lax', 'Strict', 'None'], true)) {
            return ucfirst(strtolower($default));
        }

        return $sameSite;
    }

    public static function applyPrefix(string $name, bool $secure, string $path = '/', string $domain = '', bool $preferHostPrefix = false): string
    {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Cookie name cannot be empty.');
        }

        if (str_starts_with($name, '__Host-') || str_starts_with($name, '__Secure-')) {
            return $name;
        }

        if ($preferHostPrefix && $secure && trim($domain) === '' && rtrim($path, '/') === '') {
            return '__Host-' . $name;
        }

        if ($secure) {
            return '__Secure-' . $name;
        }

        return $name;
    }

    public static function buildHeader(
        string $name,
        string $value,
        int $expireSeconds = 0,
        string $path = '/',
        string $domain = '',
        bool $secure = true,
        bool $httpOnly = true,
        string $sameSite = 'Lax',
        bool $partitioned = false
    ): string {
        $sameSite = self::normalizeSameSite($sameSite);
        if ($partitioned && (!$secure || $sameSite !== 'None')) {
            throw new \InvalidArgumentException('Partitioned cookies require Secure=true and SameSite=None.');
        }

        $parts = [rawurlencode($name) . '=' . rawurlencode($value)];
        if ($expireSeconds > 0) {
            $parts[] = 'Expires=' . gmdate('D, d M Y H:i:s \G\M\T', time() + $expireSeconds);
            $parts[] = 'Max-Age=' . $expireSeconds;
        }

        $parts[] = 'Path=' . ($path !== '' ? $path : '/');
        if (trim($domain) !== '') {
            $parts[] = 'Domain=' . trim($domain);
        }
        if ($secure) {
            $parts[] = 'Secure';
        }
        if ($httpOnly) {
            $parts[] = 'HttpOnly';
        }

        $parts[] = 'SameSite=' . $sameSite;
        if ($partitioned) {
            $parts[] = 'Partitioned';
        }

        return 'Set-Cookie: ' . implode('; ', $parts);
    }

    public static function send(
        string $name,
        string $value,
        int $expireSeconds = 0,
        string $path = '/',
        string $domain = '',
        bool $secure = true,
        bool $httpOnly = true,
        string $sameSite = 'Lax',
        bool $partitioned = false
    ): bool {
        if (headers_sent()) {
            return false;
        }

        header(self::buildHeader($name, $value, $expireSeconds, $path, $domain, $secure, $httpOnly, $sameSite, $partitioned), false);
        return true;
    }
}