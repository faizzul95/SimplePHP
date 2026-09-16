<?php

declare(strict_types=1);

namespace Middleware;

use Core\Http\HtmlResponse;
use Core\Http\Request;
use Core\Http\Responsable;
use Core\Http\StreamedResponse;

/**
 * Response compression middleware using gzip or brotli.
 *
 * Reduces response size by 60-80% for text-heavy responses (HTML, JSON, API).
 * Degrades gracefully on hosts without the brotli extension.
 *
 * Add to web and api middleware groups in app/config/framework.php:
 *   'compress' => \Middleware\CompressResponse::class,
 *
 * Shared hosting notes:
 *   - gzip is always available (zlib is built into PHP)
 *   - brotli requires ext-brotli (not available on all shared hosts)
 *   - If neither client accepts compression, output is unmodified
 *
 * Two paths, because both still occur:
 *
 *   1. The route returns a Responsable. This is now the normal case, and the body
 *      is taken from the object. An earlier version only buffered echoed output,
 *      which meant that once responses became objects the buffer was always empty
 *      and compression silently stopped happening — no error, just bigger
 *      responses.
 *   2. The route echoes directly. Legacy views still do this, so the buffer is
 *      kept as a fallback.
 */
final class CompressResponse
{
    // Minimum response size to compress (don't compress tiny responses)
    private const MIN_SIZE_BYTES = 1024;

    // Content-types that are already compressed or binary — skip compression entirely.
    // Compressing these wastes CPU and may increase payload size.
    private const SKIP_CONTENT_TYPES = [
        'image/',           // JPEG, PNG, GIF, WebP, AVIF — already compressed
        'video/',           // MP4, WebM, etc.
        'audio/',           // MP3, OGG, etc.
        'application/zip',
        'application/gzip',
        'application/x-bzip2',
        'application/x-rar-compressed',
        'application/x-7z-compressed',
        'application/pdf',
        'application/octet-stream',
        'font/woff',        // WOFF/WOFF2 are already compressed
        'font/woff2',
    ];

    public function handle(Request $request, \Closure $next): mixed
    {
        $algo = $this->negotiateAlgorithm($request);

        // Nothing to compress — skip buffering entirely
        if ($algo === null || headers_sent()) {
            return $next($request);
        }

        ob_start();

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        $buffer = (string) ob_get_clean();

        if ($response instanceof Responsable) {
            // A stream is deliberately not buffered: holding it in memory to
            // compress it would undo the reason it is a stream.
            if ($response instanceof StreamedResponse) {
                echo $buffer;

                return $response;
            }

            return $this->compressResponsable($response, $algo) ?? $response;
        }

        // Legacy echo path.
        return $this->compressBuffer($buffer, $algo, $response);
    }

    /**
     * Rebuild a response with a compressed body, or null when it is not worth it.
     */
    private function compressResponsable(Responsable $response, string $algo): ?Responsable
    {
        $headers = $response->headers();

        if ($this->shouldSkip($headers['Content-Encoding'] ?? null, $headers['Content-Disposition'] ?? null, $headers['Content-Type'] ?? null)) {
            return null;
        }

        $body = $this->renderBody($response);

        if (strlen($body) < self::MIN_SIZE_BYTES) {
            return null;
        }

        $compressed = $this->compress($body, $algo);

        if ($compressed === null) {
            return null;
        }

        $headers['Content-Encoding'] = $algo;
        $headers['Vary'] = $this->mergeVary($headers['Vary'] ?? null);
        $headers['Content-Length'] = (string) strlen($compressed);

        return new HtmlResponse($compressed, $response->status(), $headers);
    }

    /** Capture a response body without emitting it. */
    private function renderBody(Responsable $response): string
    {
        $level = ob_get_level();

        try {
            ob_start();
            $response->emitBody();

            return (string) ob_get_clean();
        } catch (\Throwable $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }

            throw $e;
        }
    }

    /** Legacy path: the route echoed its body rather than returning one. */
    private function compressBuffer(string $buffer, string $algo, mixed $response): mixed
    {
        $contentEncoding = null;
        $contentDisposition = null;
        $contentType = null;

        foreach (headers_list() as $header) {
            [$name, $value] = array_pad(explode(':', $header, 2), 2, '');
            $name = strtolower(trim($name));
            $value = trim($value);

            match ($name) {
                'content-encoding' => $contentEncoding = $value,
                'content-disposition' => $contentDisposition = $value,
                'content-type' => $contentType = $value,
                default => null,
            };
        }

        if ($this->shouldSkip($contentEncoding, $contentDisposition, $contentType)
            || strlen($buffer) < self::MIN_SIZE_BYTES
        ) {
            echo $buffer;

            return $response;
        }

        $compressed = $this->compress($buffer, $algo);

        if ($compressed === null) {
            echo $buffer;

            return $response;
        }

        header('Content-Encoding: ' . $algo);
        header('Vary: Accept-Encoding');
        header('Content-Length: ' . strlen($compressed));
        echo $compressed;

        return $response;
    }

    /**
     * Parse Accept-Encoding tokens properly (RFC 9110 §12.5.3).
     *
     * str_contains($accept, 'br') would false-positive on values like 'sabre'.
     * Strip q-values (e.g. 'br;q=0.9') and compare exact token names.
     */
    private function negotiateAlgorithm(Request $request): ?string
    {
        $accept = (string) $request->header('Accept-Encoding', '');

        if ($accept === '') {
            return null;
        }

        $tokens = array_map(
            static fn(string $part): string => strtolower(trim(explode(';', trim($part))[0])),
            explode(',', $accept)
        );

        if (in_array('br', $tokens, true) && function_exists('brotli_compress')) {
            return 'br';
        }

        if (in_array('gzip', $tokens, true) || in_array('*', $tokens, true)) {
            return 'gzip';
        }

        return null;
    }

    private function shouldSkip(?string $contentEncoding, ?string $contentDisposition, ?string $contentType): bool
    {
        // Already encoded by something upstream.
        if ($contentEncoding !== null && trim($contentEncoding) !== '') {
            return true;
        }

        // A download: the client is writing bytes to disk, not rendering them.
        if ($contentDisposition !== null && stripos($contentDisposition, 'attachment') !== false) {
            return true;
        }

        if ($contentType === null) {
            return false;
        }

        $contentType = strtolower($contentType);

        foreach (self::SKIP_CONTENT_TYPES as $skipPrefix) {
            if (str_contains($contentType, $skipPrefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Compression quality:
     *   brotli quality 5 = better ratio than 4, still fast for web responses
     *   gzip level 6     = PHP / zlib default; good balance of speed vs ratio
     * Both overridable via framework.compress.brotli_quality / gzip_level.
     */
    private function compress(string $body, string $algo): ?string
    {
        $brotliQuality = (int) (config('framework.compress.brotli_quality') ?? 5);
        $gzipLevel = (int) (config('framework.compress.gzip_level') ?? 6);

        $compressed = ($algo === 'br')
            ? call_user_func('brotli_compress', $body, max(0, min(11, $brotliQuality)))
            : gzencode($body, max(1, min(9, $gzipLevel)));

        if (!is_string($compressed) || $compressed === '') {
            return null;
        }

        // Compressing incompressible content can make it larger.
        return strlen($compressed) < strlen($body) ? $compressed : null;
    }

    /** Keep any Vary the response already declared; caches need all of them. */
    private function mergeVary(?string $existing): string
    {
        $parts = array_filter(array_map('trim', explode(',', (string) $existing)));

        if (!in_array('Accept-Encoding', $parts, true)) {
            $parts[] = 'Accept-Encoding';
        }

        return implode(', ', $parts);
    }
}
