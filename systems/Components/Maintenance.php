<?php

namespace Components;

class Maintenance
{
    private array $config;
    private ?array $payloadCache = null;
    private bool $payloadLoaded = false;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    public function handleRequest(): void
    {
        if ($this->runsInCli()) {
            return;
        }

        $payload = $this->payload();
        if ($payload === null) {
            return;
        }

        if ($this->shouldIssueBypassCookie($payload)) {
            $this->issueBypassCookie($payload);
            \Core\Http\Response::sendRedirectHeaders($this->bypassRedirectTarget($payload), 302, [], true);
            exit;
        }

        if ($this->hasValidBypassCookie($payload)) {
            return;
        }

        if ($this->shouldRedirectRequest($payload)) {
            \Core\Http\Response::sendRedirectHeaders($this->redirectTarget($payload), 302, [], true);
            exit;
        }

        $this->sendMaintenanceResponse($payload);
        exit;
    }

    public function active(): bool
    {
        return $this->payload() !== null;
    }

    public function payload(): ?array
    {
        if ($this->payloadLoaded) {
            return $this->payloadCache;
        }

        $this->payloadLoaded = true;
        $file = $this->dataFilePath();
        if (!is_file($file) || !is_readable($file)) {
            return $this->payloadCache = null;
        }

        $raw = @file_get_contents($file);
        if (!is_string($raw) || trim($raw) === '') {
            return $this->payloadCache = [];
        }

        $decoded = json_decode($raw, true);
        return $this->payloadCache = is_array($decoded) ? $decoded : [];
    }

    private function sendMaintenanceResponse(array $payload): void
    {
        $statusCode = $this->statusCode($payload);
        $retryAfterSeconds = $this->retryAfterSeconds($payload);
        $refreshAfterSeconds = $this->refreshAfterSeconds($payload);
        $redirectTarget = $this->redirectTarget($payload);

        if (!headers_sent()) {
            http_response_code($statusCode);

            if ($retryAfterSeconds !== null) {
                header('Retry-After: ' . $retryAfterSeconds);
            }

            if ($refreshAfterSeconds !== null) {
                $refreshHeader = (string) $refreshAfterSeconds;
                if ($redirectTarget !== null && $redirectTarget !== '') {
                    $refreshHeader .= ';url=' . $redirectTarget;
                }

                header('Refresh: ' . $refreshHeader);
            }
        }

        $title = 'Maintenance Mode';
        $message = trim((string) ($payload['message'] ?? 'Service Unavailable'));
        if ($message === '') {
            $message = 'Service Unavailable';
        }

        $viewPath = $this->viewPath($payload);
        if (is_file($viewPath)) {
            require $viewPath;
            return;
        }

        echo $title . ': ' . $message;
    }

    private function shouldIssueBypassCookie(array $payload): bool
    {
        $secret = $this->bypassSecret($payload);
        if ($secret === '') {
            return false;
        }

        return hash_equals(trim($secret, '/'), trim($this->relativeRequestPath(), '/'));
    }

    private function issueBypassCookie(array $payload): void
    {
        $secret = $this->bypassSecret($payload);
        if ($secret === '') {
            return;
        }

        $cookiePath = '/' . trim($this->appBasePath(), '/');
        if ($cookiePath === '//') {
            $cookiePath = '/';
        }

        $sameSite = trim((string) (($this->config['bypass_cookie']['same_site'] ?? 'Lax')));
        if (!in_array($sameSite, ['Lax', 'Strict', 'None'], true)) {
            $sameSite = 'Lax';
        }

        setcookie($this->bypassCookieName(), $this->bypassCookieValue($secret), [
            'expires' => time() + $this->bypassCookieTtl(),
            'path' => $cookiePath === '/' ? '/' : rtrim($cookiePath, '/') . '/',
            'secure' => $this->isHttpsRequest(),
            'httponly' => true,
            'samesite' => $sameSite,
        ]);
    }

    private function hasValidBypassCookie(array $payload): bool
    {
        $secret = $this->bypassSecret($payload);
        if ($secret === '') {
            return false;
        }

        $cookieName = $this->bypassCookieName();
        if (!isset($_COOKIE[$cookieName])) {
            return false;
        }

        return hash_equals($this->bypassCookieValue($secret), (string) $_COOKIE[$cookieName]);
    }

    private function bypassSecret(array $payload): string
    {
        $configured = trim((string) ($this->config['secret'] ?? ''));
        $active = trim((string) ($payload['secret'] ?? ''));

        return $active !== '' ? $active : $configured;
    }

    private function bypassCookieName(): string
    {
        $name = trim((string) (($this->config['bypass_cookie']['name'] ?? 'myth_maintenance')));
        return $name !== '' ? $name : 'myth_maintenance';
    }

    private function bypassCookieTtl(): int
    {
        return max(60, (int) (($this->config['bypass_cookie']['ttl'] ?? 43200)));
    }

    private function bypassCookieValue(string $secret): string
    {
        $appKey = (string) env('APP_KEY', '');
        $key = $appKey !== '' ? $appKey : 'myth-maintenance-bypass';

        return hash_hmac('sha256', $secret, $key);
    }

    private function retryAfterSeconds(array $payload): ?int
    {
        return isset($payload['retry']) ? max(0, (int) $payload['retry']) : null;
    }

    private function refreshAfterSeconds(array $payload): ?int
    {
        return isset($payload['refresh']) ? max(0, (int) $payload['refresh']) : null;
    }

    private function statusCode(array $payload): int
    {
        $status = (int) ($payload['status'] ?? 503);
        return $status >= 100 && $status <= 599 ? $status : 503;
    }

    private function bypassRedirectTarget(array $payload): string
    {
        $configured = $this->redirectTarget($payload);
        if ($configured !== null && $configured !== '') {
            return $configured;
        }

        return defined('BASE_URL') ? (string) BASE_URL : '/';
    }

    private function redirectTarget(array $payload): ?string
    {
        $configured = trim((string) ($payload['redirect'] ?? ''));
        if ($configured === '') {
            return null;
        }

        return \Core\Http\Response::sanitizeRedirectTarget($configured, true);
    }

    private function shouldRedirectRequest(array $payload): bool
    {
        $target = $this->redirectTarget($payload);
        if ($target === null || $target === '') {
            return false;
        }

        $targetPath = parse_url($target, PHP_URL_PATH);
        $normalizedTargetPath = trim(is_string($targetPath) ? $targetPath : '', '/');
        if ($normalizedTargetPath === '') {
            return true;
        }

        return !hash_equals($normalizedTargetPath, trim($this->relativeRequestPath(), '/'));
    }

    private function viewPath(array $payload): string
    {
        $rendered = trim((string) ($payload['render'] ?? ''));
        if ($rendered !== '') {
            $renderPath = rtrim(ROOT_DIR, '/\\') . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($rendered, '/\\'));
            if (is_file($renderPath)) {
                return $renderPath;
            }
        }

        $configured = (string) ($this->config['view'] ?? 'app/views/errors/503.php');
        return rtrim(ROOT_DIR, '/\\') . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($configured, '/\\'));
    }

    private function dataFilePath(): string
    {
        return rtrim(ROOT_DIR, '/\\') . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'down';
    }

    private function relativeRequestPath(): string
    {
        $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $requestPath = parse_url($requestUri, PHP_URL_PATH);
        $normalizedPath = trim(is_string($requestPath) ? $requestPath : '/', '/');
        $basePath = $this->appBasePath();

        if ($basePath === '') {
            return $normalizedPath;
        }

        if ($normalizedPath === $basePath) {
            return '';
        }

        if (str_starts_with($normalizedPath, $basePath . '/')) {
            return substr($normalizedPath, strlen($basePath) + 1);
        }

        return $normalizedPath;
    }

    private function appBasePath(): string
    {
        $baseUrl = defined('BASE_URL') ? (string) BASE_URL : '/';
        $basePath = parse_url($baseUrl, PHP_URL_PATH);

        return trim(is_string($basePath) ? $basePath : '/', '/');
    }

    private function isHttpsRequest(): bool
    {
        return (
            (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') ||
            (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') ||
            (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_SSL']) === 'on') ||
            (!empty($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
        );
    }

    private function runsInCli(): bool
    {
        return PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg';
    }
}