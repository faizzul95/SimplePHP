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

        if ($this->headerCount($request) > (int) ($this->config['max_header_count'] ?? 64)) {
            return $this->reject($request, 431, 'Too many request headers.');
        }

        $contentLength = (int) $request->server('CONTENT_LENGTH', 0);
        if ($contentLength > (int) ($this->config['max_body_bytes'] ?? 1048576)) {
            return $this->reject($request, 413, 'Request body too large.');
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

        if ($this->shouldInspectPayload($request, $contentLength)) {
            $contentType = strtolower((string) $request->header('content-type', ''));
            $contentType = trim(explode(';', $contentType)[0]);

            if ($contentType === 'multipart/form-data') {
                if ($this->multipartPartCount() > (int) ($this->config['max_multipart_parts'] ?? 50)) {
                    return $this->reject($request, 413, 'Too many multipart parts.');
                }
            } elseif (str_contains($contentType, 'json')) {
                if ($this->jsonFieldCount($request) > (int) ($this->config['max_json_fields'] ?? 200)) {
                    return $this->reject($request, 413, 'JSON payload contains too many fields.');
                }
            } elseif ($this->inputFieldCount() > (int) ($this->config['max_input_vars'] ?? 200)) {
                return $this->reject($request, 413, 'Request payload contains too many fields.');
            }
        }

        return $next($request);
    }

    private function shouldInspectPayload(Request $request, int $contentLength): bool
    {
        if (!in_array(strtoupper($request->method()), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return false;
        }

        if ($contentLength > 0) {
            return true;
        }

        if (!empty($request->files())) {
            return true;
        }

        return $request->rawBody() !== '' || !empty($request->all());
    }

    private function headerCount(Request $request): int
    {
        return count((array) $request->headers());
    }

    private function inputFieldCount(): int
    {
        return count($_GET, COUNT_RECURSIVE) + count($_POST, COUNT_RECURSIVE);
    }

    private function multipartPartCount(): int
    {
        return $this->countArrayEntries($_POST) + $this->countUploadedFiles($_FILES);
    }

    private function jsonFieldCount(Request $request): int
    {
        $rawBody = trim($request->rawBody());
        if ($rawBody === '') {
            return $this->countArrayEntries((array) $request->all());
        }

        $decoded = json_decode($rawBody, true);
        if (!is_array($decoded)) {
            return 0;
        }

        return $this->countArrayEntries($decoded);
    }

    private function countUploadedFiles(array $files): int
    {
        $count = 0;

        foreach ($files as $file) {
            if (!is_array($file)) {
                continue;
            }

            if (isset($file['name'])) {
                $count += is_array($file['name']) ? count($file['name'], COUNT_RECURSIVE) : 1;
                continue;
            }

            $count += $this->countUploadedFiles($file);
        }

        return $count;
    }

    private function countArrayEntries(array $items): int
    {
        $count = 0;

        foreach ($items as $value) {
            $count++;

            if (is_array($value)) {
                $count += $this->countArrayEntries($value);
            }
        }

        return $count;
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
