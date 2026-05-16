<?php

namespace App\Http\Middleware;

use Core\Http\BinaryFileResponse;
use Core\Http\HtmlResponse;
use Core\Http\Middleware\MiddlewareInterface;
use Core\Http\RedirectResponse;
use Core\Http\Request;
use Core\Http\ResponseCache;
use Core\Http\StreamedResponse;

class CacheResponse implements MiddlewareInterface
{
    private int $ttl = 0;
    private string $scope = 'public';
    private bool $varyLanguage = false;
    /** @var array<int, string> */
    private array $tags = [];

    public function setParameters(array $parameters): void
    {
        if (isset($parameters[0]) && is_numeric($parameters[0])) {
            $this->ttl = max(0, (int) $parameters[0]);
            array_shift($parameters);
        }

        foreach ($parameters as $parameter) {
            $token = trim((string) $parameter);
            $normalized = strtolower($token);

            if ($normalized === 'public' || $normalized === 'auth') {
                $this->scope = $normalized;
                continue;
            }

            if ($normalized === 'lang' || $normalized === 'language') {
                $this->varyLanguage = true;
                continue;
            }

            if (str_starts_with($normalized, 'tag=')) {
                $tag = trim(substr($token, 4));
                if ($tag !== '') {
                    $this->tags[] = $tag;
                }
            }
        }

        $this->tags = array_values(array_unique($this->tags));
    }

    public function handle(Request $request, callable $next)
    {
        if (!$this->shouldHandle($request)) {
            return $next($request);
        }

        $cache = new ResponseCache();
        $options = $this->options();
        $cached = $cache->get($request, $options);

        if (is_array($cached)) {
            $this->sendCachedPayload($request, $cached);
            return null;
        }

        $stored = false;
        ob_start(function (string $buffer) use ($request, $cache, $options, &$stored): string {
            if ($stored) {
                return $buffer;
            }

            $payload = $this->bufferPayload($buffer);
            if ($payload !== null) {
                $cache->put($request, $options, $payload);
                $stored = true;
            }

            return $buffer;
        });

        $result = $next($request);

        if ($result instanceof HtmlResponse) {
            $this->cleanupBuffer();
            $cache->put($request, $options, [
                'status' => $result->status(),
                'headers' => $this->normalizeHeaderMap($result->headers()),
                'body' => $result->content(),
            ]);
            return $result;
        }

        if ($result instanceof RedirectResponse || $result instanceof StreamedResponse || $result instanceof BinaryFileResponse || is_array($result)) {
            $this->cleanupBuffer();
            return $result;
        }

        if (is_string($result)) {
            $buffer = (string) ob_get_contents();
            $this->cleanupBuffer();

            $body = $buffer . $result;
            $payload = $this->bufferPayload($body);
            if ($payload !== null) {
                $cache->put($request, $options, $payload);
            }

            return $body;
        }

        if ((int) ob_get_length() > 0) {
            ob_end_flush();
            return $result;
        }

        $this->cleanupBuffer();
        return $result;
    }

    private function shouldHandle(Request $request): bool
    {
        if ($this->ttl < 1) {
            return false;
        }

        if (!in_array($request->method(), ['GET', 'HEAD'], true)) {
            return false;
        }

        if ($request->expectsJson() || $request->wantsJson()) {
            return false;
        }

        $middleware = array_map('strtolower', (array) $request->attributes('route.middleware', []));
        foreach ($middleware as $item) {
            $name = trim((string) $item);
            if ($name === '' || $name === 'cache.response') {
                continue;
            }

            if ($name === 'auth' || str_starts_with($name, 'auth.')) {
                return false;
            }
        }

        if ($this->scope === 'public' && $this->resolveAuthId() > 0) {
            return false;
        }

        return true;
    }

    /** @return array<string, mixed> */
    private function options(): array
    {
        return [
            'ttl' => $this->ttl,
            'scope' => $this->scope,
            'vary_language' => $this->varyLanguage,
            'tags' => $this->tags,
        ];
    }

    /** @return array<string, mixed>|null */
    private function bufferPayload(string $body): ?array
    {
        $body = (string) $body;
        if ($body === '') {
            return null;
        }

        $status = http_response_code() ?: 200;
        if ($status !== 200) {
            return null;
        }

        $headers = ResponseCache::snapshotHeaders();
        if ($this->containsHeader($headers, 'Set-Cookie') || $this->containsHeader($headers, 'Location')) {
            return null;
        }

        $contentType = $this->firstHeaderValue($headers, 'Content-Type');
        if ($contentType !== null && stripos($contentType, 'text/html') === false && stripos($contentType, 'application/xhtml+xml') === false) {
            return null;
        }

        return [
            'status' => $status,
            'headers' => $headers,
            'body' => $body,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function sendCachedPayload(Request $request, array $payload): void
    {
        if (!headers_sent()) {
            http_response_code((int) ($payload['status'] ?? 200));

            foreach ((array) ($payload['headers'] ?? []) as $header) {
                if (!is_array($header)) {
                    continue;
                }

                $name = trim((string) ($header['name'] ?? ''));
                $value = trim((string) ($header['value'] ?? ''));
                if ($name === '') {
                    continue;
                }

                header($name . ': ' . $value, false);
            }

            header('X-Response-Cache: HIT', false);
        }

        if ($request->method() !== 'HEAD') {
            echo (string) ($payload['body'] ?? '');
        }
    }

    private function cleanupBuffer(): void
    {
        if (ob_get_level() > 0) {
            ob_end_clean();
        }
    }

    /** @param array<int, array{name:string,value:string}> $headers */
    private function containsHeader(array $headers, string $needle): bool
    {
        foreach ($headers as $header) {
            if (strcasecmp((string) ($header['name'] ?? ''), $needle) === 0) {
                return true;
            }
        }

        return false;
    }

    /** @param array<int, array{name:string,value:string}> $headers */
    private function firstHeaderValue(array $headers, string $needle): ?string
    {
        foreach ($headers as $header) {
            if (strcasecmp((string) ($header['name'] ?? ''), $needle) === 0) {
                return (string) ($header['value'] ?? '');
            }
        }

        return null;
    }

    /** @param array<string, string> $headers
     *  @return array<int, array{name:string,value:string}>
     */
    private function normalizeHeaderMap(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            if (!is_string($name) || $name === '') {
                continue;
            }

            $normalized[] = [
                'name' => $name,
                'value' => (string) $value,
            ];
        }

        return $normalized;
    }

    private function resolveAuthId(): int
    {
        if (!function_exists('auth')) {
            return 0;
        }

        try {
            return max(0, (int) (auth()->id() ?? 0));
        } catch (\Throwable) {
            return 0;
        }
    }
}