<?php

declare(strict_types=1);

use Core\Filesystem\FilesystemAdapterInterface;
use Core\Filesystem\StorageManager;
use PHPUnit\Framework\TestCase;

final class StorageManagerTest extends TestCase
{
    private string $storageRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storageRoot = ROOT_DIR . 'storage/cache/storage-manager-test';
        $this->deleteDirectory($this->storageRoot);

        bootstrapTestFrameworkServices([
            'filesystems' => [
                'default' => 'local',
                'drivers' => [
                    'local' => [
                        'adapter' => 'Core\\Filesystem\\LocalFilesystemAdapter',
                    ],
                    's3' => [
                        'adapter' => App\Support\Filesystem\S3FilesystemAdapter::class,
                    ],
                    'gdrive' => [
                        'adapter' => App\Support\Filesystem\GoogleDriveFilesystemAdapter::class,
                    ],
                ],
                'disks' => [
                    'local' => [
                        'driver' => 'local',
                        'root' => 'storage/cache/storage-manager-test/local',
                    ],
                    'public' => [
                        'driver' => 'local',
                        'root' => 'storage/cache/storage-manager-test/public',
                        'url' => '/storage-test',
                    ],
                    'archive' => [
                        'driver' => 's3',
                        'bucket' => 'reports-bucket',
                        'region' => 'ap-southeast-1',
                        'base_url' => 'https://cdn.example.test/reports',
                    ],
                    'docs' => [
                        'driver' => 'gdrive',
                        'root_id' => 'drive-root-123',
                        'base_url' => 'https://drive.example.test/shared',
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

    public function testStorageHelperResolvesManagedStorageManager(): void
    {
        self::assertInstanceOf(StorageManager::class, storage());
        self::assertInstanceOf(FilesystemAdapterInterface::class, storage('public'));
    }

    public function testStorageManagerCanRegisterCustomDriverFactories(): void
    {
        $manager = new StorageManager([
            'default' => 'memory',
            'disks' => [
                'memory' => [
                    'driver' => 'memory',
                ],
            ],
        ]);

        $manager->registerDriver('memory', static fn(array $config): FilesystemAdapterInterface => new StorageManagerTestMemoryAdapter($config));

        self::assertInstanceOf(StorageManagerTestMemoryAdapter::class, $manager->disk('memory'));
    }

    public function testStorageCanResolveScaffoldedCloudAdaptersWithStablePaths(): void
    {
        $archive = storage('archive');
        $docs = storage('docs');

        self::assertInstanceOf(FilesystemAdapterInterface::class, $archive);
        self::assertInstanceOf(FilesystemAdapterInterface::class, $docs);
        self::assertSame('s3://reports-bucket/exports/daily.csv', $archive->path('exports/daily.csv'));
        self::assertSame('https://cdn.example.test/reports/exports/daily.csv', $archive->url('exports/daily.csv'));
        self::assertSame('gdrive://drive-root-123/contracts/nda.pdf', $docs->path('contracts/nda.pdf'));
        self::assertSame('https://drive.example.test/shared/contracts/nda.pdf', $docs->url('contracts/nda.pdf'));
    }

    public function testStorageCanPersistReadMoveAndDeleteFiles(): void
    {
        $disk = storage('local');

        self::assertTrue($disk->put('reports/daily.txt', 'hello storage'));
        self::assertTrue($disk->exists('reports/daily.txt'));
        self::assertSame('hello storage', $disk->get('reports/daily.txt'));

        self::assertTrue($disk->copy('reports/daily.txt', 'reports/archive/daily-copy.txt'));
        self::assertTrue($disk->move('reports/daily.txt', 'reports/daily-moved.txt'));
        self::assertFalse($disk->exists('reports/daily.txt'));
        self::assertTrue($disk->exists('reports/daily-moved.txt'));

        self::assertSame([
            'reports/archive/daily-copy.txt',
            'reports/daily-moved.txt',
        ], $disk->allFiles('reports'));

        self::assertTrue($disk->delete(['reports/daily-moved.txt', 'reports/archive/daily-copy.txt']));
        self::assertFalse($disk->exists('reports/daily-moved.txt'));
    }

    public function testStorageCanWriteStreamsAndGenerateUrls(): void
    {
        $disk = storage('public');
        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, 'streamed payload');
        rewind($stream);

        try {
            self::assertTrue($disk->writeStream('exports/report.csv', $stream));
        } finally {
            fclose($stream);
        }

        $readStream = $disk->readStream('exports/report.csv');
        try {
            self::assertSame('streamed payload', stream_get_contents($readStream));
        } finally {
            fclose($readStream);
        }

        self::assertSame('/storage-test/exports/report.csv', $disk->url('exports/report.csv'));
    }

    public function testStorageRejectsDirectoryTraversalPaths(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Directory traversal is not allowed in storage paths.');

        storage('local')->path('../escape.txt');
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

final class StorageManagerTestMemoryAdapter implements FilesystemAdapterInterface
{
    public function __construct(private array $config = [])
    {
    }

    public function put(string $path, string $contents): bool
    {
        return true;
    }

    public function writeStream(string $path, $stream): bool
    {
        return true;
    }

    public function get(string $path): string
    {
        return '';
    }

    public function readStream(string $path)
    {
        return fopen('php://temp', 'r+b');
    }

    public function delete(string|array $paths): bool
    {
        return true;
    }

    public function exists(string $path): bool
    {
        return false;
    }

    public function copy(string $from, string $to): bool
    {
        return true;
    }

    public function move(string $from, string $to): bool
    {
        return true;
    }

    public function path(string $path): string
    {
        return $path;
    }

    public function url(string $path): string
    {
        return $path;
    }

    public function makeDirectory(string $directory): bool
    {
        return true;
    }

    public function deleteDirectory(string $directory): bool
    {
        return true;
    }

    public function files(string $directory = ''): array
    {
        return [];
    }

    public function allFiles(string $directory = ''): array
    {
        return [];
    }
}