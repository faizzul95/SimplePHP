<?php

declare(strict_types=1);

use Core\Cache\FileStore;
use PHPUnit\Framework\TestCase;

final class FileStoreRenameFallbackProbe extends FileStore
{
    public bool $failFirstRename = true;
    public int $renameCalls = 0;

    protected function renameFile(string $from, string $to): bool
    {
        $this->renameCalls++;

        if ($this->failFirstRename && is_file($to)) {
            $this->failFirstRename = false;
            return false;
        }

        return parent::renameFile($from, $to);
    }
}

final class FileStoreTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = ROOT_DIR . 'storage/cache/test-file-store';
        $this->clearDirectory($this->directory);
    }

    protected function tearDown(): void
    {
        $this->clearDirectory($this->directory);
        parent::tearDown();
    }

    public function testPutReplacesExistingEntryWhenInitialRenameFails(): void
    {
        $store = new FileStoreRenameFallbackProbe($this->directory);

        self::assertTrue($store->put('alpha', 'first', 60));
        self::assertTrue($store->put('alpha', 'second', 60));
        self::assertSame('second', $store->get('alpha'));
        self::assertGreaterThanOrEqual(3, $store->renameCalls);
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