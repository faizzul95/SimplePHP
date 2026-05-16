<?php

declare(strict_types=1);

namespace Core\Http;

use Core\Cache\CacheManager;

final class ResponseCache
{
    private const VERSION_KEY = '_response_cache.version';
    private const PATH_INDEX_PREFIX = '_response_cache.path.';
    private const TAG_INDEX_PREFIX = '_response_cache.tag.';
    private const ENTRY_PREFIX = 'response_cache.';

    public function __construct(private ?CacheManager $cache = null)
    {
        $this->cache ??= cache();
    }

    /** @param array<string, mixed> $options */
    public function keyFor(Request $request, array $options = []): string
    {
        $scope = (string) ($options['scope'] ?? 'public');
        $version = $this->version();
        $query = (array) $request->query();
        ksort($query);

        $segments = [
            $request->method(),
            $request->path(),
            http_build_query($query),
            $scope,
        ];

        if (($options['vary_language'] ?? false) === true) {
            $segments[] = strtolower((string) $request->header('accept-language', ''));
        }

        if ($scope === 'auth') {
            $segments[] = (string) $this->resolveAuthIdentifier();
        }

        return self::ENTRY_PREFIX . $version . '.' . hash('sha256', implode('|', $segments));
    }

    /** @param array<string, mixed> $options
     *  @return array<string, mixed>|null
     */
    public function get(Request $request, array $options = []): ?array
    {
        $cached = $this->cache->get($this->keyFor($request, $options));
        return is_array($cached) ? $cached : null;
    }

    /** @param array<string, mixed> $options
     *  @param array<string, mixed> $payload
     */
    public function put(Request $request, array $options, array $payload): void
    {
        $ttl = max(1, (int) ($options['ttl'] ?? 0));
        if ($ttl < 1) {
            return;
        }

        $key = $this->keyFor($request, $options);
        $record = [
            'status' => (int) ($payload['status'] ?? 200),
            'headers' => array_values(array_filter((array) ($payload['headers'] ?? []), static fn($header): bool => is_array($header))),
            'body' => (string) ($payload['body'] ?? ''),
            'stored_at' => date('c'),
        ];

        $this->cache->put($key, $record, $ttl);
        $this->registerPathIndex($request->path(), $key, $ttl);

        foreach ((array) ($options['tags'] ?? []) as $tag) {
            $normalizedTag = trim((string) $tag);
            if ($normalizedTag === '') {
                continue;
            }

            $this->registerTagIndex($normalizedTag, $key, $ttl);
        }
    }

    public function forget(string $path): int
    {
        $indexKey = $this->pathIndexKey($path);
        $keys = (array) $this->cache->get($indexKey, []);
        $deleted = 0;

        foreach ($keys as $key) {
            if (is_string($key) && $key !== '' && $this->cache->forget($key)) {
                $deleted++;
            }
        }

        $this->cache->forget($indexKey);

        return $deleted;
    }

    public function forgetByTag(string $tag): int
    {
        $indexKey = $this->tagIndexKey($tag);
        $keys = (array) $this->cache->get($indexKey, []);
        $deleted = 0;

        foreach ($keys as $key) {
            if (is_string($key) && $key !== '' && $this->cache->forget($key)) {
                $deleted++;
            }
        }

        $this->cache->forget($indexKey);

        return $deleted;
    }

    public function flush(): void
    {
        $this->cache->increment(self::VERSION_KEY);
    }

    /** @return array<int, array{name:string,value:string}> */
    public static function snapshotHeaders(): array
    {
        $headers = [];
        foreach (headers_list() as $header) {
            if (!is_string($header) || !str_contains($header, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $header, 2);
            $normalizedName = trim($name);
            if ($normalizedName === '') {
                continue;
            }

            $headers[] = [
                'name' => $normalizedName,
                'value' => trim($value),
            ];
        }

        return $headers;
    }

    private function version(): int
    {
        $version = (int) $this->cache->get(self::VERSION_KEY, 1);
        if ($version < 1) {
            $version = 1;
            $this->cache->put(self::VERSION_KEY, $version, 0);
        }

        return $version;
    }

    private function registerPathIndex(string $path, string $cacheKey, int $ttl): void
    {
        $indexKey = $this->pathIndexKey($path);
        $keys = (array) $this->cache->get($indexKey, []);
        $keys[] = $cacheKey;
        $keys = array_values(array_unique(array_filter($keys, static fn($key): bool => is_string($key) && $key !== '')));
        $this->cache->put($indexKey, $keys, $ttl);
    }

    private function registerTagIndex(string $tag, string $cacheKey, int $ttl): void
    {
        $indexKey = $this->tagIndexKey($tag);
        $keys = (array) $this->cache->get($indexKey, []);
        $keys[] = $cacheKey;
        $keys = array_values(array_unique(array_filter($keys, static fn($key): bool => is_string($key) && $key !== '')));
        $this->cache->put($indexKey, $keys, $ttl);
    }

    private function pathIndexKey(string $path): string
    {
        return self::PATH_INDEX_PREFIX . $this->version() . '.' . hash('sha256', $this->normalizePath($path));
    }

    private function tagIndexKey(string $tag): string
    {
        return self::TAG_INDEX_PREFIX . $this->version() . '.' . hash('sha256', strtolower(trim($tag)));
    }

    private function normalizePath(string $path): string
    {
        $trimmed = '/' . trim($path, '/');
        return $trimmed === '//' ? '/' : $trimmed;
    }

    private function resolveAuthIdentifier(): int
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