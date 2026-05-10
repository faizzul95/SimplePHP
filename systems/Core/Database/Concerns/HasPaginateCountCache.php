<?php

namespace Core\Database\Concerns;

/**
 * Trait HasPaginateCountCache
 *
 * Provides short-lived paginate count caching, caller-based namespace
 * detection, and cache-group invalidation helpers.
 *
 * Consumed by: BaseDatabase
 *
 * @category Database
 * @package  Core\Database\Concerns
 */
trait HasPaginateCountCache
{
    /**
     * @var array<int, string> Paginate count cache namespaces to remove after a successful write.
     */
    protected array $pendingPaginateCountCacheRemovals = [];

    /**
     * Remember paginate count queries for a short time window.
     *
     * @param int $ttl Time to live in seconds.
     * @param string|null $namespace Stable cache namespace for invalidation.
     * @return $this
     */
    public function rememberCount(int $ttl = 30, ?string $namespace = null)
    {
        $namespace = trim($namespace ?? $this->detectPaginateCountCacheNamespace());

        if ($namespace === '' || $ttl < 1) {
            $this->paginateCountCacheNamespace = null;
            $this->paginateCountCacheTtl = 0;
            return $this;
        }

        $this->paginateCountCacheNamespace = $namespace;
        $this->paginateCountCacheTtl = $ttl;
        return $this;
    }

    /**
     * Backward-compatible alias for rememberCount().
     *
     * @param int $ttl Time to live in seconds.
     * @param string|null $namespace Stable cache namespace for invalidation.
     * @return $this
     */
    public function cachePaginateCounts(int $ttl = 30, ?string $namespace = null)
    {
        return $this->rememberCount($ttl, $namespace);
    }

    /**
     * Queue cache groups for removal after a successful write.
     *
     * @param string|array<int, string> $namespaces
     * @return $this
     */
    public function removeCache(string|array $namespaces)
    {
        $namespaces = is_array($namespaces) ? $namespaces : [$namespaces];

        foreach ($namespaces as $namespace) {
            $namespace = trim((string) $namespace);
            if ($namespace !== '' && !in_array($namespace, $this->pendingPaginateCountCacheRemovals, true)) {
                $this->pendingPaginateCountCacheRemovals[] = $namespace;
            }
        }

        return $this;
    }

    /**
     * Forget all cached paginate counts for a namespace.
     */
    public static function forgetPaginateCountCacheGroup(string $namespace): void
    {
        $namespace = trim($namespace);
        if ($namespace === '') {
            return;
        }

        cache()->increment(self::paginateCountCacheNamespaceVersionKey($namespace));
    }

    /**
     * Build a stable paginate count cache namespace for a class/method pair.
     */
    public static function buildPaginateCountCacheNamespace(string $className, string $functionName): string
    {
        $className = ltrim(trim($className), '\\');
        $functionName = trim($functionName);

        if ($className === '' || $functionName === '') {
            return '';
        }

        return $className . '::' . $functionName;
    }

    protected function resolvePaginateCountCache(string $suffix, string $query, array $bindings, callable $resolver): int
    {
        if ($this->paginateCountCacheNamespace === null || $this->paginateCountCacheTtl < 1) {
            return (int) $resolver();
        }

        $cacheKey = $this->buildPaginateCountCacheKey($suffix, $query, $bindings);
        $value = cache()->remember($cacheKey, $this->paginateCountCacheTtl, static function () use ($resolver) {
            return (int) $resolver();
        });

        return (int) $value;
    }

    protected function buildPaginateCountCacheKey(string $suffix, string $query, array $bindings): string
    {
        $namespaceVersion = $this->getPaginateCountCacheNamespaceVersion();

        return 'db:paginate-count:' . sha1(implode('|', [
            (string) $this->connectionName,
            (string) $this->paginateCountCacheNamespace,
            (string) $namespaceVersion,
            $suffix,
            $query,
            serialize($bindings),
        ]));
    }

    protected function getPaginateCountCacheNamespaceVersion(): int
    {
        if ($this->paginateCountCacheNamespace === null) {
            return 0;
        }

        return (int) cache()->get(
            self::paginateCountCacheNamespaceVersionKey($this->paginateCountCacheNamespace),
            0
        );
    }

    protected static function paginateCountCacheNamespaceVersionKey(string $namespace): string
    {
        return 'db:paginate-count-version:' . sha1($namespace);
    }

    protected function detectPaginateCountCacheNamespace(): string
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 8);

        foreach ($trace as $frame) {
            $className = $frame['class'] ?? null;
            $functionName = $frame['function'] ?? null;

            if (!is_string($className) || !is_string($functionName)) {
                continue;
            }

            if ($className === self::class) {
                continue;
            }

            return self::buildPaginateCountCacheNamespace($className, $functionName);
        }

        return '';
    }

    protected function flushPendingPaginateCountCacheRemovals(): void
    {
        foreach ($this->pendingPaginateCountCacheRemovals as $namespace) {
            self::forgetPaginateCountCacheGroup($namespace);
        }

        $this->pendingPaginateCountCacheRemovals = [];
    }
}