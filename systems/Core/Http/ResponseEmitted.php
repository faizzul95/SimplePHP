<?php

declare(strict_types=1);

namespace Core\Http;

/**
 * Carries a finished response out through the middleware stack.
 *
 * jsonResponse(), Response::json(), Response::redirect() and every middleware
 * short-circuit used to `exit`. That skipped each middleware's post-$next() code,
 * skipped the Kernel's finally block, gave the framework no single emission point,
 * and killed the worker process outright under FrankenPHP or RoadRunner.
 *
 * Throwing instead lets the Router catch it at the innermost point and hand it back
 * as an ordinary return value, so the stack unwinds normally and Core\Http\Emitter
 * writes the response exactly once.
 *
 * The array form is kept for backwards compatibility — payload() and status() have
 * the same meaning they always had — while response() exposes the full Responsable
 * for redirects, HTML, files and streams.
 */
final class ResponseEmitted extends \RuntimeException
{
    private Responsable $response;

    /** @param array<mixed, mixed>|Responsable $payload */
    public function __construct(array|Responsable $payload, int $status = 200)
    {
        parent::__construct('Response emitted by a controller helper.');

        $this->response = $payload instanceof Responsable
            ? $payload
            : new JsonResponse($payload, Emitter::normalizeStatus($status));
    }

    public static function json(array $payload, int $status = 200, array $headers = []): self
    {
        return new self(new JsonResponse($payload, Emitter::normalizeStatus($status), $headers));
    }

    public static function html(string $content, int $status = 200, array $headers = []): self
    {
        return new self(new HtmlResponse($content, Emitter::normalizeStatus($status), $headers));
    }

    public static function of(Responsable $response): self
    {
        return new self($response);
    }

    public function response(): Responsable
    {
        return $this->response;
    }

    /**
     * The decoded body when it is JSON, an empty array otherwise.
     *
     * Callers that only ever produced JSON keep working unchanged.
     *
     * @return array<mixed, mixed>
     */
    public function payload(): array
    {
        return $this->response instanceof JsonResponse
            ? $this->response->payload()
            : [];
    }

    public function status(): int
    {
        return $this->response->status();
    }
}
