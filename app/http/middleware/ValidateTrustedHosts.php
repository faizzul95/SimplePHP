<?php

namespace App\Http\Middleware;

use Components\Security;
use Core\Http\Middleware\MiddlewareInterface;
use Core\Http\Request;
use Core\Http\Response;

class ValidateTrustedHosts implements MiddlewareInterface
{
    private array $allowedHosts = [];

    private Security $security;

    public function __construct()
    {
        $this->security = new Security();
        $this->allowedHosts = $this->normalizeHosts((array) config('security.trusted.hosts', []));
    }

    public function setParameters(array $parameters): void
    {
        $hosts = [];

        foreach ($parameters as $parameter) {
            foreach (explode(',', (string) $parameter) as $host) {
                $hosts[] = $host;
            }
        }

        $normalized = $this->normalizeHosts($hosts);
        if (!empty($normalized)) {
            $this->allowedHosts = $normalized;
        }
    }

    public function handle(Request $request, callable $next)
    {
        if (empty($this->allowedHosts)) {
            return $next($request);
        }

        $host = $this->security->normalizeHostHeader((string) ($_SERVER['HTTP_HOST'] ?? $request->server('HTTP_HOST', '')));
        if ($host === '' || !in_array($host, $this->allowedHosts, true)) {
            return $this->reject($request, 400, 'Untrusted host header.');
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

    private function normalizeHosts(array $hosts): array
    {
        $normalized = [];

        foreach ($hosts as $host) {
            $value = $this->security->normalizeHostHeader((string) $host);
            if ($value === '') {
                continue;
            }

            $normalized[$value] = $value;
        }

        return array_values($normalized);
    }
}