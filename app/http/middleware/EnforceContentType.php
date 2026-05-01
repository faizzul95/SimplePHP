<?php

namespace App\Http\Middleware;

use Core\Http\Middleware\MiddlewareInterface;
use Core\Http\Request;
use Core\Http\Response;

class EnforceContentType implements MiddlewareInterface
{
    private array $allowedTypes = [];

    public function setParameters(array $parameters): void
    {
        $profiles = (array) config('framework.content_type_profiles', []);
        $resolved = [];

        foreach ($parameters as $parameter) {
            foreach (explode(',', (string) $parameter) as $value) {
                $candidate = strtolower(trim($value));
                if ($candidate === '') {
                    continue;
                }

                if (isset($profiles[$candidate]) && is_array($profiles[$candidate])) {
                    foreach ($profiles[$candidate] as $type) {
                        $resolved[] = strtolower(trim((string) $type));
                    }
                    continue;
                }

                $resolved[] = $candidate;
            }
        }

        $this->allowedTypes = array_values(array_unique(array_filter($resolved, static function ($type) {
            return $type !== '';
        })));
    }

    public function handle(Request $request, callable $next)
    {
        if (!$this->shouldEnforce($request)) {
            return $next($request);
        }

        $allowedTypes = $this->allowedTypes;
        if (empty($allowedTypes)) {
            $allowedTypes = array_map('strtolower', (array) config('security.request_hardening.allowed_write_content_types', []));
        }

        if (empty($allowedTypes)) {
            return $next($request);
        }

        $contentType = strtolower((string) $request->header('content-type', ''));
        $contentType = trim(explode(';', $contentType)[0]);

        if ($contentType === '' || !$this->matchesAllowedType($contentType, $allowedTypes)) {
            return $this->reject($request, 415, 'Unsupported content type.');
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

    private function shouldEnforce(Request $request): bool
    {
        if (!in_array(strtoupper($request->method()), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return false;
        }

        if (!empty($request->files())) {
            return true;
        }

        $contentLength = (int) $request->server('CONTENT_LENGTH', 0);
        if ($contentLength > 0) {
            return true;
        }

        return $request->rawBody() !== '' || !empty($request->all());
    }

    private function matchesAllowedType(string $contentType, array $allowedTypes): bool
    {
        foreach ($allowedTypes as $allowedType) {
            if ($allowedType === '') {
                continue;
            }

            if ($allowedType === $contentType) {
                return true;
            }

            if (str_contains($allowedType, '*+') && preg_match('/^[^\/]+\/\*\+(.+)$/', $allowedType, $matches) === 1) {
                $suffix = $matches[1] ?? '';
                if ($suffix !== '' && str_ends_with($contentType, '+' . $suffix)) {
                    return true;
                }
            }

            if (str_ends_with($allowedType, '/*')) {
                $prefix = substr($allowedType, 0, -1);
                if (str_starts_with($contentType, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }
}