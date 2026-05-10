<?php

namespace Components;

use Core\Http\Request;
use PDO;
use Exception;
use InvalidArgumentException;

/**
 * Secure PSR-Compliant API Class
 * 
 * A framework-free API class inspired by Laravel's structure with:
 * - Token-based authentication
 * - CORS support
 * - Rate limiting
 * - IP/URL whitelisting
 * - API versioning
 * - Error handling & logging
 */
class Api
{
    private PDO $pdo;
    private array $config;
    private array $routes = [];
    private ?array $currentUser = null;
    private ?Request $request = null;
    private string $requestMethod;
    private string $requestUri;
    private array $headers;

    public function __construct(PDO $pdo, array $config = [])
    {
        $this->pdo = $pdo;
        $this->config = array_replace_recursive($this->getDefaultConfig(), $config);
        if (function_exists('request')) {
            try {
                $resolvedRequest = request();
                if ($resolvedRequest instanceof Request) {
                    $this->request = $resolvedRequest;
                }
            } catch (\Throwable $e) {
                $this->request = null;
            }
        }

        $this->requestMethod = $this->request instanceof Request
            ? $this->request->method()
            : strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $this->requestUri = $this->parseRequestUri();
        $this->headers = $this->getAllHeaders();
    }

    /**
     * Sanitize table name to prevent SQL injection
     */
    private function safeTable(string $name): string
    {
        $sanitized = preg_replace('/[^a-zA-Z0-9_]/', '', trim($name));
        if (!is_string($sanitized) || $sanitized === '') {
            throw new InvalidArgumentException('Configured table name is invalid.');
        }

        return $sanitized;
    }
    
    private function safeColumn(string $name, string $fallback = 'id'): string
    {
        $sanitized = preg_replace('/[^a-zA-Z0-9_]/', '', trim($name));
        if (is_string($sanitized) && $sanitized !== '') {
            return $sanitized;
        }

        $fallbackSanitized = preg_replace('/[^a-zA-Z0-9_]/', '', trim($fallback));
        if (!is_string($fallbackSanitized) || $fallbackSanitized === '') {
            throw new InvalidArgumentException('Configured column name is invalid.');
        }

        return $fallbackSanitized;
    }

    /**
     * Default configuration — token_table resolved from api or auth config
     */
    private function getDefaultConfig(): array
    {
        // Single source of truth: prefer api config, fall back to auth config
        $tokenTable = 'users_access_tokens';
        $apiToken = \config('api.token_table');
        if (is_string($apiToken) && trim($apiToken) !== '') {
            $tokenTable = $apiToken;
        } else {
            $authToken = \config('auth.token_table');
            if (is_string($authToken) && trim($authToken) !== '') {
                $tokenTable = $authToken;
            }
        }

        return [
            'cors' => [
                'allow_origin' => ['*'],
                'allow_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
                'allow_headers' => ['Content-Type', 'Authorization', 'X-Requested-With'],
                'allow_credentials' => false,
                'allow_wildcard_with_auth' => false,
            ],
            'ip_whitelist' => [],
            'url_whitelist' => [],
            'rate_limit' => [
                'enabled' => true,
                'max_requests' => 60,
                'window_seconds' => 60
            ],
            'auth' => [
                'required' => true,
                'methods' => ['token'],
            ],
            'token_table' => $tokenTable,
            'rate_limit_table' => 'api_rate_limits',
            'log_errors' => true
        ];
    }

    /**
     * Parse request URI and remove query string
     */
    private function parseRequestUri(): string
    {
        if ($this->request instanceof Request) {
            $uri = $this->request->path();
        } else {
            $uri = $_SERVER['REQUEST_URI'] ?? '/';
            $uri = parse_url($uri, PHP_URL_PATH);
        }

        if (!is_string($uri) || $uri === '') {
            return '/';
        }

        if (!$this->request instanceof Request) {
            // Remove the folder name (script's directory) from the URI
            $base = dirname($_SERVER['SCRIPT_NAME']);
            if ($base !== '/' && strpos($uri, $base) === 0) {
                $uri = substr($uri, strlen($base));
            }
        }

        // Remove everything up to and including '/api/' so only the path after 'api/' remains
        $apiPos = strpos($uri, '/api/');
        if ($apiPos !== false) {
            $uri = substr($uri, $apiPos + 5); // 5 = strlen('/api/')
        } else {
            $uri = '/';
        }

        return rtrim($uri, '/') ?: '/';
    }

    /**
     * Get all HTTP headers in a case-insensitive way
     */
    private function getAllHeaders(): array
    {
        if ($this->request instanceof Request) {
            $headers = [];
            foreach ($this->request->headers() as $key => $value) {
                $header = str_replace(' ', '-', ucwords(str_replace('-', ' ', strtolower((string) $key))));
                $headers[$header] = $value;
            }

            return $headers;
        }

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $header = str_replace(' ', '-', ucwords(str_replace('_', ' ', strtolower(substr($key, 5)))));
                $headers[$header] = $value;
            }
        }

        // Compatibility fallbacks for environments that don't expose Authorization as HTTP_AUTHORIZATION.
        if (!isset($headers['Authorization'])) {
            $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
            if (is_string($authorization) && $authorization !== '') {
                $headers['Authorization'] = $authorization;
            }
        }

        if (!isset($headers['Content-Type']) && isset($_SERVER['CONTENT_TYPE'])) {
            $headers['Content-Type'] = (string) $_SERVER['CONTENT_TYPE'];
        }

        if (!isset($headers['Content-Length']) && isset($_SERVER['CONTENT_LENGTH'])) {
            $headers['Content-Length'] = (string) $_SERVER['CONTENT_LENGTH'];
        }

        return $headers;
    }

    /**
     * Generate a new API token
     */
    public function generateToken(int $userId, string $name, ?int $expiresAt = null, array $abilities = []): string
    {
        $plainToken = bin2hex(random_bytes(40));
        $hashedToken = hash('sha256', $plainToken);

        $expiresAtFormatted = $expiresAt ? date('Y-m-d H:i:s', $expiresAt) : null;
        $abilitiesJson = json_encode($abilities);

        $tokenTable = $this->safeTable($this->config['token_table']);
        $stmt = $this->pdo->prepare("
            INSERT INTO {$tokenTable} 
            (user_id, name, token, abilities, expires_at) 
            VALUES (?, ?, ?, ?, ?)
        ");

        $stmt->execute([$userId, $name, $hashedToken, $abilitiesJson, $expiresAtFormatted]);

        return $plainToken;
    }

    /**
     * Authenticate request using bearer token
     */
    private function authenticate(?array $methods = null): ?array
    {
        $resolvedMethods = $this->normalizeAuthMethods($methods ?? ($this->config['auth']['methods'] ?? ['token']));

        if (function_exists('auth')) {
            try {
                $auth = auth();
                if (is_object($auth) && method_exists($auth, 'user') && method_exists($auth, 'via')) {
                    $user = $auth->user($resolvedMethods);
                    if (is_array($user) && !empty($user)) {
                        $via = $auth->via($resolvedMethods);
                        if (is_string($via) && $via !== '' && !isset($user['auth_type'])) {
                            $user['auth_type'] = $via;
                        }

                        return $user;
                    }
                }
            } catch (\Throwable $e) {
                // Fall back to local token auth implementation.
            }
        }

        if (!in_array('token', $resolvedMethods, true)) {
            return null;
        }

        $authHeader = $this->headers['Authorization'] ?? '';

        if (!preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            return null;
        }

        $plainToken = $matches[1];
        $hashedToken = hash('sha256', $plainToken);

        $tokenTable = $this->safeTable($this->config['token_table']);
        $stmt = $this->pdo->prepare("
            SELECT * FROM {$tokenTable} 
            WHERE token = ? AND (expires_at IS NULL OR expires_at > NOW())
        ");

        $stmt->execute([$hashedToken]);
        $tokenRecord = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$tokenRecord) {
            return null;
        }

        $fallbackUser = $this->resolveFallbackTokenUser((int) ($tokenRecord['user_id'] ?? 0));
        if ($fallbackUser === null) {
            return null;
        }

        // Update last used timestamp
        $updateStmt = $this->pdo->prepare("
            UPDATE {$tokenTable} 
            SET last_used_at = NOW() 
            WHERE id = ?
        ");
        $updateStmt->execute([$tokenRecord['id']]);

        return [
            'id' => $fallbackUser['id'],
            'token_id' => $tokenRecord['id'],
            'token_name' => $tokenRecord['name'],
            'auth_type' => 'token',
            'abilities' => json_decode($tokenRecord['abilities'] ?? '[]', true)
        ];
    }

    private function normalizeAuthMethods(array|string|null $methods): array
    {
        if ($methods === null) {
            $methods = ['token'];
        }

        if (is_string($methods)) {
            $methods = str_contains($methods, ',')
                ? array_map('trim', explode(',', $methods))
                : [trim($methods)];
        }

        if (!is_array($methods) || empty($methods)) {
            return ['token'];
        }

        $aliases = [
            'web' => 'session',
            'api' => 'token',
            'session' => 'session',
            'token' => 'token',
            'jwt' => 'jwt',
            'api_key' => 'api_key',
            'apikey' => 'api_key',
            'oauth' => 'oauth',
            'basic' => 'basic',
            'digest' => 'digest',
        ];

        $normalized = [];
        foreach ($methods as $method) {
            if (!is_string($method)) {
                continue;
            }

            $name = strtolower(trim($method));
            if ($name === '') {
                continue;
            }

            $resolved = $aliases[$name] ?? null;
            if ($resolved !== null) {
                $normalized[] = $resolved;
            }
        }

        if (empty($normalized)) {
            return ['token'];
        }

        return array_values(array_unique($normalized));
    }

    private function setAuthChallengeHeaders(array $methods): void
    {
        $normalized = $this->normalizeAuthMethods($methods);
        if (!function_exists('auth')) {
            return;
        }

        try {
            $auth = auth();

            if (in_array('basic', $normalized, true) && is_object($auth) && method_exists($auth, 'basicChallengeHeader')) {
                header('WWW-Authenticate: ' . $auth->basicChallengeHeader());
            }

            if (in_array('digest', $normalized, true) && is_object($auth) && method_exists($auth, 'digestChallengeHeader')) {
                header('WWW-Authenticate: ' . $auth->digestChallengeHeader());
            }
        } catch (\Throwable $e) {
            // Best-effort challenge headers.
        }
    }

    /**
     * Check if current IP is whitelisted
     */
    private function isIpWhitelisted(): bool
    {
        $clientIp = $this->getClientIp();
        return in_array($clientIp, $this->config['ip_whitelist']);
    }

    /**
     * Check if current URL is whitelisted
     */
    private function isUrlWhitelisted(): bool
    {
        $needle = $this->normalizeWhitelistUri($this->requestUri);

        foreach ((array) ($this->config['url_whitelist'] ?? []) as $allowedUri) {
            if ($needle === $this->normalizeWhitelistUri((string) $allowedUri)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeWhitelistUri(string $uri): string
    {
        $path = (string) parse_url($uri, PHP_URL_PATH);
        if ($path === '') {
            return '/';
        }

        $normalized = trim($path);
        $apiPos = strpos($normalized, '/api/');
        if ($apiPos !== false) {
            $normalized = substr($normalized, $apiPos + 5);
        }

        $normalized = trim($normalized, '/');

        return $normalized === '' ? '/' : $normalized;
    }

    /**
     * Handle CORS preflight and set CORS headers
     */
    private function handleCors(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $allowedOrigins = (array) ($this->config['cors']['allow_origin'] ?? []);
        $allowCredentials = (bool) ($this->config['cors']['allow_credentials'] ?? false);
        $allowWildcardWithAuth = (bool) ($this->config['cors']['allow_wildcard_with_auth'] ?? false);
        $authRequired = (bool) ($this->config['auth']['required'] ?? true);

        $originAllowed = false;

        if (in_array('*', $allowedOrigins, true)) {
            if (!$authRequired || $allowWildcardWithAuth) {
                header('Access-Control-Allow-Origin: *');
                $originAllowed = true;
            }
        } elseif ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
            // Reflect only explicitly allowed origin values.
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
            $originAllowed = true;
        }

        if ($allowCredentials && $originAllowed && !in_array('*', $allowedOrigins, true)) {
            header('Access-Control-Allow-Credentials: true');
        }

        header('Access-Control-Allow-Methods: ' . implode(', ', $this->config['cors']['allow_methods']));
        header('Access-Control-Allow-Headers: ' . implode(', ', $this->config['cors']['allow_headers']));
        header('Access-Control-Max-Age: 86400');

        // Handle preflight request
        if ($this->requestMethod === 'OPTIONS') {
            // Reject preflight when an Origin is sent but not allowed.
            if ($origin !== '' && !$originAllowed) {
                http_response_code(403);
                exit;
            }

            http_response_code(200);
            exit;
        }

        // Reject actual cross-origin request when origin is not allowed.
        if ($origin !== '' && !$originAllowed) {
            $this->sendError('CORS origin not allowed', 403);
        }
    }

    /**
     * Check and enforce rate limiting
     */
    private function checkRateLimit(): bool
    {
        if (!$this->config['rate_limit']['enabled']) {
            return true;
        }

        // Skip rate limiting for whitelisted IPs
        if ($this->isIpWhitelisted()) {
            return true;
        }

        // Skip rate limiting for whitelisted URLs (but not IP whitelist)
        if ($this->isUrlWhitelisted()) {
            return true;
        }

        $clientIp = $this->getClientIp();
        $maxRequests = $this->config['rate_limit']['max_requests'];
        $windowSeconds = $this->config['rate_limit']['window_seconds'];
        $windowStart = date('Y-m-d H:i:s', time() - $windowSeconds);
        $requestWindow = date('Y-m-d H:i:s');
        $rateLimitTable = $this->safeTable($this->config['rate_limit_table']);

        $startedTransaction = false;

        try {
            if (!$this->pdo->inTransaction()) {
                $this->pdo->beginTransaction();
                $startedTransaction = true;
            }

            $cleanStmt = $this->pdo->prepare(" 
                DELETE FROM {$rateLimitTable} 
                WHERE window_start < ?
            ");
            $cleanStmt->execute([$windowStart]);

            $checkStmt = $this->pdo->prepare(" 
                SELECT SUM(requests_count) as total_requests 
                FROM {$rateLimitTable} 
                WHERE ip_address = ? AND window_start >= ?
            ");
            $checkStmt->execute([$clientIp, $windowStart]);
            $result = $checkStmt->fetch(PDO::FETCH_ASSOC);
            $currentRequests = (int) ($result['total_requests'] ?? 0);

            if ($currentRequests >= $maxRequests) {
                if ($startedTransaction && $this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }

                return false;
            }

            $recordStmt = $this->pdo->prepare(" 
                INSERT INTO {$rateLimitTable} (ip_address, requests_count, window_start) 
                VALUES (?, 1, ?)
            ");
            $recordStmt->execute([$clientIp, $requestWindow]);

            if ($startedTransaction && $this->pdo->inTransaction()) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($startedTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }

        return true;
    }

    /**
     * Register a GET route
     */
    public function get(string $uri, callable $callback): void
    {
        $this->addRoute('GET', $uri, $callback);
    }

    /**
     * Register a POST route
     */
    public function post(string $uri, callable $callback): void
    {
        $this->addRoute('POST', $uri, $callback);
    }

    /**
     * Register a PUT route
     */
    public function put(string $uri, callable $callback): void
    {
        $this->addRoute('PUT', $uri, $callback);
    }

    /**
     * Register a PATCH route
     */
    public function patch(string $uri, callable $callback): void
    {
        $this->addRoute('PATCH', $uri, $callback);
    }

    /**
     * Register a DELETE route
     */
    public function delete(string $uri, callable $callback): void
    {
        $this->addRoute('DELETE', $uri, $callback);
    }

    /**
     * Register an OPTIONS route
     */
    public function options(string $uri, callable $callback): void
    {
        $this->addRoute('OPTIONS', $uri, $callback);
    }

    /**
     * Add a route to the routes array
     */
    private function addRoute(string $method, string $uri, callable $callback): void
    {
        $uri = trim($uri, '/') ?: '/';
        $this->routes[$method][$uri] = [
            'callback' => $callback,
            'regex' => $this->compileRoutePattern($uri),
        ];
    }

    /**
     * Find matching route
     */
    private function findRoute(): ?array
    {
        $methodRoutes = $this->routes[$this->requestMethod] ?? [];

        if (isset($methodRoutes[$this->requestUri])) {
            return [
                'callback' => $methodRoutes[$this->requestUri]['callback'],
                'params' => [],
            ];
        }

        foreach ($methodRoutes as $route) {
            $matches = [];
            if (preg_match($route['regex'], $this->requestUri, $matches) === 1) {
                $params = [];
                foreach ($matches as $key => $value) {
                    if (!is_string($key)) {
                        continue;
                    }
                    $params[$key] = $value;
                }

                return [
                    'callback' => $route['callback'],
                    'params' => $params,
                ];
            }
        }

        return null;
    }

    /**
     * Compile route URI pattern into regex.
     * Example: /v1/users/{id} => #^v1/users/(?P<id>[A-Za-z0-9_-]+)$#
     */
    private function compileRoutePattern(string $uri): string
    {
        $segments = explode('/', trim($uri, '/'));
        if ($segments === ['']) {
            return '#^/$#';
        }

        $patternParts = [];
        foreach ($segments as $segment) {
            if (preg_match('/^\{([a-zA-Z_][a-zA-Z0-9_]*)\}$/', $segment, $matches) === 1) {
                $patternParts[] = '(?P<' . $matches[1] . '>[A-Za-z0-9_-]+)';
                continue;
            }

            $patternParts[] = preg_quote($segment, '#');
        }

        return '#^' . implode('/', $patternParts) . '$#';
    }

    /**
     * Send JSON response
     */
    private function sendJsonResponse(array $data, int $statusCode = 200): void
    {
        if (!headers_sent()) {
            http_response_code($statusCode);
            header('Content-Type: application/json');
        }
        echo json_encode($data, JSON_PRETTY_PRINT);
        exit;
    }

    /**
     * Send error response
     */
    private function sendError(string $message, int $statusCode = 400, array $details = []): void
    {
        $error = [
            'error' => true,
            'message' => $message,
            'status_code' => $statusCode
        ];

        if (!empty($details)) {
            $error['details'] = $details;
        }

        if ($this->config['log_errors']) {
            error_log("API Error [{$statusCode}]: {$message}");
        }

        $this->sendJsonResponse($error, $statusCode);
    }

    /**
     * Handle the incoming request
     */
    public function handleRequest(): void
    {
        try {
            // Handle CORS
            $this->handleCors();

            // Check rate limiting first
            if (!$this->checkRateLimit()) {
                $this->sendError('Rate limit exceeded', 429);
            }

            // Find route
            $route = $this->findRoute();
            if (!$route) {
                $this->sendError('Route not found', 404);
            }

            $callback = $route['callback'];
            $routeParams = array_values($route['params'] ?? []);

            // Check authentication requirements
            $authRequired = $this->config['auth']['required'] &&
                !$this->isIpWhitelisted() &&
                !$this->isUrlWhitelisted();

            if ($authRequired) {
                $authMethods = $this->normalizeAuthMethods($this->config['auth']['methods'] ?? ['token']);
                $this->currentUser = $this->authenticate($authMethods);
                if (!$this->currentUser) {
                    $this->setAuthChallengeHeaders($authMethods);
                    $this->sendError('Unauthorized', 401);
                }
            }

            // Execute route callback
            $result = $this->currentUser ?
                $callback($this->currentUser, ...$routeParams) :
                $callback(...$routeParams);

            // Send successful response
            if (is_array($result)) {
                $this->sendJsonResponse($result);
            } else {
                $this->sendJsonResponse(['data' => $result]);
            }
        } catch (\Throwable $e) {
            if ($this->config['log_errors']) {
                error_log("API Exception: " . $e->getMessage());
            }
            $this->sendError('Internal server error', 500);
        }
    }

    /**
     * Get request body as JSON
     */
    public function getJsonInput(): array
    {
        if ($this->request instanceof Request) {
            if ($this->request->isJson()) {
                $data = $this->request->input();
                return is_array($data) ? $data : [];
            }

            return [];
        }

        $input = file_get_contents('php://input');
        $data = is_string($input) ? json_decode($input, true) : null;
        return $data ?? [];
    }

    /**
     * Get current authenticated user
     */
    public function getCurrentUser(): ?array
    {
        return $this->currentUser;
    }

    /**
     * Validate token abilities
     */
    public function hasAbility(string $ability): bool
    {
        if (!$this->currentUser) {
            return false;
        }

        $abilities = $this->currentUser['abilities'] ?? [];

        if (!is_array($abilities) && isset($this->currentUser['jwt_claims']['scope']) && is_string($this->currentUser['jwt_claims']['scope'])) {
            $abilities = preg_split('/\s+/', trim($this->currentUser['jwt_claims']['scope'])) ?: [];
        }

        if (!is_array($abilities) && isset($this->currentUser['jwt_claims']['scopes']) && is_array($this->currentUser['jwt_claims']['scopes'])) {
            $abilities = $this->currentUser['jwt_claims']['scopes'];
        }

        if (!is_array($abilities)) {
            $abilities = [];
        }

        return in_array($ability, $abilities, true) || in_array('*', $abilities, true);
    }

    /**
     * Revoke a token
     */
    public function revokeToken(string $plainToken): bool
    {
        $hashedToken = hash('sha256', $plainToken);
        $tokenTable = $this->safeTable($this->config['token_table']);

        $stmt = $this->pdo->prepare("
            DELETE FROM {$tokenTable} 
            WHERE token = ?
        ");

        return $stmt->execute([$hashedToken]);
    }

    /**
     * Revoke all tokens for a user
     */
    public function revokeAllUserTokens(int $userId): int
    {
        $tokenTable = $this->safeTable($this->config['token_table']);
        $stmt = $this->pdo->prepare("
            DELETE FROM {$tokenTable} 
            WHERE user_id = ?
        ");

        $stmt->execute([$userId]);
        return $stmt->rowCount();
    }

    /**
     * Get user's active tokens
     */
    public function getUserTokens(int $userId): array
    {
        $tokenTable = $this->safeTable($this->config['token_table']);
        $stmt = $this->pdo->prepare("
            SELECT id, name, abilities, last_used_at, expires_at, created_at
            FROM {$tokenTable} 
            WHERE user_id = ? AND (expires_at IS NULL OR expires_at > NOW())
            ORDER BY created_at DESC
        ");

        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get the client IP address.
     *
     * Uses the framework's request()->ip() when available (respects trusted
     * proxies), otherwise falls back to REMOTE_ADDR.
     */
    private function getClientIp(): string
    {
        if (function_exists('request')) {
            try {
                return request()->ip();
            } catch (\Throwable $e) {
                // Silently fall back
            }
        }

        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        return filter_var($remoteAddr, FILTER_VALIDATE_IP) ? $remoteAddr : '127.0.0.1';
    }

    private function resolveFallbackTokenUser(int $userId): ?array
    {
        if ($userId < 1) {
            return null;
        }

        $usersTable = $this->safeTable((string) \config('auth.users_table', 'users'));
        $userColumns = (array) \config('auth.user_columns', []);
        $policy = (array) \config('auth.systems_login_policy', []);

        $idColumn = $this->safeColumn((string) ($userColumns['id'] ?? 'id'));
        $statusColumn = $this->safeColumn((string) ($policy['user_status_column'] ?? ($userColumns['status'] ?? 'user_status')), 'user_status');

        $stmt = $this->pdo->prepare("SELECT {$idColumn}, {$statusColumn} FROM {$usersTable} WHERE {$idColumn} = ? LIMIT 1");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($user) || empty($user)) {
            return null;
        }

        $allowedStatuses = array_map('intval', (array) ($policy['allowed_user_status'] ?? [1]));
        if (($policy['enforce_user_status'] ?? true) === true && !in_array((int) ($user[$statusColumn] ?? 0), $allowedStatuses, true)) {
            return null;
        }

        return [
            'id' => (int) ($user[$idColumn] ?? $userId),
            'status' => (int) ($user[$statusColumn] ?? 0),
        ];
    }
}
