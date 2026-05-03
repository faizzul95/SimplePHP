<?php

declare(strict_types=1);

use Core\Cache\CacheManager;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class CacheManagerTest extends TestCase
{
    private string $cachePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cachePath = ROOT_DIR . 'storage/cache/test-cache-manager';
        $this->clearDirectory($this->cachePath);
    }

    protected function tearDown(): void
    {
        $this->clearDirectory($this->cachePath);
        parent::tearDown();
    }

    public function testAddUsesConfiguredPrefixWithFileStore(): void
    {
        $cache = $this->makeFileCacheManager();

        self::assertTrue($cache->add('lock', 'granted', 60));
        self::assertSame('granted', $cache->get('lock'));
        self::assertFalse($cache->add('lock', 'denied', 60));
        self::assertSame('granted', $cache->get('lock'));
    }

    public function testFileStoreAddCanReplaceExpiredEntry(): void
    {
        $cache = $this->makeFileCacheManager();

        self::assertTrue($cache->put('stale-lock', 'old', 1));

        $cacheFile = $this->cacheFilePath('spec_stale-lock');
        self::assertFileExists($cacheFile);

        $expiredPayload = str_pad((string) (time() - 10), 10, '0', STR_PAD_LEFT) . serialize('expired');
        file_put_contents($cacheFile, $expiredPayload, LOCK_EX);

        self::assertTrue($cache->add('stale-lock', 'fresh', 60));
        self::assertSame('fresh', $cache->get('stale-lock'));
    }

    private function makeFileCacheManager(): CacheManager
    {
        return new CacheManager([
            'default' => 'file',
            'stores' => [
                'file' => [
                    'driver' => 'file',
                    'path' => 'storage/cache/test-cache-manager',
                ],
            ],
            'prefix' => 'spec_',
        ]);
    }

    private function cacheFilePath(string $key): string
    {
        $hash = sha1($key);

        return $this->cachePath
            . DIRECTORY_SEPARATOR . substr($hash, 0, 2)
            . DIRECTORY_SEPARATOR . substr($hash, 2);
    }

    private function clearDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
                continue;
            }

            @unlink($item->getPathname());
        }

        @rmdir($path);
    }
}