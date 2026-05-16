<?php

namespace Core\Http;

class Response
{
    /** @var array<int, string> */
    private static array $pendingLinkHeaders = [];

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

    /**
     * Add stale-while-revalidate and optional stale-if-error to the current
     * Cache-Control header. Call AFTER cache() to extend the directive list.
     *
     * stale-while-revalidate: serve stale content while fetching a fresh copy in background.
     * stale-if-error:         serve stale content when the origin is returning errors.
     *
     * @param int $whileRevalidate  Seconds to serve stale while revalidating (default 30)
     * @param int $ifError          Seconds to serve stale on error (default 86400 = 1 day)
     */
    public static function staleWhileRevalidate(int $whileRevalidate = 30, int $ifError = 86400): void
    {
        header('Cache-Control: stale-while-revalidate=' . max(0, $whileRevalidate)
            . ', stale-if-error=' . max(0, $ifError), false);
    }

    /**
     * Set or append to the Vary header.
     * Sending multiple Vary values is merged by browsers/CDNs correctly.
     *
     * @param string|string[] $fields  Header field name(s) to vary on
     */
    public static function vary(string|array $fields): void
    {
        $clean = array_map(
            static fn(string $f): string => preg_replace('/[^\w-]/', '', trim($f)),
            is_array($fields) ? $fields : [$fields]
        );
        $clean = array_filter($clean);
        if (!empty($clean)) {
            header('Vary: ' . implode(', ', $clean), false);
        }
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

    /**
     * Set ETag + Last-Modified and return true when the browser cache is
     * still valid (a 304 has already been sent; caller should return immediately).
     *
     * Usage:
     *   $html = view('home');
     *   if (Response::withCacheHeaders($html)) return;
     *   echo $html;
     *
     * Shared hosting safe: no external service needed; pure HTTP headers.
     *
     * @param string                  $content      Full response body to fingerprint.
     * @param \DateTimeImmutable|null $lastModified  Defaults to now.
     * @return bool  true = 304 sent and caller should stop; false = send full response.
     */
    public static function withCacheHeaders(string $content, ?\DateTimeImmutable $lastModified = null): bool
    {
        $etag         = '"' . md5($content) . '"';
        $lastModified = $lastModified ?? new \DateTimeImmutable();

        header('ETag: ' . $etag);
        header('Last-Modified: ' . $lastModified->format('D, d M Y H:i:s') . ' GMT');
        header('Vary: Accept-Encoding, Accept');

        $ifNoneMatch = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
        $ifModSince  = trim((string) ($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? ''));

        if ($ifNoneMatch !== '' && hash_equals($etag, $ifNoneMatch)) {
            http_response_code(304);
            return true;
        }

        if ($ifModSince !== '') {
            $since = \DateTimeImmutable::createFromFormat('D, d M Y H:i:s T', $ifModSince);
            if ($since !== false && $lastModified <= $since) {
                http_response_code(304);
                return true;
            }
        }

        return false;
    }

    public static function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        self::flushPendingLinkHeaders();
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($data);
        exit;
    }

    public static function redirect(string $url, int $status = 302): void
    {
        self::sendRedirectHeaders($url, $status);
        exit;
    }

    public static function preload(string $url, string $as, ?string $type = null, bool $crossOrigin = false): void
    {
        $header = self::buildLinkHeaderValue($url, 'preload', $as, $type, $crossOrigin);
        if ($header === '') {
            return;
        }

        self::$pendingLinkHeaders[] = $header;
        header('Link: ' . $header, false);
    }

    public static function prefetch(string $url): void
    {
        $header = self::buildLinkHeaderValue($url, 'prefetch');
        if ($header === '') {
            return;
        }

        self::$pendingLinkHeaders[] = $header;
        header('Link: ' . $header, false);
    }

    public static function buildLinkHeaderValue(string $url, string $rel, ?string $as = null, ?string $type = null, bool $crossOrigin = false): string
    {
        $url = trim(str_replace(["\r", "\n", "\0"], '', $url));
        $rel = trim($rel);
        if ($url === '' || $rel === '') {
            return '';
        }

        $parts = ['<' . $url . '>', 'rel=' . $rel];
        if ($as !== null && trim($as) !== '') {
            $parts[] = 'as=' . trim($as);
        }
        if ($type !== null && trim($type) !== '') {
            $parts[] = 'type="' . str_replace('"', '', trim($type)) . '"';
        }
        if ($crossOrigin) {
            $parts[] = 'crossorigin';
        }

        return implode('; ', $parts);
    }

    public static function pendingLinkHeaders(): array
    {
        return self::$pendingLinkHeaders;
    }

    public static function resetLinkHeaders(): void
    {
        self::$pendingLinkHeaders = [];
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

    private static function flushPendingLinkHeaders(): void
    {
        foreach (self::$pendingLinkHeaders as $headerValue) {
            header('Link: ' . $headerValue, false);
        }
    }
}
