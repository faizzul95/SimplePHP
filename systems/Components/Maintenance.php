<?php

namespace Components;

use Core\Http\CookieFactory;

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

            throw new \Core\Http\ResponseEmitted(
                new \Core\Http\RedirectResponse($this->bypassRedirectTarget($payload), 302, [], true)
            );
        }

        if ($this->hasValidBypassCookie($payload)) {
            return;
        }

        // Health checks, webhook receivers and status endpoints have to stay up
        // through a maintenance window — a load balancer that cannot reach /health
        // pulls the node out, and a payment provider that gets a 503 stops retrying.
        if ($this->isExemptPath()) {
            return;
        }

        if ($this->shouldRedirectRequest($payload)) {
            throw new \Core\Http\ResponseEmitted(
                new \Core\Http\RedirectResponse($this->redirectTarget($payload), 302, [], true)
            );
        }

        // Throws rather than exits so a maintenance window does not kill a worker
        // process on every request.
        throw new \Core\Http\ResponseEmitted($this->maintenanceResponse($payload));
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

    /**
     * Build the maintenance page as a response object.
     *
     * The view is buffered rather than written straight out, so the status code
     * and Retry-After header are decided before anything reaches the client and
     * the whole thing can travel out through the normal emission path.
     */
    private function maintenanceResponse(array $payload): \Core\Http\Responsable
    {
        $statusCode = $this->statusCode($payload);
        $retryAfterSeconds = $this->retryAfterSeconds($payload);
        $refreshAfterSeconds = $this->refreshAfterSeconds($payload);
        $redirectTarget = $this->redirectTarget($payload);

        $headers = [];

        if ($retryAfterSeconds !== null) {
            $headers['Retry-After'] = (string) $retryAfterSeconds;
        }

        $message = trim((string) ($payload['message'] ?? 'Service Unavailable'));
        if ($message === '') {
            $message = 'Service Unavailable';
        }

        // An API client handed an HTML 503 cannot parse it, so a mobile app in a
        // maintenance window shows a blank screen instead of the message. The same
        // negotiation every other error path does applies here.
        if ($this->expectsJson()) {
            return new \Core\Http\JsonResponse([
                'code' => $statusCode,
                'error' => 'service_unavailable',
                'message' => $message,
                'retry_after' => $retryAfterSeconds,
            ], $statusCode, $headers);
        }

        $headers['Content-Type'] = 'text/html; charset=UTF-8';

        if ($refreshAfterSeconds !== null) {
            $refreshHeader = (string) $refreshAfterSeconds;
            if ($redirectTarget !== null && $redirectTarget !== '') {
                $refreshHeader .= ';url=' . $redirectTarget;
            }

            $headers['Refresh'] = $refreshHeader;
        }

        return new \Core\Http\HtmlResponse(
            $this->renderMaintenanceBody($payload, 'Maintenance Mode', $message),
            $statusCode,
            $headers
        );
    }

    /**
     * Whether this request wants JSON.
     *
     * Resolved from the superglobals rather than a Request object because
     * maintenance runs before routing, and before the kernel exists.
     */
    private function expectsJson(): bool
    {
        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));

        if (str_contains($accept, 'application/json') || str_contains($accept, '+json')) {
            return true;
        }

        if (strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest') {
            return true;
        }

        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));

        if (str_contains($contentType, 'application/json')) {
            return true;
        }

        // A request under the configured API prefix is an API request whatever it
        // claims to accept.
        return $this->isApiPath();
    }

    private function isApiPath(): bool
    {
        $prefix = trim((string) (function_exists('config') ? config('api.versioning.prefix', '/api') : '/api'), '/');

        if ($prefix === '') {
            return false;
        }

        return preg_match('#(?:^|/)' . preg_quote($prefix, '#') . '(?:/|$)#i', $this->relativeRequestPath()) === 1;
    }

    /**
     * Whether the current path is exempt from maintenance.
     *
     * Configured as `framework.maintenance.allowed_paths`, each entry matched with
     * fnmatch() so `webhooks/*` works.
     */
    private function isExemptPath(): bool
    {
        $allowed = (array) ($this->config['allowed_paths'] ?? []);

        if ($allowed === []) {
            return false;
        }

        $path = trim($this->relativeRequestPath(), '/');

        foreach ($allowed as $pattern) {
            $pattern = trim((string) $pattern, '/');

            if ($pattern === '') {
                continue;
            }

            if ($path === $pattern || fnmatch($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the application is down, without needing an instance.
     *
     * The scheduler and the queue worker both need this and neither has a
     * Maintenance object; each previously re-implemented the file check, which is
     * how the three could disagree about whether the app was down.
     */
    public static function isActive(): bool
    {
        return is_file(rtrim(ROOT_DIR, '/\\') . DIRECTORY_SEPARATOR
            . 'storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'down');
    }

    private function renderMaintenanceBody(array $payload, string $title, string $message): string
    {
        $viewPath = $this->viewPath($payload);

        if (!is_file($viewPath)) {
            return $title . ': ' . $message;
        }

        $level = ob_get_level();

        try {
            ob_start();
            require $viewPath;

            return (string) ob_get_clean();
        } catch (\Throwable $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }

            $this->logMaintenanceError('Maintenance view failed to render: ' . $e->getMessage());

            return $title . ': ' . $message;
        }
    }

    private function logMaintenanceError(string $message): void
    {
        try {
            \Components\Logger::instance()->log_error($message);
        } catch (\Throwable) {
            // Maintenance mode must render even when logging is unavailable.
        }
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

        CookieFactory::send(
            $this->bypassCookieName(),
            $this->bypassCookieValue($secret),
            $this->bypassCookieTtl(),
            $cookiePath === '/' ? '/' : rtrim($cookiePath, '/') . '/',
            '',
            $this->isHttpsRequest(),
            true,
            $sameSite,
            false
        );
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

    /**
     * A CLI process is not a request, and blocking one would make it impossible
     * to run the migration the window was opened for.
     *
     * Protected rather than private so the request path can be driven from a
     * test, where PHP_SAPI is always 'cli' and every assertion would otherwise
     * exercise this early return instead of the behaviour under test.
     */
    protected function runsInCli(): bool
    {
        return PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg';
    }
}