<?php

declare(strict_types=1);

namespace Core\Http;

/**
 * Anything that can be turned into an HTTP response.
 *
 * Implementations describe a response; they never emit one. Emission belongs to
 * Core\Http\Emitter, which is the single place in the framework allowed to touch
 * http_response_code(), header() and the output stream.
 *
 * That separation is what makes worker SAPIs (RoadRunner, FrankenPHP) viable: the
 * previous design had ~37 `exit` calls scattered through the response classes and
 * middleware, each of which terminates the worker process rather than the request.
 *
 */
interface Responsable
{
    public function status(): int;

    /**
     * Response headers, already sanitised of CR/LF/NUL.
     *
     * @return array<string, string>
     */
    public function headers(): array;

    /**
     * Write the body to the output stream.
     *
     * Must not send headers, set the status code, or terminate the process.
     * May echo, may stream, may do nothing at all (204, 304).
     */
    public function emitBody(): void;
}
