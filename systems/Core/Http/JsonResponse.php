<?php

declare(strict_types=1);

namespace Core\Http;

/**
 * A JSON body plus its status and headers.
 *
 * Keeps the decoded array available (payload()) so the Router, the Kernel and
 * tests can inspect a response without re-decoding it.
 */
final class JsonResponse implements Responsable
{
    use HeaderSanitizer;

    private const DEFAULT_FLAGS = JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE;

    /** @var array<string, string> */
    private array $headers;

    /** @param array<mixed, mixed> $payload */
    public function __construct(
        private array $payload,
        private int $status = 200,
        array $headers = [],
        private int $encodingFlags = self::DEFAULT_FLAGS
    ) {
        $this->headers = $this->sanitizeHeaders(array_merge(
            ['Content-Type' => 'application/json; charset=UTF-8'],
            $headers
        ));
    }

    /** @return array<mixed, mixed> */
    public function payload(): array
    {
        return $this->payload;
    }

    public function status(): int
    {
        return $this->status;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function body(): string
    {
        $encoded = json_encode($this->payload, $this->encodingFlags);

        // A payload that cannot be encoded (malformed UTF-8 that survived
        // SUBSTITUTE, a recursive structure) must still produce valid JSON
        // rather than an empty body the client cannot parse.
        if ($encoded === false) {
            return json_encode([
                'code' => 500,
                'message' => 'Response could not be encoded.',
            ], self::DEFAULT_FLAGS) ?: '{"code":500}';
        }

        return $encoded;
    }

    public function emitBody(): void
    {
        echo $this->body();
    }
}
