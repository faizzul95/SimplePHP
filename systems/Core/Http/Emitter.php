<?php

declare(strict_types=1);

namespace Core\Http;

/**
 * The single place that writes an HTTP response.
 *
 * Everything else in the framework *describes* a response — a Responsable, an
 * array, a string — and hands it here. Centralising emission is what lets the
 * Kernel apply cross-cutting concerns (compression, ETag, timing headers) in one
 * place, lets middleware run their post-$next() code, and lets a worker SAPI
 * finish a request without terminating the process.
 */
final class Emitter
{
    /** Whether a response has already been emitted for the current request. */
    private static bool $sent = false;

    /**
     * Emit a response.
     *
     * Silently does nothing on a second call: a double emission would corrupt the
     * body of the first, and the situation is a bug in the caller rather than
     * something the client should see.
     */
    public static function send(Responsable $response): void
    {
        if (self::$sent) {
            return;
        }

        self::$sent = true;

        self::sendHeaders($response);
        $response->emitBody();
    }

    /**
     * Send status line and headers without a body.
     *
     * Split out for HEAD and 304 handling, where the headers are the response.
     */
    public static function sendHeaders(Responsable $response): void
    {
        if (headers_sent()) {
            return;
        }

        http_response_code($response->status());

        foreach ($response->headers() as $name => $value) {
            header($name . ': ' . $value, true);
        }
    }

    /**
     * Normalise whatever a route action returned into a Responsable.
     *
     * - Responsable  → itself
     * - array        → JSON, status taken from a `code` key when present, so the
     *                  long-standing `['code' => 422, ...]` return convention keeps
     *                  producing a 422 rather than a 200 with an error body
     * - string       → HTML
     * - null         → nothing to emit
     */
    public static function toResponse(mixed $result): ?Responsable
    {
        if ($result === null) {
            return null;
        }

        if ($result instanceof Responsable) {
            return $result;
        }

        if (is_array($result)) {
            $status = isset($result['code']) && is_numeric($result['code'])
                ? (int) $result['code']
                : 200;

            return new JsonResponse($result, self::normalizeStatus($status));
        }

        if (is_string($result)) {
            return new HtmlResponse($result);
        }

        return null;
    }

    /** Guard against a `code` key that is not a real HTTP status. */
    /**
     * Clamp anything that is not a valid HTTP status to 500.
     *
     * It used to clamp to 200, while ExceptionHandler's own copy of this clamped
     * to 500 — the same question with two answers depending on which one you
     * reached. 200 is the wrong one: an out-of-range status is a bug, and the
     * value that arrives is usually a driver error code or a 0 from an
     * uninitialised variable. Answering "OK" makes the client treat the failure
     * as a success, which is the one outcome worse than the error itself.
     */
    public static function normalizeStatus(int $status): int
    {
        return ($status >= 100 && $status <= 599) ? $status : 500;
    }

    public static function hasSent(): bool
    {
        return self::$sent;
    }

    /**
     * Reset the emitted flag.
     *
     * Called by WorkerState::flush() between worker cycles and by tests.
     */
    public static function reset(): void
    {
        self::$sent = false;
    }
}
