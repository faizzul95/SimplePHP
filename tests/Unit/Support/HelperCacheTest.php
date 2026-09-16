<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/**
 * loadHelperFiles() globbed app/helpers and stat'd each file on every request —
 * 0.85 ms of syscalls for a directory that only changes at deploy. config:cache
 * now writes the resolved list and config:clear removes it.
 */
final class HelperCacheTest extends TestCase
{
    private string $cacheFile;
    private ?string $backup = null;

    protected function setUp(): void
    {
        $this->cacheFile = helperCacheFile();

        if (is_file($this->cacheFile)) {
            $this->backup = (string) file_get_contents($this->cacheFile);
            unlink($this->cacheFile);
        }
    }

    protected function tearDown(): void
    {
        if (is_file($this->cacheFile)) {
            unlink($this->cacheFile);
        }

        if ($this->backup !== null) {
            file_put_contents($this->cacheFile, $this->backup);
        }
    }

    public function testDiscoveryReturnsEveryHelperFileSorted(): void
    {
        $files = discoverHelperFiles();

        self::assertNotEmpty($files, 'No helper files discovered.');

        $sorted = $files;
        sort($sorted, SORT_NATURAL | SORT_FLAG_CASE);
        self::assertSame($sorted, $files, 'Load order must be deterministic.');

        foreach ($files as $file) {
            self::assertFileExists($file);
            self::assertStringEndsWith('.php', $file);
        }
    }

    public function testTheCacheFileLivesUnderStorageCache(): void
    {
        self::assertStringEndsWith(
            'storage' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'helpers.cache.php',
            helperCacheFile()
        );
    }

    public function testLoadingFallsBackToDiscoveryWhenNoCacheExists(): void
    {
        self::assertFileDoesNotExist($this->cacheFile);

        loadHelperFiles();

        self::assertTrue(function_exists('jsonResponse'), 'Helpers were not loaded without a cache.');
    }

    public function testLoadingUsesTheCachedListWhenPresent(): void
    {
        $probe = sys_get_temp_dir() . '/mythphp_helper_probe_' . uniqid() . '.php';
        file_put_contents($probe, '<?php function mythphp_helper_cache_probe() { return true; }');

        file_put_contents($this->cacheFile, '<?php return ' . var_export([$probe], true) . ';');

        try {
            loadHelperFiles();
            self::assertTrue(
                function_exists('mythphp_helper_cache_probe'),
                'loadHelperFiles() ignored the cached list and globbed instead.'
            );
        } finally {
            @unlink($probe);
        }
    }

    public function testAnEmptyOrCorruptCacheFallsBackToDiscovery(): void
    {
        file_put_contents($this->cacheFile, '<?php return [];');

        loadHelperFiles();

        self::assertTrue(function_exists('jsonResponse'), 'An empty cache must fall back to discovery.');
    }

    /**
     * A helper deleted after config:cache leaves the list pointing at a missing file.
     * Loading must notice and rediscover rather than skip the rest of the list.
     */
    public function testACachedPathThatNoLongerExistsFallsBackToDiscovery(): void
    {
        $probe = sys_get_temp_dir() . '/mythphp_stale_probe_' . uniqid() . '.php';
        file_put_contents($probe, '<?php function mythphp_stale_probe_loaded() { return true; }');

        // The missing entry comes first, so a naive loader would stop before the probe.
        file_put_contents($this->cacheFile, '<?php return ' . var_export([
            sys_get_temp_dir() . '/mythphp_definitely_deleted_' . uniqid() . '.php',
            $probe,
        ], true) . ';');

        try {
            loadHelperFiles();

            self::assertTrue(
                function_exists('jsonResponse'),
                'A stale cache entry must trigger rediscovery so the real helpers still load.'
            );
            self::assertFalse(
                function_exists('mythphp_stale_probe_loaded'),
                'Loading should abandon the stale list, not keep walking it.'
            );
        } finally {
            @unlink($probe);
        }
    }

    public function testConfigCacheWritesTheHelperListAndConfigClearRemovesIt(): void
    {
        $commands = dirname(__DIR__, 3) . '/systems/Core/Console/Commands/';

        self::assertStringContainsString(
            'helperCacheFile',
            (string) file_get_contents($commands . 'ConfigCacheCommand.php'),
            'config:cache no longer writes the helper list.'
        );
        self::assertStringContainsString(
            'helperCacheFile()',
            (string) file_get_contents($commands . 'ConfigClearCommand.php'),
            'config:clear no longer removes the helper cache, so it would go stale after a deploy.'
        );
    }
}
