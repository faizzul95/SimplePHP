<?php

namespace Core\Http;

class Request
{
    private string $method;
    private string $path;
    private array $query;
    private array $request;
    private array $server;
    private array $headers;
    private array $routeParams = [];

    public function __construct(array $query = [], array $request = [], array $server = [])
    {
        $this->query = $query;
        $this->request = $request;
        $this->server = $server;
        $this->headers = $this->extractHeaders($server);
        $this->method = strtoupper($server['REQUEST_METHOD'] ?? 'GET');
        $this->path = $this->resolvePath($server);
    }

    public static function capture(): self
    {
        $request = new self($_GET, $_POST, $_SERVER);

        if ($request->isJson()) {
            $raw = file_get_contents('php://input');
            $json = json_decode((string) $raw, true);
            if (is_array($json)) {
                $request->request = array_merge($request->request, $json);
            }
        }

        return $request;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function query(?string $key = null, $default = null)
    {
        if ($key === null) {
            return $this->query;
        }

        return $this->query[$key] ?? $default;
    }

    public function input(?string $key = null, $default = null)
    {
        $data = array_merge($this->query, $this->request);

        if ($key === null) {
            return $data;
        }

        return $data[$key] ?? $default;
    }

    public function all(): array
    {
        return array_merge($this->query, $this->request);
    }

    /**
     * Check if the request contains a given input key.
     */
    public function has(string $key): bool
    {
        $data = array_merge($this->query, $this->request);
        return array_key_exists($key, $data);
    }

    /**
     * Merge additional data into the request input.
     */
    public function merge(array $data): static
    {
        $this->request = array_merge($this->request, $data);
        return $this;
    }

    /**
     * Get a subset of the input data.
     */
    public function only(array $keys): array
    {
        return array_intersect_key($this->all(), array_flip($keys));
    }

    /**
     * Get all input except the specified keys.
     */
    public function except(array $keys): array
    {
        return array_diff_key($this->all(), array_flip($keys));
    }

    /**
     * Determine if the request is sending JSON.
     */
    public function isJson(): bool
    {
        $contentType = strtolower((string) $this->header('content-type', ''));
        return str_contains($contentType, 'application/json') || str_contains($contentType, '+json');
    }

    /**
     * Determine if the current request is asking for JSON.
     */
    public function wantsJson(): bool
    {
        $accept = strtolower((string) $this->header('accept', ''));
        return str_contains($accept, 'application/json') || str_contains($accept, '+json');
    }

    /**
     * Get the bearer token from the Authorization header.
     */
    public function bearerToken(): ?string
    {
        $header = trim((string) $this->header('authorization', ''));
        if ($header === '') {
            return null;
        }

        if (stripos($header, 'Bearer ') === 0) {
            return trim(substr($header, 7));
        }

        return null;
    }

    /**
     * Get the full URL for the request.
     */
    public function fullUrl(): string
    {
        $scheme = $this->isSecureRequest() ? 'https' : 'http';
        $host = $this->server['HTTP_HOST'] ?? 'localhost';
        return $scheme . '://' . $host . ($this->server['REQUEST_URI'] ?? '/');
    }

    /**
     * Get the URL (no query string) for the request.
     */
    public function url(): string
    {
        $scheme = $this->isSecureRequest() ? 'https' : 'http';
        $host = $this->server['HTTP_HOST'] ?? 'localhost';
        return $scheme . '://' . $host . $this->path;
    }

    public function header(string $key, $default = null)
    {
        $normalized = strtolower($key);
        return $this->headers[$normalized] ?? $default;
    }

    public function userAgent(): string
    {
        return (string) ($this->header('user-agent', $this->server['HTTP_USER_AGENT'] ?? 'Unknown'));
    }

    public function ip(): string
    {
        $remoteAddr = $this->server['REMOTE_ADDR'] ?? '127.0.0.1';

        // Only trust forwarded headers when behind a known reverse proxy
        $trustedProxies = config('security.trusted_proxies', []);

        if (!empty($trustedProxies) && $this->isTrustedProxy($remoteAddr, $trustedProxies)) {
            $keys = [
                'HTTP_CF_CONNECTING_IP',
                'HTTP_CLIENT_IP',
                'HTTP_X_FORWARDED_FOR',
                'HTTP_X_FORWARDED',
                'HTTP_X_CLUSTER_CLIENT_IP',
                'HTTP_FORWARDED_FOR',
                'HTTP_FORWARDED',
            ];

            foreach ($keys as $key) {
                if (empty($this->server[$key])) {
                    continue;
                }

                $ips = explode(',', (string) $this->server[$key]);
                foreach ($ips as $ip) {
                    $candidate = trim($ip);
                    if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                        return $candidate;
                    }
                }
            }
        }

        return filter_var($remoteAddr, FILTER_VALIDATE_IP) ? $remoteAddr : '127.0.0.1';
    }

    private function isSecureRequest(): bool
    {
        if (!empty($this->server['HTTPS']) && strtolower((string) $this->server['HTTPS']) !== 'off') {
            return true;
        }

        $port = (int) ($this->server['SERVER_PORT'] ?? 0);
        if ($port === 443) {
            return true;
        }

        return strtolower((string) ($this->header('x-forwarded-proto', ''))) === 'https';
    }

    private function isTrustedProxy(string $remoteAddr, array $trustedProxies): bool
    {
        foreach ($trustedProxies as $proxy) {
            $proxy = trim((string) $proxy);
            if ($proxy === '') {
                continue;
            }

            if ($proxy === '*' || $remoteAddr === $proxy) {
                return true;
            }

            if (str_contains($proxy, '/') && $this->ipInCidr($remoteAddr, $proxy)) {
                return true;
            }
        }

        return false;
    }

    private function ipInCidr(string $ip, string $cidr): bool
    {
        if (!str_contains($cidr, '/')) {
            return false;
        }

        [$subnet, $maskBits] = explode('/', $cidr, 2);
        $subnet = trim($subnet);
        $maskBits = (int) $maskBits;

        $ipBinary = @inet_pton($ip);
        $subnetBinary = @inet_pton($subnet);

        if ($ipBinary === false || $subnetBinary === false) {
            return false;
        }

        $byteLength = strlen($ipBinary);
        if ($byteLength !== strlen($subnetBinary)) {
            return false;
        }

        $maxBits = $byteLength * 8;
        if ($maskBits < 0 || $maskBits > $maxBits) {
            return false;
        }

        $fullBytes = intdiv($maskBits, 8);
        $remainingBits = $maskBits % 8;

        if ($fullBytes > 0 && substr($ipBinary, 0, $fullBytes) !== substr($subnetBinary, 0, $fullBytes)) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = (~((1 << (8 - $remainingBits)) - 1)) & 0xFF;
        return ((ord($ipBinary[$fullBytes]) & $mask) === (ord($subnetBinary[$fullBytes]) & $mask));
    }

    public function platform(): string
    {
        $agent = strtolower($this->userAgent());

        if (str_contains($agent, 'windows')) {
            return 'Windows';
        }
        if (str_contains($agent, 'mac')) {
            return 'macOS';
        }
        if (str_contains($agent, 'linux')) {
            return 'Linux';
        }
        if (str_contains($agent, 'android')) {
            return 'Android';
        }
        if (str_contains($agent, 'iphone') || str_contains($agent, 'ipad') || str_contains($agent, 'ios')) {
            return 'iOS';
        }

        return 'Unknown';
    }

    public function browser(): string
    {
        $agent = strtolower($this->userAgent());

        $map = [
            'edg/' => 'Microsoft Edge',
            'chrome/' => 'Google Chrome',
            'firefox/' => 'Mozilla Firefox',
            'safari/' => 'Safari',
            'opr/' => 'Opera',
            'opera/' => 'Opera',
            'msie ' => 'Internet Explorer',
            'trident/' => 'Internet Explorer',
        ];

        foreach ($map as $needle => $name) {
            if (str_contains($agent, $needle)) {
                return $name;
            }
        }

        return 'Unknown';
    }

    public function isApi(): bool
    {
        return str_starts_with(trim($this->path, '/'), 'api/');
    }

    /**
     * Determine if the request expects a JSON response.
     *
     * Returns true when:
     *  - The path starts with api/ (API route)
     *  - The Accept header asks for JSON (wantsJson)
     *  - The X-Requested-With header indicates AJAX (XMLHttpRequest)
     */
    public function expectsJson(): bool
    {
        if ($this->isApi()) {
            return true;
        }

        if ($this->wantsJson()) {
            return true;
        }

        // Detect XMLHttpRequest (axios, jQuery.ajax, fetch with X-Requested-With)
        $xhr = strtolower((string) $this->header('x-requested-with', ''));
        if ($xhr === 'xmlhttprequest') {
            return true;
        }

        return false;
    }

    public function setRouteParams(array $params): void
    {
        $this->routeParams = $params;
    }

    public function route(?string $key = null, $default = null)
    {
        if ($key === null) {
            return $this->routeParams;
        }

        return $this->routeParams[$key] ?? $default;
    }

    private function resolvePath(array $server): string
    {
        $route = $this->query['__route'] ?? null;
        if (is_string($route) && trim($route) !== '') {
            return '/' . trim($route, '/');
        }

        $pathInfo = $server['PATH_INFO'] ?? $server['ORIG_PATH_INFO'] ?? '';
        if (is_string($pathInfo) && trim($pathInfo) !== '') {
            return '/' . trim($pathInfo, '/');
        }

        $uri = parse_url($server['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        if (!is_string($uri)) {
            return '/';
        }

        $scriptName = str_replace('\\', '/', (string) ($server['SCRIPT_NAME'] ?? ''));
        $basePath = rtrim(dirname($scriptName), '/');

        if ($basePath !== '' && $basePath !== '/' && str_starts_with($uri, $basePath)) {
            $uri = substr($uri, strlen($basePath));
        }

        $uri = trim($uri, '/');
        if ($uri === '' || $uri === 'index.php') {
            return '/';
        }

        return '/' . $uri;
    }

    private function extractHeaders(array $server): array
    {
        $headers = [];

        foreach ($server as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headerName = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$headerName] = $value;
            }
        }

        if (isset($server['CONTENT_TYPE'])) {
            $headers['content-type'] = $server['CONTENT_TYPE'];
        }

        if (isset($server['CONTENT_LENGTH'])) {
            $headers['content-length'] = $server['CONTENT_LENGTH'];
        }

        return $headers;
    }
}
