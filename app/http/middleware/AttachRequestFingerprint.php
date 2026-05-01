<?php

namespace App\Http\Middleware;

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

        if (!headers_sent()) {
            header('X-Request-Id: ' . $requestId, true);
            header('X-Trace-Id: ' . $traceId, true);
        }

        if (function_exists('dispatch_event')) {
            dispatch_event('request.captured', $securityContext);
        }

        $response = $next($request);

        if (is_array($response)) {
            if (!array_key_exists('request_id', $response)) {
                $response['request_id'] = $requestId;
            }

            if (!array_key_exists('trace_id', $response)) {
                $response['trace_id'] = $traceId;
            }
        }

        return $response;
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