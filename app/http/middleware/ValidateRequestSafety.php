<?php

namespace App\Http\Middleware;

use Core\Http\Middleware\MiddlewareInterface;
use Core\Http\Request;
use Core\Http\Response;

class ValidateRequestSafety implements MiddlewareInterface
{
    private array $config = [];

    public function __construct()
    {
        $defaults = [
            'enabled' => true,
            'max_uri_length' => 2000,
            'max_body_bytes' => 1048576,
            'max_user_agent_length' => 1024,
            'allowed_hosts' => [],
            'allowed_write_content_types' => [
                'application/json',
                'application/x-www-form-urlencoded',
                'multipart/form-data',
                'text/plain',
            ],
        ];

        $this->config = array_merge($defaults, (array) config('security.request_hardening', []));
    }

    public function handle(Request $request, callable $next)
    {
        if (($this->config['enabled'] ?? true) !== true) {
            return $next($request);
        }

        // Restrict to known HTTP verbs to reduce protocol abuse.
        $method = strtoupper($request->method());
        $allowedMethods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'];
        if (!in_array($method, $allowedMethods, true)) {
            return $this->reject($request, 405, 'Unsupported HTTP method.');
        }

        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        if (strlen($uri) > (int) ($this->config['max_uri_length'] ?? 2000)) {
            return $this->reject($request, 414, 'Request URI too long.');
        }

        // Optional host allow-list hardening.
        $allowedHosts = (array) ($this->config['allowed_hosts'] ?? []);
        if (!empty($allowedHosts)) {
            $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
            $host = preg_replace('/:\\d+$/', '', $host);
            $normalized = array_map(static function ($value) {
                return strtolower(trim((string) $value));
            }, $allowedHosts);

            if ($host === '' || !in_array($host, $normalized, true)) {
                return $this->reject($request, 400, 'Untrusted host header.');
            }
        }

        $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($contentLength > (int) ($this->config['max_body_bytes'] ?? 1048576)) {
            return $this->reject($request, 413, 'Request body too large.');
        }

        $userAgent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        if ($userAgent !== '' && strlen($userAgent) > (int) ($this->config['max_user_agent_length'] ?? 1024)) {
            return $this->reject($request, 400, 'Invalid user agent length.');
        }

        // Content-Type allow-list for write requests with payload.
        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) && $contentLength > 0) {
            $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
            $contentType = trim(explode(';', $contentType)[0]);
            $allowedTypes = array_map('strtolower', (array) ($this->config['allowed_write_content_types'] ?? []));

            if ($contentType !== '' && !in_array($contentType, $allowedTypes, true)) {
                return $this->reject($request, 415, 'Unsupported content type.');
            }
        }

        return $next($request);
    }

    private function reject(Request $request, int $status, string $message)
    {
        if ($request->expectsJson()) {
            Response::json([
                'code' => $status,
                'message' => $message,
            ], $status);
        }

        http_response_code($status);
        echo $message;
        exit;
    }
}
