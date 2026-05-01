<?php

namespace App\Http\Middleware;

use Core\Http\Middleware\MiddlewareInterface;
use Core\Http\Request;
use Core\Http\Response;

class ValidateTrustedProxies implements MiddlewareInterface
{
    private array $trustedProxies = [];

    private const FORWARDED_HEADER_KEYS = [
        'HTTP_CF_CONNECTING_IP',
        'HTTP_CLIENT_IP',
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_FORWARDED',
        'HTTP_X_FORWARDED_PROTO',
        'HTTP_X_FORWARDED_HOST',
        'HTTP_X_CLUSTER_CLIENT_IP',
        'HTTP_FORWARDED_FOR',
        'HTTP_FORWARDED',
    ];

    public function __construct()
    {
        $this->trustedProxies = $this->normalizeProxies((array) config('security.trusted.proxies', []));
    }

    public function setParameters(array $parameters): void
    {
        $proxies = [];

        foreach ($parameters as $parameter) {
            foreach (explode(',', (string) $parameter) as $proxy) {
                $proxies[] = $proxy;
            }
        }

        $normalized = $this->normalizeProxies($proxies);
        if (!empty($normalized)) {
            $this->trustedProxies = $normalized;
        }
    }

    public function handle(Request $request, callable $next)
    {
        if ($this->containsUnsafeWildcard($this->trustedProxies)) {
            return $this->reject($request, 500, 'Unsafe trusted proxy configuration.');
        }

        if (!$this->hasForwardedHeaders($request)) {
            return $next($request);
        }

        $remoteAddr = trim((string) $request->server('REMOTE_ADDR', ''));
        if ($remoteAddr === '' || empty($this->trustedProxies) || !$this->isTrustedProxy($remoteAddr, $this->trustedProxies)) {
            return $this->reject($request, 400, 'Forwarded headers are not allowed from untrusted proxies.');
        }

        $forwardedProto = strtolower(trim((string) $request->server('HTTP_X_FORWARDED_PROTO', '')));
        if ($forwardedProto !== '' && !in_array($forwardedProto, ['http', 'https'], true)) {
            return $this->reject($request, 400, 'Invalid forwarded protocol header.');
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

    private function hasForwardedHeaders(Request $request): bool
    {
        foreach (self::FORWARDED_HEADER_KEYS as $key) {
            $value = $request->server($key, '');
            if (trim((string) $value) !== '') {
                return true;
            }
        }

        return false;
    }

    private function normalizeProxies(array $proxies): array
    {
        $normalized = [];

        foreach ($proxies as $proxy) {
            $value = trim((string) $proxy);
            if ($value === '') {
                continue;
            }

            $normalized[$value] = $value;
        }

        return array_values($normalized);
    }

    private function containsUnsafeWildcard(array $trustedProxies): bool
    {
        foreach ($trustedProxies as $proxy) {
            $value = strtolower(trim((string) $proxy));
            if (in_array($value, ['*', '0.0.0.0/0', '::/0'], true)) {
                return true;
            }
        }

        return false;
    }

    private function isTrustedProxy(string $remoteAddr, array $trustedProxies): bool
    {
        foreach ($trustedProxies as $proxy) {
            $proxy = trim((string) $proxy);
            if ($proxy === '') {
                continue;
            }

            if ($remoteAddr === $proxy) {
                return true;
            }

            if (str_contains($proxy, '/') && $this->ipInCidr($remoteAddr, $proxy)) {
                return true;
            }
        }

        return false;
    }

    private function ipInCidr(string $ip, string $cidr): bool
    {
        if (!str_contains($cidr, '/')) {
            return false;
        }

        [$subnet, $maskBits] = explode('/', $cidr, 2);
        $subnet = trim($subnet);
        $maskBits = (int) $maskBits;

        $ipBinary = @inet_pton($ip);
        $subnetBinary = @inet_pton($subnet);
        if ($ipBinary === false || $subnetBinary === false || strlen($ipBinary) !== strlen($subnetBinary)) {
            return false;
        }

        $bytes = intdiv($maskBits, 8);
        $bits = $maskBits % 8;

        if ($bytes > 0 && substr($ipBinary, 0, $bytes) !== substr($subnetBinary, 0, $bytes)) {
            return false;
        }

        if ($bits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $bits)) & 0xFF;

        return (ord($ipBinary[$bytes]) & $mask) === (ord($subnetBinary[$bytes]) & $mask);
    }
}