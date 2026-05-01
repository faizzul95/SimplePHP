<?php

declare(strict_types=1);

use Components\Files;
use PHPUnit\Framework\TestCase;

final class FilesSecurityTest extends TestCase
{
    private string $uploadDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->uploadDir = ROOT_DIR . 'storage\cache\phpunit-files';
        $this->deleteDirectory($this->uploadDir);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->uploadDir);
        $this->deleteDirectory(ROOT_DIR . 'storage\cache\phpunit-files-managed');
        parent::tearDown();
    }

    public function testStoreFileRejectsUnsafeOriginalFilename(): void
    {
        $sourcePath = $this->createTempFile('safe report');
        $files = new Files();
        $files->setUploadDir('storage/cache/phpunit-files');
        $files->setAllowedMimeTypes('text/plain');

        $result = $files->storeFile($sourcePath, '<script>alert(1)</script>.txt');

        self::assertFalse($result['isUpload']);
        self::assertSame('Uploaded file name is not allowed.', $result['message']);
    }

    public function testStoreFileRejectsUnsafeScannableDocumentByDefault(): void
    {
        $sourcePath = $this->createTempFile("hello\njavascript:alert(1)\nworld");
        $files = new Files();
        $files->setUploadDir('storage/cache/phpunit-files');
        $files->setAllowedMimeTypes('text/plain');

        $result = $files->storeFile($sourcePath, 'notes.txt');

        self::assertFalse($result['isUpload']);
        self::assertSame('Uploaded document contains unsafe content and was rejected.', $result['message']);
    }

    public function testStoreFileAllowsSafePlainTextDocument(): void
    {
        $sourcePath = $this->createTempFile("monthly report\nall clear\n");
        $files = new Files();
        $files->setUploadDir('storage/cache/phpunit-files');
        $files->setAllowedMimeTypes('text/plain');

        $result = $files->storeFile($sourcePath, 'notes.txt');

        self::assertTrue($result['isUpload']);
        self::assertSame(200, $result['code']);
        self::assertSame('text/plain', $result['files']['mime']);
        self::assertSame('txt', $result['files']['extension']);
        self::assertFileExists($result['files']['path']);
    }

    public function testStoreFileCanPersistThroughManagedStorageDisk(): void
    {
        bootstrapTestFrameworkServices([
            'filesystems' => [
                'default' => 'local',
                'disks' => [
                    'public' => [
                        'driver' => 'local',
                        'root' => 'storage/cache/phpunit-files-managed',
                        'url' => '/managed-storage-test',
                    ],
                ],
            ],
            'framework' => [
                'view_path' => 'tests/Fixtures/views',
                'view_cache_path' => 'storage/cache/views-test',
                'maintenance' => [],
            ],
        ]);

        $sourcePath = $this->createTempFile("managed report\nall clear\n");
        $files = new Files();
        $files->setStorageDisk('public', 'exports');
        $files->setAllowedMimeTypes('text/plain');

        $result = $files->storeFile($sourcePath, 'notes.txt');

        self::assertTrue($result['isUpload']);
        self::assertSame('public', $result['files']['disk']);
        self::assertSame('/managed-storage-test/' . $result['files']['relative_path'], $result['files']['url']);
        self::assertTrue(storage('public')->exists($result['files']['relative_path']));
    }

    private function createTempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'resi-files-');
        if ($path === false) {
            self::fail('Failed to allocate a temporary file.');
        }

        file_put_contents($path, $contents);

        return $path;
    }

    private function deleteDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if (!is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $itemPath = $path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($itemPath)) {
                $this->deleteDirectory($itemPath);
                continue;
            }

            @unlink($itemPath);
        }

        @rmdir($path);
    }
}