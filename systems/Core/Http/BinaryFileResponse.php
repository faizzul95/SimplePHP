<?php

namespace Core\Http;

use RuntimeException;

class BinaryFileResponse
{
    public function __construct(
        private string $path,
        private ?string $downloadName = null,
        private array $headers = [],
        private int $status = 200
    ) {
    }

    public function path(): string
    {
        return $this->path;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function headers(): array
    {
        return $this->preparedHeaders();
    }

    public function send(): never
    {
        $realPath = realpath($this->path);
        if ($realPath === false || !is_file($realPath) || !is_readable($realPath)) {
            throw new RuntimeException('Download file is missing or not readable.');
        }

        if (!headers_sent()) {
            http_response_code($this->status);

            foreach ($this->preparedHeaders() as $name => $value) {
                header($name . ': ' . $value, true);
            }

            header('Content-Length: ' . (string) filesize($realPath), true);
        }

        readfile($realPath);
        exit;
    }

    public static function buildContentDisposition(string $downloadName): string
    {
        $fallback = trim(str_replace(["\r", "\n", '"'], '', $downloadName));
        $fallback = $fallback === '' ? 'download' : $fallback;
        $encoded = rawurlencode($fallback);

        return sprintf('attachment; filename="%s"; filename*=UTF-8\'\'%s', $fallback, $encoded);
    }

    private function preparedHeaders(): array
    {
        $pathInfo = pathinfo($this->path);
        $downloadName = $this->downloadName ?? ($pathInfo['basename'] ?? 'download');
        $contentType = $this->headers['Content-Type'] ?? $this->detectMimeType();

        return array_merge([
            'Content-Type' => $contentType,
            'Content-Disposition' => self::buildContentDisposition($downloadName),
            'X-Content-Type-Options' => 'nosniff',
        ], $this->sanitizeHeaders($this->headers));
    }

    private function detectMimeType(): string
    {
        $mimeType = function_exists('mime_content_type') ? @mime_content_type($this->path) : false;

        return is_string($mimeType) && $mimeType !== '' ? $mimeType : 'application/octet-stream';
    }

    private function sanitizeHeaders(array $headers): array
    {
        $sanitized = [];

        foreach ($headers as $name => $value) {
            if (!is_string($name) || !is_scalar($value)) {
                continue;
            }

            $headerName = str_replace(["\r", "\n", "\0"], '', $name);
            $headerValue = str_replace(["\r", "\n", "\0"], '', (string) $value);
            if ($headerName === '') {
                continue;
            }

            $sanitized[$headerName] = $headerValue;
        }

        return $sanitized;
    }
}