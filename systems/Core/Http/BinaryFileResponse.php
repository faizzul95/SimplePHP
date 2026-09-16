<?php

namespace Core\Http;

use RuntimeException;

class BinaryFileResponse implements Responsable
{
    use HeaderSanitizer;

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
        $headers = $this->preparedHeaders();

        // Content-Length is part of the header set, not something emitBody() can
        // add later — by then the headers are already on the wire.
        $realPath = $this->resolvedPath();
        if ($realPath !== null) {
            $size = @filesize($realPath);
            if ($size !== false) {
                $headers['Content-Length'] = (string) $size;
            }
        }

        return $headers;
    }

    public function emitBody(): void
    {
        $realPath = $this->resolvedPath();

        if ($realPath === null) {
            throw new RuntimeException('Download file is missing or not readable.');
        }

        readfile($realPath);
    }

    /** Throws rather than exits so middleware unwind and the Kernel emits once. */
    public function send(): never
    {
        // Fail before any header is sent, so a missing file still produces a
        // proper 500 rather than a truncated 200.
        if ($this->resolvedPath() === null) {
            throw new RuntimeException('Download file is missing or not readable.');
        }

        throw new ResponseEmitted($this);
    }

    public static function buildContentDisposition(string $downloadName): string
    {
        $fallback = trim(str_replace(["\r", "\n", '"'], '', $downloadName));
        $fallback = $fallback === '' ? 'download' : $fallback;
        $encoded = rawurlencode($fallback);

        return sprintf('attachment; filename="%s"; filename*=UTF-8\'\'%s', $fallback, $encoded);
    }

    private function resolvedPath(): ?string
    {
        $realPath = realpath($this->path);

        if ($realPath === false || !is_file($realPath) || !is_readable($realPath)) {
            return null;
        }

        return $realPath;
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
}
