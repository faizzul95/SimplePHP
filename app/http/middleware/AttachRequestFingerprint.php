<?php

namespace App\Http\Middleware;

use Core\Http\JsonResponse;
use Core\Http\Middleware\MiddlewareInterface;
use Core\Http\Request;

class AttachRequestFingerprint implements MiddlewareInterface
{
    public function handle(Request $request, callable $next)
    {
        $requestId = $this->resolveIdentifier('request_id', 'req_');
        $traceId = $this->resolveIdentifier('trace_id', 'trace_');
        $clientFingerprint = $this->buildClientFingerprint($request);
        $securityContext = $this->buildSecurityContext($request, $requestId, $traceId, $clientFingerprint);

        $request->setAttributes([
            'request_id' => $requestId,
            'trace_id' => $traceId,
            'client_fingerprint' => $clientFingerprint,
            'security_context' => $securityContext,
        ]);

        $_SERVER['HTTP_X_REQUEST_ID'] = $requestId;
        $_SERVER['HTTP_X_TRACE_ID'] = $traceId;
        $_SERVER['MYTH_REQUEST_ID'] = $requestId;
        $_SERVER['MYTH_TRACE_ID'] = $traceId;
        $_SERVER['MYTH_CLIENT_FINGERPRINT'] = $clientFingerprint;

        // The id the client is about to see in X-Request-Id has to reach the log
        // too, or "here's the id from the error page" identifies nothing.
        \Core\Support\LogContext::set([
            'request_id' => $requestId,
            'trace_id' => $traceId,
            'method' => $request->method(),
            'path' => $request->path(),
        ]);

        if (!headers_sent()) {
            header('X-Request-Id: ' . $requestId, true);
            header('X-Trace-Id: ' . $traceId, true);
        }

        if (function_exists('dispatch_event')) {
            dispatch_event('request.captured', $securityContext);
        }

        $response = $next($request);

        return $this->stampResponse($response, $requestId, $traceId);
    }

    /**
     * Put the request and trace ids into the response body so a user reporting a
     * problem can quote an id that appears in the server log.
     *
     * Handles both shapes. Controllers used to return plain arrays; most now
     * return a JsonResponse, and only checking for an array meant the ids
     * silently stopped being attached to API responses once that changed.
     */
    private function stampResponse(mixed $response, string $requestId, string $traceId): mixed
    {
        if (is_array($response)) {
            return $this->stampPayload($response, $requestId, $traceId);
        }

        if ($response instanceof JsonResponse) {
            $payload = $this->stampPayload($response->payload(), $requestId, $traceId);

            return new JsonResponse($payload, $response->status(), $response->headers());
        }

        // HTML, redirects, files and streams carry the ids in their headers,
        // which were already set above.
        return $response;
    }

    /**
     * @param  array<mixed, mixed> $payload
     * @return array<mixed, mixed>
     */
    private function stampPayload(array $payload, string $requestId, string $traceId): array
    {
        // Never overwrite: a controller that set its own correlation id meant to.
        $payload['request_id'] ??= $requestId;
        $payload['trace_id'] ??= $traceId;

        return $payload;
    }

    private function resolveIdentifier(string $attribute, string $prefix): string
    {
        $existing = $_SERVER['MYTH_' . strtoupper($attribute)] ?? null;
        if (is_string($existing) && trim($existing) !== '') {
            return trim($existing);
        }

        return $prefix . bin2hex(random_bytes(8));
    }

    private function buildClientFingerprint(Request $request): string
    {
        $parts = [
            $request->ip(),
            $request->userAgent(),
            $request->method(),
            $request->path(),
            strtolower((string) $request->header('accept', '')),
        ];

        return hash('sha256', implode('|', $parts));
    }

    private function buildSecurityContext(Request $request, string $requestId, string $traceId, string $clientFingerprint): array
    {
        return [
            'event' => 'request.captured',
            'request_id' => $requestId,
            'trace_id' => $traceId,
            'client_fingerprint' => $clientFingerprint,
            'ip_address' => $request->ip(),
            'method' => $request->method(),
            'path' => $request->path(),
            'expects_json' => $request->expectsJson(),
            'is_api' => $request->isApi(),
            'platform' => $request->platform(),
            'browser' => $request->browser(),
        ];
    }
}