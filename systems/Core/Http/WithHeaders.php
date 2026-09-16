<?php

declare(strict_types=1);

namespace Core\Http;

/**
 * Wraps a response to add headers without knowing its concrete type.
 *
 * Middleware that annotate a *successful* response — rate-limit budgets, request
 * ids, cache markers, timing — used raw header() calls. That works under php-fpm
 * because PHP accumulates headers globally, but it puts the header outside the
 * response object, which means:
 *
 *   - Core\Http\Emitter cannot see it, so it is absent from anything that
 *     inspects a response (a feature test, a cached payload, a worker bridge).
 *   - CacheResponse stores headers from headers_list(); order decides whether a
 *     given header made it in.
 *   - A worker calls header_remove() between requests, so anything set outside
 *     the response is easy to lose.
 *
 * Decorating rather than rebuilding keeps this working for StreamedResponse and
 * BinaryFileResponse, which cannot simply be reconstructed with new headers.
 */
final class WithHeaders implements Responsable
{
    use HeaderSanitizer;

    /** @var array<string, string> */
    private array $extra;

    /** @param array<string, string> $headers */
    public function __construct(private Responsable $inner, array $headers)
    {
        $this->extra = $this->sanitizeHeaders($headers);
    }

    /**
     * Attach headers to whatever the pipeline returned.
     *
     * Non-Responsable values (an array or string a controller returned, or null)
     * pass through untouched — the kernel normalises those later, and wrapping
     * them here would convert them too early.
     *
     * @param array<string, string> $headers
     */
    public static function attach(mixed $response, array $headers): mixed
    {
        if ($headers === [] || !$response instanceof Responsable) {
            return $response;
        }

        return new self($response, $headers);
    }

    public function status(): int
    {
        return $this->inner->status();
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        // The wrapper wins: it is annotating a response that already exists, so
        // its value is the more recent decision.
        return array_merge($this->inner->headers(), $this->extra);
    }

    public function emitBody(): void
    {
        $this->inner->emitBody();
    }

    /** The response being decorated, for callers that need to inspect it. */
    public function inner(): Responsable
    {
        return $this->inner;
    }
}
