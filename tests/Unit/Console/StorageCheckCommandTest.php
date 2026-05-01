<?php

declare(strict_types=1);

use Core\Console\Kernel;
use PHPUnit\Framework\TestCase;

final class StorageCheckCommandTest extends TestCase
{
    private string $storageRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storageRoot = ROOT_DIR . 'storage/cache/storage-check-command-test';
        $this->deleteDirectory($this->storageRoot);

        bootstrapTestFrameworkServices([
            'filesystems' => [
                'default' => 'local',
                'drivers' => [
                    'local' => [
                        'adapter' => 'Core\\Filesystem\\LocalFilesystemAdapter',
                    ],
                ],
                'disks' => [
                    'local' => [
                        'driver' => 'local',
                        'root' => 'storage/cache/storage-check-command-test/local',
                        'url' => '/storage-check-test',
                    ],
                ],
            ],
            'framework' => [
                'view_path' => 'tests/Fixtures/views',
                'view_cache_path' => 'storage/cache/views-test',
                'maintenance' => [],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->storageRoot);

        parent::tearDown();
    }

    public function testStorageCheckCommandValidatesStreamedDiskOperations(): void
    {
        $kernel = new Kernel();
        $exitCode = $kernel->callSilently('storage:check', [
            'disk' => 'local',
            'path' => 'probes/health.bin',
            'bytes' => 2097152,
        ]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Integrity check', $kernel->output());
        self::assertStringContainsString('Storage disk probe completed successfully', $kernel->output());
        self::assertFalse(storage('local')->exists('probes/health.bin'));
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