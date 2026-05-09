<?php

declare(strict_types=1);

namespace Middleware;

use Core\Http\Request;

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
 *   - gzip is always available (ob_gzhandler is built-in to PHP)
 *   - brotli requires ext-brotli (not available on all shared hosts)
 *   - If neither client accepts compression, output is unmodified
 *
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
        // Parse Accept-Encoding tokens properly (RFC 7231 §5.3.4).
        // str_contains($accept, 'br') would false-positive on values like 'sabre' or 'abroken'.
        // Strip q-values (e.g. 'br;q=0.9') and compare exact token names.
        $accept = $request->header('Accept-Encoding', '');
        $tokens = array_map(
            static fn(string $part): string => strtolower(trim(explode(';', trim($part))[0])),
            explode(',', $accept)
        );

        $algo = null;
        if (in_array('br', $tokens, true) && function_exists('brotli_compress')) {
            $algo = 'br';
        } elseif (in_array('gzip', $tokens, true) || in_array('*', $tokens, true)) {
            $algo = 'gzip';
        }

        // Nothing to compress — skip buffering entirely
        if ($algo === null || headers_sent()) {
            return $next($request);
        }

        // Buffer output BEFORE running the pipeline so all echo'd / rendered content
        // is captured. The prior implementation placed ob_start() after $next($request)
        // which meant the buffer was empty — the response was already generated.
        ob_start();

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        $buffer = ob_get_clean();
        if ($buffer === false) {
            $buffer = '';
        }

        // Skip file downloads (Content-Disposition: attachment), already-encoded responses,
        // or content types that are already compressed / binary.
        foreach (headers_list() as $header) {
            if (
                stripos($header, 'Content-Encoding:') === 0
                || (stripos($header, 'Content-Disposition:') === 0 && stripos($header, 'attachment') !== false)
            ) {
                echo $buffer;
                return $response;
            }

            // Skip already-compressed content types
            if (stripos($header, 'Content-Type:') === 0) {
                $contentType = strtolower(substr($header, strlen('Content-Type:')));
                foreach (self::SKIP_CONTENT_TYPES as $skipPrefix) {
                    if (str_contains($contentType, $skipPrefix)) {
                        echo $buffer;
                        return $response;
                    }
                }
            }
        }

        // Don't compress tiny responses — overhead outweighs savings
        if (strlen($buffer) < self::MIN_SIZE_BYTES) {
            echo $buffer;

            return $response;
        }

        // Compression quality settings:
        //   brotli quality 5 = better ratio than 4, still fast for web responses
        //   gzip level 6    = PHP / zlib default; good balance of speed vs ratio
        // Both can be overridden via framework config: framework.compress.brotli_quality / gzip_level
        $brotliQuality = (int) (config('framework.compress.brotli_quality') ?? 5);
        $gzipLevel     = (int) (config('framework.compress.gzip_level') ?? 6);

        $compressed = ($algo === 'br')
            ? call_user_func('brotli_compress', $buffer, max(0, min(11, $brotliQuality)))
            : gzencode($buffer, max(1, min(9, $gzipLevel)));

        if ($compressed === false || $compressed === '') {
            echo $buffer; // Compression failed — send unmodified

            return $response;
        }

        header('Content-Encoding: ' . $algo);
        header('Vary: Accept-Encoding');
        header('Content-Length: ' . strlen($compressed));
        echo $compressed;

        return $response;
    }
}
