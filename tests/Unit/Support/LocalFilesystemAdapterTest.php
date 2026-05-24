<?php

declare(strict_types=1);

use Core\Filesystem\LocalFilesystemAdapter;
use PHPUnit\Framework\TestCase;

final class LocalFilesystemAdapterRenameProbe extends LocalFilesystemAdapter
{
    public bool $failFirstReplaceRename = true;
    public int $renameCalls = 0;

    protected function renameFile(string $from, string $to): bool
    {
        $this->renameCalls++;

        if ($this->failFirstReplaceRename && is_file($to)) {
            $this->failFirstReplaceRename = false;
            return false;
        }

        return parent::renameFile($from, $to);
    }
}

final class LocalFilesystemAdapterTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = ROOT_DIR . 'storage/cache/test-local-filesystem';
        $this->deleteDirectory($this->root);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->root);
        parent::tearDown();
    }

    public function testPutReplacesExistingFileWhenInitialRenameFails(): void
    {
        $adapter = new LocalFilesystemAdapterRenameProbe([
            'root' => $this->root,
        ]);

        self::assertTrue($adapter->put('exports/report.txt', 'first'));
        self::assertTrue($adapter->put('exports/report.txt', 'second'));
        self::assertSame('second', $adapter->get('exports/report.txt'));
        self::assertGreaterThanOrEqual(3, $adapter->renameCalls);
    }

    private function deleteDirectory(string $path): void
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