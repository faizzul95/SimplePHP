<?php

namespace Core\Http;

class Request
{
    private static ?self $current = null;
    private const CONTROL_CHARACTERS = [
        "\x00", "\x01", "\x02", "\x03", "\x04", "\x05", "\x06", "\x07",
        "\x08", "\x0B", "\x0C", "\x0E", "\x0F", "\x10", "\x11", "\x12",
        "\x13", "\x14", "\x15", "\x16", "\x17", "\x18", "\x19", "\x1A",
        "\x1B", "\x1C", "\x1D", "\x1E", "\x1F",
    ];

    private string $method;
    private string $path;
    private array $query;
    private array $request;
    private array $server;
    private array $files;
    private array $headers;
    private array $routeParams = [];
    private array $attributes = [];
    private ?string $rawBody = null;

    public function __construct(array $query = [], array $request = [], array $server = [], array $files = [])
    {
        $this->query = $query;
        $this->request = $request;
        $this->server = $server;
        $this->files = $files;
        $this->headers = $this->extractHeaders($server);
        $this->method = strtoupper($server['REQUEST_METHOD'] ?? 'GET');
        $this->path = $this->resolvePath($server);
    }

    public static function capture(): self
    {
        $request = new self($_GET, $_POST, $_SERVER, $_FILES);

        $rawBody = file_get_contents('php://input');
        $request->rawBody = is_string($rawBody) ? $rawBody : '';

        if ($request->isJson()) {
            $json = json_decode((string) $request->rawBody, true);
            if (is_array($json)) {
                $request->request = array_merge($request->request, $json);
            }
        } elseif (in_array($request->method(), ['PUT', 'PATCH', 'DELETE'], true) && $request->rawBody !== null) {
            parse_str($request->rawBody, $parsedBody);
            if (is_array($parsedBody) && !empty($parsedBody)) {
                $request->request = array_merge($request->request, $parsedBody);
            }
        }

        self::$current = $request;

        return $request;
    }

    public static function current(): ?self
    {
        return self::$current;
    }

    public static function setCurrent(?self $request): void
    {
        self::$current = $request;
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

    public function detectXss(string|array|null $ignoreList = null): bool
    {
        $ignoredKeys = $this->normalizeIgnoredKeys($ignoreList);

        return $this->containsSuspiciousPayload($this->all(), $ignoredKeys);
    }

    public function files(?string $key = null, $default = null)
    {
        if ($key === null) {
            return $this->files;
        }

        return $this->files[$key] ?? $default;
    }

    public function server(?string $key = null, $default = null)
    {
        if ($key === null) {
            return $this->server;
        }

        return $this->server[$key] ?? $default;
    }

    public function headers(?string $key = null, $default = null)
    {
        if ($key === null) {
            return $this->headers;
        }

        return $this->header($key, $default);
    }

    public function rawBody(): string
    {
        if ($this->rawBody === null) {
            return '';
        }

        return $this->rawBody;
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

    public function uri(): string
    {
        return ltrim($this->path, '/');
    }

    public function allSegments(): array
    {
        $uri = $this->uri();
        if ($uri === '') {
            return [];
        }

        return explode('/', $uri);
    }

    public function segment(int $index = 0): ?string
    {
        $segments = $this->allSegments();
        return $segments[$index] ?? null;
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

    private function normalizeIgnoredKeys(string|array|null $ignoreList): array
    {
        if ($ignoreList === null) {
            return [];
        }

        $keys = is_array($ignoreList) ? $ignoreList : explode(',', $ignoreList);
        $normalized = [];

        foreach ($keys as $key) {
            $normalizedKey = strtolower(trim((string) $key));
            if ($normalizedKey === '') {
                continue;
            }

            $normalized[$normalizedKey] = true;
        }

        return $normalized;
    }

    private function containsSuspiciousPayload(mixed $value, array $ignoredKeys, string $path = ''): bool
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $keyName = strtolower(trim((string) $key));
                $nestedPath = $path === '' ? $keyName : $path . '.' . $keyName;

                if ($keyName !== '' && (isset($ignoredKeys[$keyName]) || isset($ignoredKeys[$nestedPath]))) {
                    continue;
                }

                if ($this->containsSuspiciousPayload($item, $ignoredKeys, $nestedPath)) {
                    return true;
                }
            }

            return false;
        }

        if (!is_string($value) || $value === '') {
            return false;
        }

        return $this->stringHasSuspiciousPayload($value);
    }

    private function stringHasSuspiciousPayload(string $value): bool
    {
        $candidate = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $candidate = str_replace(self::CONTROL_CHARACTERS, '', $candidate);
        $normalized = strtolower(trim($candidate));

        if ($normalized === '') {
            return false;
        }

        $patterns = [
            '/<\s*script\b/i',
            '/<\s*iframe\b/i',
            '/<\s*svg\b/i',
            '/<\s*img\b[^>]*\bon\w+\s*=/i',
            '/\bon\w+\s*=\s*["\']?[^"\'>\s]+/i',
            '/\bjavascript\s*:/i',
            '/\bvbscript\s*:/i',
            '/\bdata\s*:\s*text\/html/i',
            '/expression\s*\(/i',
            '/@import\s+["\']?/i',
            '/<\s*[a-z][^>]*>/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $candidate) === 1) {
                return true;
            }
        }

        return false;
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

    public function attributes(?string $key = null, $default = null)
    {
        if ($key === null) {
            return $this->attributes;
        }

        return $this->attributes[$key] ?? $default;
    }

    public function setAttribute(string $key, mixed $value): static
    {
        $normalizedKey = trim($key);
        if ($normalizedKey === '') {
            return $this;
        }

        $this->attributes[$normalizedKey] = $value;

        return $this;
    }

    public function setAttributes(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $this->setAttribute($key, $value);
        }

        return $this;
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
                $headers[$headerName] = $this->sanitizeHeaderValue($value);
            }
        }

        if (isset($server['CONTENT_TYPE'])) {
            $headers['content-type'] = $this->sanitizeHeaderValue($server['CONTENT_TYPE']);
        }

        if (isset($server['CONTENT_LENGTH'])) {
            $headers['content-length'] = $this->sanitizeHeaderValue($server['CONTENT_LENGTH']);
        }

        return $headers;
    }

    private function sanitizeHeaderValue(mixed $value): string
    {
        // Strip CR/LF/NUL from header values so upstream code that reflects
        // them into responses cannot be tricked into header/response splitting.
        return str_replace(["\r", "\n", "\0"], '', (string) $value);
    }
}
