<?php

declare(strict_types=1);

namespace Core\Http;

/**
 * Header name/value sanitising, in one place.
 *
 * The same sanitizeHeaders() body was copy-pasted into HtmlResponse,
 * StreamedResponse, BinaryFileResponse and Response. Four copies of a security
 * control is three too many — a fix applied to one of them would silently miss
 * the others.
 */
trait HeaderSanitizer
{
    /**
     * Strip CR, LF and NUL from header names and values, dropping entries that
     * cannot be represented safely. This is what prevents response splitting.
     *
     * @param  array<mixed, mixed> $headers
     * @return array<string, string>
     */
    protected function sanitizeHeaders(array $headers): array
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
