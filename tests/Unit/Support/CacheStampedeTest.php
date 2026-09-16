<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use Core\Cache\CacheManager;
use PHPUnit\Framework\TestCase;

/**
 * remember() carried a comment saying add() "eliminates the TOCTOU race where
 * two concurrent requests both see a cache miss and both execute the expensive
 * callback". It did not: the callback ran before the add(), so every concurrent
 * caller recomputed and only the write was deduplicated.
 *
 * A single-process test cannot reproduce true concurrency, so these drive the
 * pieces the fix is built from — the lock, the wait, and the fallback — plus the
 * cached-null bug that made remember() around a nullable lookup a no-op.
 */
final class CacheStampedeTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/myth-cache-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->directory)) {
            $this->deleteTree($this->directory);
        }

        parent::tearDown();
    }

    private function deleteTree(string $path): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($path);
    }

    /** @param array<string, mixed> $stampede */
    private function cache(array $stampede = []): CacheManager
    {
        return new CacheManager([
            'default' => 'file',
            'stores' => ['file' => ['driver' => 'file', 'path' => $this->directory]],
            'prefix' => 'test_',
            'stampede' => array_merge(['enabled' => true, 'lock_seconds' => 10, 'wait_ms' => 100], $stampede),
        ]);
    }

    // ─── Caching a null ──────────────────────────────────────────────

    /**
     * The old fast path was `if ($cached !== null) return $cached;`, so a lookup
     * that legitimately returns null was recomputed on every single request —
     * exactly the call remember() was wrapped around to avoid.
     */
    public function testACachedNullIsAHitNotAMiss(): void
    {
        $cache = $this->cache();
        $calls = 0;

        $callback = static function () use (&$calls) {
            $calls++;

            return null;
        };

        self::assertNull($cache->remember('missing-user', 60, $callback));
        self::assertNull($cache->remember('missing-user', 60, $callback));
        self::assertNull($cache->remember('missing-user', 60, $callback));

        self::assertSame(1, $calls, 'A cached null must not be recomputed.');
    }

    public function testAFalseyValueIsAlsoCached(): void
    {
        $cache = $this->cache();
        $calls = 0;

        foreach ([0, '', false, []] as $index => $value) {
            $calls = 0;
            $callback = static function () use (&$calls, $value) {
                $calls++;

                return $value;
            };

            $cache->remember('falsey-' . $index, 60, $callback);
            $cache->remember('falsey-' . $index, 60, $callback);

            self::assertSame(1, $calls, 'Value ' . var_export($value, true) . ' was recomputed.');
        }
    }

    // ─── The lock ────────────────────────────────────────────────────

    public function testTheComputingCallerReleasesTheLock(): void
    {
        $cache = $this->cache();

        $cache->remember('report', 60, static fn(): string => 'value');

        self::assertFalse($cache->has('report:__lock'), 'A held lock would block the next miss.');
    }

    /**
     * A callback that throws must not leave the lock behind, or the key is
     * unwritable until the lock TTL expires and every caller pays the full wait
     * before recomputing.
     */
    public function testAThrowingCallbackStillReleasesTheLock(): void
    {
        $cache = $this->cache();

        try {
            $cache->remember('report', 60, static fn() => throw new \RuntimeException('query failed'));
            self::fail('The exception should have propagated.');
        } catch (\RuntimeException) {
            // Expected.
        }

        self::assertFalse($cache->has('report:__lock'));
    }

    public function testTheExceptionFromTheCallbackIsNotSwallowed(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('query failed');

        $this->cache()->remember('report', 60, static fn() => throw new \RuntimeException('query failed'));
    }

    /**
     * Another process holding the lock while the value is already published: the
     * waiting caller must return the published value rather than recompute.
     */
    public function testAWaitingCallerPicksUpAValuePublishedByTheHolder(): void
    {
        $cache = $this->cache();

        // Simulate the holder: lock taken, value already written.
        $cache->put('report:__lock', 1, 10);
        $cache->put('report', 'published-by-someone-else', 60);

        $calls = 0;
        $value = $cache->remember('report', 60, static function () use (&$calls) {
            $calls++;

            return 'recomputed';
        });

        self::assertSame('published-by-someone-else', $value);
        self::assertSame(0, $calls);
    }

    /**
     * A lock holder that died without publishing must not turn every later
     * request into a permanent wait. After the bounded wait the caller computes.
     */
    public function testAStuckLockFallsBackToComputingRatherThanHanging(): void
    {
        $cache = $this->cache(['wait_ms' => 60]);
        $cache->put('report:__lock', 1, 10);

        $started = microtime(true);
        $value = $cache->remember('report', 60, static fn(): string => 'computed anyway');
        $elapsed = (microtime(true) - $started) * 1000;

        self::assertSame('computed anyway', $value);
        self::assertLessThan(1000, $elapsed, 'The wait must be bounded.');
    }

    public function testTheFallbackStillPublishesTheValue(): void
    {
        $cache = $this->cache(['wait_ms' => 30]);
        $cache->put('report:__lock', 1, 10);

        $cache->remember('report', 60, static fn(): string => 'computed anyway');

        self::assertSame('computed anyway', $cache->get('report'));
    }

    // ─── Opting out ──────────────────────────────────────────────────

    public function testProtectionCanBeTurnedOff(): void
    {
        $cache = $this->cache(['enabled' => false]);
        $cache->put('report:__lock', 1, 10);

        $started = microtime(true);
        $value = $cache->remember('report', 60, static fn(): string => 'no waiting');
        $elapsed = (microtime(true) - $started) * 1000;

        self::assertSame('no waiting', $value);
        self::assertLessThan(50, $elapsed, 'With protection off there is nothing to wait for.');
    }

    public function testWithProtectionOffNoLockKeyIsLeftBehind(): void
    {
        $cache = $this->cache(['enabled' => false]);

        $cache->remember('report', 60, static fn(): string => 'value');

        self::assertFalse($cache->has('report:__lock'));
    }

    // ─── Ordinary behaviour is unchanged ─────────────────────────────

    public function testAHitSkipsTheCallbackEntirely(): void
    {
        $cache = $this->cache();
        $cache->put('report', 'cached', 60);

        $calls = 0;
        $value = $cache->remember('report', 60, static function () use (&$calls) {
            $calls++;

            return 'fresh';
        });

        self::assertSame('cached', $value);
        self::assertSame(0, $calls);
    }

    public function testTheComputedValueIsStoredUnderTheRequestedKey(): void
    {
        $cache = $this->cache();

        $cache->remember('report', 60, static fn(): array => ['rows' => 3]);

        self::assertSame(['rows' => 3], $cache->get('report'));
    }

    public function testRememberForeverStoresWithoutATtl(): void
    {
        $cache = $this->cache();

        $cache->rememberForever('settings', static fn(): string => 'permanent');

        self::assertSame(0, $cache->getMetadata('settings')['expires_in'] ?? -1);
    }
}
