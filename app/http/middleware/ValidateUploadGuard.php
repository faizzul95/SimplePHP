<?php

namespace App\Http\Middleware;

use Core\Http\Middleware\MiddlewareInterface;
use Core\Http\Request;
use Core\Http\Response;

class ValidateUploadGuard implements MiddlewareInterface
{
    private array $policy = [];

    public function setParameters(array $parameters): void
    {
        $profile = trim((string) ($parameters[0] ?? ''));
        $this->policy = $profile !== ''
            ? (array) config('framework.upload_guards.' . $profile, [])
            : [];
    }

    public function handle(Request $request, callable $next)
    {
        if (empty($this->policy)) {
            return $next($request);
        }

        if (($this->policy['require_ajax'] ?? false) === true && !$this->isAjaxRequest($request)) {
            return $this->reject($request, 403, 'Upload requests must use XMLHttpRequest.');
        }

        foreach ((array) ($this->policy['required_fields'] ?? []) as $field) {
            if (!$request->has((string) $field) || trim((string) $request->input((string) $field, '')) === '') {
                return $this->reject($request, 422, 'Upload request is missing required fields.');
            }
        }

        if (!$this->matchesAllowList((string) $request->input('entity_type', ''), (array) ($this->policy['entity_types'] ?? []))) {
            return $this->reject($request, 422, 'Upload entity type is not allowed.');
        }

        if (!$this->matchesAllowList((string) $request->input('entity_file_type', ''), (array) ($this->policy['entity_file_types'] ?? []))) {
            return $this->reject($request, 422, 'Upload entity file type is not allowed.');
        }

        if (!$this->matchesAllowList((string) $request->input('folder_group', ''), (array) ($this->policy['folder_groups'] ?? []), true)) {
            return $this->reject($request, 422, 'Upload folder group is not allowed.');
        }

        if (!$this->matchesAllowList((string) $request->input('folder_type', ''), (array) ($this->policy['folder_types'] ?? []), true)) {
            return $this->reject($request, 422, 'Upload folder type is not allowed.');
        }

        $base64Field = trim((string) ($this->policy['base64_image_field'] ?? ''));
        if ($base64Field !== '') {
            $mime = $this->detectBase64ImageMime((string) $request->input($base64Field, ''));
            $allowedMimes = array_map('strtolower', (array) ($this->policy['base64_image_mime_types'] ?? []));

            if ($mime === null || (!empty($allowedMimes) && !in_array($mime, $allowedMimes, true))) {
                return $this->reject($request, 422, 'Upload image payload is not allowed.');
            }
        }

        return $next($request);
    }

    protected function reject(Request $request, int $status, string $message)
    {
        $payload = [
            'code' => $status,
            'message' => $message,
            'files' => [],
            'isUpload' => false,
        ];

        if ($request->expectsJson() || $this->isAjaxRequest($request)) {
            Response::json($payload, $status);
        }

        http_response_code($status);
        echo $message;
        exit;
    }

    private function isAjaxRequest(Request $request): bool
    {
        return strtolower((string) $request->header('x-requested-with', '')) === 'xmlhttprequest';
    }

    private function matchesAllowList(string $value, array $allowed, bool $allowEmpty = false): bool
    {
        $value = trim($value);
        if ($value === '') {
            return $allowEmpty || empty($allowed);
        }

        if (empty($allowed)) {
            return true;
        }

        $normalizedAllowed = array_map(static function ($item): string {
            return strtolower(trim((string) $item));
        }, $allowed);

        return in_array(strtolower($value), $normalizedAllowed, true);
    }

    private function detectBase64ImageMime(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^data:([a-zA-Z0-9.+\/-]+);base64,/', $value, $matches) !== 1) {
            return null;
        }

        return strtolower(trim((string) ($matches[1] ?? '')));
    }
}