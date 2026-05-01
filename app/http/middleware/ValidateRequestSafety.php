<?php

namespace App\Http\Middleware;

use Components\Security;
use Core\Http\Middleware\MiddlewareInterface;
use Core\Http\Request;
use Core\Http\Response;

class ValidateRequestSafety implements MiddlewareInterface
{
    private array $config = [];

    private Security $security;

    public function __construct()
    {
        $this->security = new Security();

        $defaults = [
            'enabled' => true,
            'max_uri_length' => 2000,
            'max_body_bytes' => 1048576,
            'max_user_agent_length' => 1024,
            'max_header_count' => 64,
            'max_input_vars' => 200,
            'max_json_fields' => 200,
            'max_multipart_parts' => 50,
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
        if ($this->security->exceedsMaxLength($uri, (int) ($this->config['max_uri_length'] ?? 2000))) {
            return $this->reject($request, 414, 'Request URI too long.');
        }

        $userAgent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        if ($userAgent !== '' && $this->security->exceedsMaxLength($userAgent, (int) ($this->config['max_user_agent_length'] ?? 1024))) {
            return $this->reject($request, 400, 'Invalid user agent length.');
        }

        return $next($request);
    }

    protected function reject(Request $request, int $status, string $message)
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
