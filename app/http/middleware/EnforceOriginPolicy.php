<?php

namespace App\Http\Middleware;

use Components\Security;
use Core\Http\Middleware\MiddlewareInterface;
use Core\Http\Request;
use Core\Http\Response;

class EnforceOriginPolicy implements MiddlewareInterface
{
    private array $config = [];

    private Security $security;

    public function __construct()
    {
        $this->security = new Security();
        $csrf = (array) config('security.csrf', []);
        $this->config = [
            'enabled' => ($csrf['csrf_origin_check'] ?? true) === true,
            'allow_missing' => ($csrf['csrf_allow_missing_origin'] ?? true) === true,
            'trusted_origins' => (array) ($csrf['csrf_trusted_origins'] ?? []),
        ];
    }

    public function setParameters(array $parameters): void
    {
        if (empty($parameters)) {
            return;
        }

        $mode = strtolower(trim((string) ($parameters[0] ?? '')));
        if ($mode === 'strict') {
            $this->config['allow_missing'] = false;
        }

        if ($mode === 'off') {
            $this->config['enabled'] = false;
        }
    }

    public function handle(Request $request, callable $next)
    {
        if (($this->config['enabled'] ?? true) !== true || !$this->shouldInspect($request)) {
            return $next($request);
        }

        if (!$this->isAllowedOriginRequest($request)) {
            return $this->reject($request, 403, 'Origin policy violation.');
        }

        return $next($request);
    }

    protected function reject(Request $request, int $status, string $message)
    {
        if ($request->expectsJson()) {
            Response::json([
                'code' => $status,
                'message' => $message,
            ], $status);
        }

        http_response_code($status);
        echo $message;
        exit;
    }

    private function shouldInspect(Request $request): bool
    {
        return in_array(strtoupper($request->method()), ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }

    private function isAllowedOriginRequest(Request $request): bool
    {
        $origin = trim((string) $request->server('HTTP_ORIGIN', ''));
        $referer = trim((string) $request->server('HTTP_REFERER', ''));
        $allowMissing = ($this->config['allow_missing'] ?? true) === true;

        if ($origin === '' && $referer === '') {
            return $allowMissing;
        }

        $allowed = $this->allowedOrigins($request);
        if (empty($allowed)) {
            return $allowMissing;
        }

        $source = $origin !== '' ? $origin : $referer;
        $candidate = $this->normalizeOrigin($source);
        if ($candidate === null) {
            return false;
        }

        return in_array($candidate, $allowed, true);
    }

    private function allowedOrigins(Request $request): array
    {
        $allowed = [];
        $host = $this->safeHostForOrigin((string) $request->server('HTTP_HOST', ''));
        if ($host !== '') {
            $scheme = str_starts_with($request->url(), 'https://') ? 'https' : 'http';
            $allowed[] = $scheme . '://' . $host;
        }

        foreach ((array) ($this->config['trusted_origins'] ?? []) as $item) {
            $normalized = $this->normalizeOrigin((string) $item);
            if ($normalized !== null) {
                $allowed[] = $normalized;
            }
        }

        return array_values(array_unique($allowed));
    }

    private function normalizeOrigin(string $value): ?string
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return null;
        }

        $parts = parse_url($value);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }

        $candidate = $parts['scheme'] . '://' . $parts['host'];
        if (isset($parts['port'])) {
            $candidate .= ':' . (int) $parts['port'];
        }

        return rtrim($candidate, '/');
    }

    private function safeHostForOrigin(string $rawHost): string
    {
        $rawHost = trim($rawHost);
        if ($rawHost === '') {
            return '';
        }

        $hostPart = $rawHost;
        $port = null;
        $wrapIpv6 = false;

        if (preg_match('/^\[([^\]]+)\](?::(\d+))?$/', $rawHost, $matches) === 1) {
            $hostPart = $matches[1];
            $port = $matches[2] ?? null;
            $wrapIpv6 = true;
        } elseif (substr_count($rawHost, ':') === 1 && preg_match('/^(.+):(\d+)$/', $rawHost, $matches) === 1) {
            $hostPart = $matches[1];
            $port = $matches[2];
        }

        $normalizedHost = $this->security->normalizeHostHeader($hostPart);
        if ($normalizedHost === '') {
            return '';
        }

        if ($wrapIpv6) {
            $normalizedHost = '[' . $normalizedHost . ']';
        }

        if ($port !== null) {
            $portNumber = (int) $port;
            if ($portNumber >= 1 && $portNumber <= 65535) {
                return strtolower($normalizedHost . ':' . $portNumber);
            }
        }

        return strtolower($normalizedHost);
    }
}