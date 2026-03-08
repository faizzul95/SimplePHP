<?php

namespace Components;

use PDO;
use Exception;

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
    private string $requestMethod;
    private string $requestUri;
    private array $headers;

    public function __construct(PDO $pdo, array $config = [])
    {
        $this->pdo = $pdo;
        $this->config = array_merge($this->getDefaultConfig(), $config);
        $this->requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $this->requestUri = $this->parseRequestUri();
        $this->headers = $this->getAllHeaders();
    }

    /**
     * Sanitize table name to prevent SQL injection
     */
    private function safeTable(string $name): string
    {
        return preg_replace('/[^a-zA-Z0-9_]/', '', $name) ?: 'users_access_tokens';
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
                'allow_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
                'allow_headers' => ['Content-Type', 'Authorization', 'X-Requested-With'],
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
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $uri = parse_url($uri, PHP_URL_PATH);

        // Remove the folder name (script's directory) from the URI
        $base = dirname($_SERVER['SCRIPT_NAME']);
        if ($base !== '/' && strpos($uri, $base) === 0) {
            $uri = substr($uri, strlen($base));
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
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $header = str_replace(' ', '-', ucwords(str_replace('_', ' ', strtolower(substr($key, 5)))));
                $headers[$header] = $value;
            }
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
    private function authenticate(): ?array
    {
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

        // Update last used timestamp
        $updateStmt = $this->pdo->prepare("
            UPDATE {$tokenTable} 
            SET last_used_at = NOW() 
            WHERE id = ?
        ");
        $updateStmt->execute([$tokenRecord['id']]);

        return [
            'id' => $tokenRecord['user_id'],
            'token_id' => $tokenRecord['id'],
            'token_name' => $tokenRecord['name'],
            'abilities' => json_decode($tokenRecord['abilities'] ?? '[]', true)
        ];
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
        return in_array($this->requestUri, $this->config['url_whitelist']);
    }

    /**
     * Handle CORS preflight and set CORS headers
     */
    private function handleCors(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $allowedOrigins = $this->config['cors']['allow_origin'];

        if (in_array('*', $allowedOrigins)) {
            // Wildcard: allow all origins (cannot be used with credentials)
            header('Access-Control-Allow-Origin: *');
        } elseif ($origin !== '' && in_array($origin, $allowedOrigins)) {
            // Reflect the specific allowed origin (safe with credentials)
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
        }

        header('Access-Control-Allow-Methods: ' . implode(', ', $this->config['cors']['allow_methods']));
        header('Access-Control-Allow-Headers: ' . implode(', ', $this->config['cors']['allow_headers']));
        header('Access-Control-Max-Age: 86400');

        // Handle preflight request
        if ($this->requestMethod === 'OPTIONS') {
            http_response_code(200);
            exit;
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
        $rateLimitTable = $this->safeTable($this->config['rate_limit_table']);

        // Clean old entries
        $cleanStmt = $this->pdo->prepare("
            DELETE FROM {$rateLimitTable} 
            WHERE window_start < ?
        ");
        $cleanStmt->execute([$windowStart]);

        // Check current request count
        $checkStmt = $this->pdo->prepare("
            SELECT SUM(requests_count) as total_requests 
            FROM {$rateLimitTable} 
            WHERE ip_address = ? AND window_start >= ?
        ");
        $checkStmt->execute([$clientIp, $windowStart]);
        $result = $checkStmt->fetch(PDO::FETCH_ASSOC);
        $currentRequests = (int)($result['total_requests'] ?? 0);

        if ($currentRequests >= $maxRequests) {
            return false;
        }

        // Record this request - simple insert per window
        $recordStmt = $this->pdo->prepare("
            INSERT INTO {$rateLimitTable} (ip_address, requests_count) 
            VALUES (?, 1)
        ");
        $recordStmt->execute([$clientIp]);

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
     * Register a DELETE route
     */
    public function delete(string $uri, callable $callback): void
    {
        $this->addRoute('DELETE', $uri, $callback);
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
        $pattern = preg_replace_callback('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', function ($matches) {
            $paramName = $matches[1];
            return '(?P<' . $paramName . '>[A-Za-z0-9_-]+)';
        }, $uri);

        return '#^' . $pattern . '$#';
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
                $this->currentUser = $this->authenticate();
                if (!$this->currentUser) {
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
        } catch (Exception $e) {
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
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
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
        return in_array($ability, $abilities) || in_array('*', $abilities);
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
}
