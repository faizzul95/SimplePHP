<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use Core\Security\FileUploadGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The guard had solid extension and MIME checks but no size ceiling, no proof
 * that an "image" actually decodes, and no decompression-bomb guard. It also
 * required is_uploaded_file(), which a PSR-7 worker bridge can never satisfy
 * because it spools uploads to a temp file the SAPI never registered.
 */
final class FileUploadGuardTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    private string $storageDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storageDir = ROOT_DIR . 'storage/uploads/phpunit-guard';
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        foreach (glob($this->storageDir . '/*') ?: [] as $stored) {
            @unlink($stored);
        }

        if (is_dir($this->storageDir)) {
            @rmdir($this->storageDir);
        }

        parent::tearDown();
    }

    /** Write bytes to a temp file the guard will accept as an upload source. */
    private function tempUpload(string $contents, string $name): array
    {
        $path = tempnam(sys_get_temp_dir(), 'guard_');
        self::assertIsString($path);

        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return [
            'tmp_name' => $path,
            'name' => $name,
            'error' => UPLOAD_ERR_OK,
            'size' => strlen($contents),
            'type' => 'application/octet-stream',
        ];
    }

    private function pngBytes(int $width = 4, int $height = 4): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    private function jpegBytes(int $width = 4, int $height = 4): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagejpeg($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    // ─── Worker-mode source acceptance ───────────────────────────────

    /**
     * A spooled temp file is not is_uploaded_file(), so requiring that
     * unconditionally broke every upload under a PSR-7 worker.
     */
    public function testASpooledTempFileIsAnAcceptableSource(): void
    {
        $stored = FileUploadGuard::store($this->tempUpload($this->pngBytes(), 'avatar.png'), 'phpunit-guard');

        self::assertMatchesRegularExpression('#^phpunit-guard/[0-9a-f]{32}\.png$#', $stored);
    }

    public function testAPathOutsideAnyTempDirectoryIsRejected(): void
    {
        // The property that matters: an arbitrary caller-supplied path is refused
        // even when it is a perfectly valid image with an allowed extension, so
        // the rejection is about provenance rather than content.
        $outside = ROOT_DIR . 'storage/phpunit-outside-temp.png';
        file_put_contents($outside, $this->pngBytes());

        try {
            FileUploadGuard::store([
                'tmp_name' => $outside,
                'name' => 'avatar.png',
                'error' => UPLOAD_ERR_OK,
            ], 'phpunit-guard');

            self::fail('A file outside any temp directory must not be accepted as an upload.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('not a valid upload source', $e->getMessage());
        } finally {
            @unlink($outside);
        }
    }

    public function testAMissingTempFileIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('missing or unreadable');

        FileUploadGuard::store([
            'tmp_name' => sys_get_temp_dir() . '/definitely-not-here-' . bin2hex(random_bytes(6)),
            'name' => 'x.png',
            'error' => UPLOAD_ERR_OK,
        ], 'phpunit-guard');
    }

    // ─── Size ceiling ────────────────────────────────────────────────

    public function testAFileOverTheLimitIsRejected(): void
    {
        $file = $this->tempUpload($this->pngBytes(64, 64), 'big.png');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('the limit is');

        FileUploadGuard::store($file, 'phpunit-guard', maxBytes: 10);
    }

    public function testTheSizeErrorNamesBothNumbers(): void
    {
        try {
            FileUploadGuard::store($this->tempUpload($this->pngBytes(64, 64), 'big.png'), 'phpunit-guard', 10);
            self::fail('Expected a size rejection.');
        } catch (RuntimeException $e) {
            // A user cannot act on "upload failed".
            self::assertStringContainsString('bytes', $e->getMessage());
        }
    }

    public function testAnEmptyFileIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('empty');

        FileUploadGuard::store($this->tempUpload('', 'empty.png'), 'phpunit-guard');
    }

    public function testAFileUnderTheLimitIsAccepted(): void
    {
        $stored = FileUploadGuard::store(
            $this->tempUpload($this->pngBytes(), 'ok.png'),
            'phpunit-guard',
            maxBytes: 1048576
        );

        self::assertStringEndsWith('.png', $stored);
    }

    // ─── Image content verification ──────────────────────────────────

    /**
     * finfo reads the magic bytes at the head of the file. A polyglot satisfies
     * that while carrying something else after it; getimagesize() has to parse
     * real dimensions out of the container.
     */
    public function testBytesThatDoNotDecodeAsAnImageAreRejected(): void
    {
        // PNG magic followed by junk — passes finfo, fails a real decode.
        $fake = "\x89PNG\r\n\x1a\n" . str_repeat('not actually a png', 40);

        $this->expectException(RuntimeException::class);

        FileUploadGuard::store($this->tempUpload($fake, 'fake.png'), 'phpunit-guard');
    }

    public function testAJpegRenamedToPngIsRejected(): void
    {
        // finfo would report image/jpeg against a .png extension, and even if the
        // MIME matched, the decoded type must agree with the extension.
        $this->expectException(RuntimeException::class);

        FileUploadGuard::store($this->tempUpload($this->jpegBytes(), 'actually.png'), 'phpunit-guard');
    }

    public function testAGenuineJpegIsAccepted(): void
    {
        $stored = FileUploadGuard::store($this->tempUpload($this->jpegBytes(), 'photo.jpg'), 'phpunit-guard');

        self::assertStringEndsWith('.jpg', $stored);
    }

    // ─── Extension policy still holds ────────────────────────────────

    /** @return list<array{0:string}> */
    public static function dangerousNameProvider(): array
    {
        return [
            ['shell.php'],
            ['image.php.jpg'],
            ['payload.phar'],
            ['script.svg'],
            ['config.ini'],
            ['run.sh'],
        ];
    }

    #[DataProvider('dangerousNameProvider')]
    public function testDangerousExtensionsAreStillBlocked(string $name): void
    {
        $this->expectException(RuntimeException::class);

        FileUploadGuard::store($this->tempUpload($this->pngBytes(), $name), 'phpunit-guard');
    }

    public function testAnUnlistedExtensionIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Extension not permitted');

        FileUploadGuard::store($this->tempUpload($this->pngBytes(), 'archive.zip'), 'phpunit-guard');
    }

    // ─── Upload error reporting ──────────────────────────────────────

    /** @return list<array{0:int,1:string}> */
    public static function uploadErrorProvider(): array
    {
        return [
            [UPLOAD_ERR_INI_SIZE, 'upload_max_filesize'],
            [UPLOAD_ERR_PARTIAL, 'partially uploaded'],
            [UPLOAD_ERR_NO_FILE, 'No file'],
            [UPLOAD_ERR_CANT_WRITE, 'could not write'],
        ];
    }

    #[DataProvider('uploadErrorProvider')]
    public function testUploadErrorsAreExplainedNotJustNumbered(int $code, string $expected): void
    {
        // "Upload error code: 1" tells a user nothing.
        try {
            FileUploadGuard::store(['tmp_name' => '', 'name' => 'x.png', 'error' => $code], 'phpunit-guard');
            self::fail('Expected a rejection.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString($expected, $e->getMessage());
        }
    }

    // ─── Stored file properties ──────────────────────────────────────

    public function testTheStoredNameIsRandomNotUserControlled(): void
    {
        $stored = FileUploadGuard::store($this->tempUpload($this->pngBytes(), 'my secret name.png'), 'phpunit-guard');

        self::assertStringNotContainsString('secret', $stored);
        self::assertMatchesRegularExpression('#/[0-9a-f]{32}\.png$#', $stored);
    }

    public function testUploadsLandOutsideTheWebRoot(): void
    {
        FileUploadGuard::store($this->tempUpload($this->pngBytes(), 'a.png'), 'phpunit-guard');

        self::assertDirectoryExists($this->storageDir);
        self::assertFileExists($this->storageDir . '/.htaccess');
        self::assertStringContainsString('Deny from all', (string) file_get_contents($this->storageDir . '/.htaccess'));
    }

    public function testTheSubdirectoryIsSanitised(): void
    {
        $stored = FileUploadGuard::store(
            $this->tempUpload($this->pngBytes(), 'a.png'),
            '../../etc/phpunit-guard'
        );

        self::assertStringNotContainsString('..', $stored);
    }
}
