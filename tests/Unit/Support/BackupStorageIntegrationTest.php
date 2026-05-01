<?php

declare(strict_types=1);

use Components\Backup;
use PHPUnit\Framework\TestCase;

final class BackupStorageIntegrationTest extends TestCase
{
    private string $localBackupDir;
    private string $remoteBackupDir;
    private string $fixtureDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->localBackupDir = ROOT_DIR . 'storage/cache/phpunit-backups-local';
        $this->remoteBackupDir = ROOT_DIR . 'storage/cache/phpunit-backups-remote';
        $this->fixtureDir = ROOT_DIR . 'storage/cache/phpunit-backups-fixture';

        $this->deleteDirectory($this->localBackupDir);
        $this->deleteDirectory($this->remoteBackupDir);
        $this->deleteDirectory($this->fixtureDir);

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
                        'root' => 'storage/cache/phpunit-backups-remote',
                        'url' => '/backup-storage-test',
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
        $this->deleteDirectory($this->localBackupDir);
        $this->deleteDirectory($this->remoteBackupDir);
        $this->deleteDirectory($this->fixtureDir);

        parent::tearDown();
    }

    public function testBackupCanPublishArchiveToManagedStorageDisk(): void
    {
        $fixturePath = $this->createFixtureFile('backup fixture body');

        $backup = new Backup([
            'backup_path' => $this->localBackupDir,
            'filename_prefix' => 'phpunit-backup',
            'directories' => [dirname($fixturePath)],
            'exclude' => [],
        ]);

        $result = $backup
            ->files([dirname($fixturePath)])
            ->setBackupDisk('local', 'database-backups')
            ->run();

        self::assertTrue($result['success']);
        self::assertSame('local', $result['disk']);
        self::assertIsString($result['disk_path']);
        self::assertIsString($result['disk_url']);
        self::assertFileExists((string) $result['path']);
        self::assertTrue(storage('local')->exists((string) $result['disk_path']));
        self::assertSame('/backup-storage-test/' . $result['disk_path'], $result['disk_url']);
    }

    public function testBackupCanUseConfiguredPublishDiskDefaults(): void
    {
        bootstrapTestFrameworkServices([
            'integration' => [
                'backup' => [
                    'publish' => [
                        'disk' => 'local',
                        'prefix' => 'scheduled-backups',
                    ],
                ],
            ],
        ]);

        $fixturePath = $this->createFixtureFile('config driven backup fixture');

        $result = (new Backup([
            'backup_path' => $this->localBackupDir,
            'filename_prefix' => 'configured-backup',
            'directories' => [dirname($fixturePath)],
            'exclude' => [],
        ]))
            ->files([dirname($fixturePath)])
            ->run();

        self::assertTrue($result['success']);
        self::assertSame('local', $result['disk']);
        self::assertSame('scheduled-backups/' . $result['filename'], $result['disk_path']);
        self::assertTrue(storage('local')->exists((string) $result['disk_path']));
    }

    private function createFixtureFile(string $contents): string
    {
        if (!is_dir($this->fixtureDir)) {
            mkdir($this->fixtureDir, 0775, true);
        }

        $path = $this->fixtureDir . DIRECTORY_SEPARATOR . 'fixture.txt';
        file_put_contents($path, $contents);

        return $path;
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