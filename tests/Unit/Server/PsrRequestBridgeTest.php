<?php

declare(strict_types=1);

namespace Tests\Unit\Server;

use Core\Server\PsrRequestBridge;
use PHPUnit\Framework\TestCase;

/**
 * Minimal stand-in for PSR-7's UploadedFileInterface, so the bridge can be tested
 * without pulling nyholm/psr7 into the dev dependencies.
 */
final class FakeUploadedFile
{
    public function __construct(
        private ?string $name,
        private ?string $type,
        private int $size,
        private int $error = UPLOAD_ERR_OK
    ) {
    }

    public function getClientFilename(): ?string
    {
        return $this->name;
    }

    public function getClientMediaType(): ?string
    {
        return $this->type;
    }

    public function getSize(): ?int
    {
        return $this->size;
    }

    public function getError(): int
    {
        return $this->error;
    }
}

/**
 * The worker assigned $_FILES = $psrRequest->getUploadedFiles() directly, handing
 * every consumer PSR-7 objects where PHP's native
 * ['name','type','tmp_name','error','size'] array was expected. Uploads failed
 * silently in worker mode.
 */
final class PsrRequestBridgeTest extends TestCase
{
    /** @var list<string> */
    private array $spooled = [];

    private function spool(): callable
    {
        return function (object $file): string {
            $path = '/tmp/spooled_' . count($this->spooled);
            $this->spooled[] = $path;

            return $path;
        };
    }

    // ─── $_FILES ─────────────────────────────────────────────────────

    public function testSingleUploadIsMappedToTheNativeShape(): void
    {
        $files = PsrRequestBridge::files(
            ['avatar' => new FakeUploadedFile('me.png', 'image/png', 2048)],
            $this->spool()
        );

        self::assertSame(
            ['name', 'type', 'tmp_name', 'error', 'size'],
            array_keys($files['avatar']),
            'Consumers index $_FILES by these exact keys.'
        );
        self::assertSame('me.png', $files['avatar']['name']);
        self::assertSame('image/png', $files['avatar']['type']);
        self::assertSame(2048, $files['avatar']['size']);
        self::assertSame(UPLOAD_ERR_OK, $files['avatar']['error']);
        self::assertSame('/tmp/spooled_0', $files['avatar']['tmp_name']);
    }

    public function testArrayUploadsUseParallelArraysLikePhpDoes(): void
    {
        // PHP represents name="docs[]" as $_FILES['docs']['name'][0], not as
        // $_FILES['docs'][0]['name']. Getting this backwards breaks every loop
        // written against native uploads.
        $files = PsrRequestBridge::files(
            [
                'docs' => [
                    new FakeUploadedFile('a.pdf', 'application/pdf', 10),
                    new FakeUploadedFile('b.pdf', 'application/pdf', 20),
                ],
            ],
            $this->spool()
        );

        self::assertSame(['a.pdf', 'b.pdf'], $files['docs']['name']);
        self::assertSame([10, 20], $files['docs']['size']);
        self::assertCount(2, $files['docs']['tmp_name']);
    }

    public function testFailedUploadsAreNotSpooled(): void
    {
        $files = PsrRequestBridge::files(
            ['avatar' => new FakeUploadedFile('too-big.png', 'image/png', 0, UPLOAD_ERR_INI_SIZE)],
            $this->spool()
        );

        self::assertSame(UPLOAD_ERR_INI_SIZE, $files['avatar']['error']);
        self::assertSame('', $files['avatar']['tmp_name']);
        self::assertSame([], $this->spooled, 'There is no stream to spool for a failed upload.');
    }

    public function testASpoolFailureBecomesAnUploadError(): void
    {
        $files = PsrRequestBridge::files(
            ['avatar' => new FakeUploadedFile('me.png', 'image/png', 10)],
            static function (): string {
                throw new \RuntimeException('disk full');
            }
        );

        self::assertSame(UPLOAD_ERR_CANT_WRITE, $files['avatar']['error']);
        self::assertSame('', $files['avatar']['tmp_name']);
    }

    public function testMissingClientMetadataBecomesEmptyStringsNotNull(): void
    {
        $files = PsrRequestBridge::files(
            ['avatar' => new FakeUploadedFile(null, null, 0)],
            $this->spool()
        );

        self::assertSame('', $files['avatar']['name']);
        self::assertSame('', $files['avatar']['type']);
    }

    public function testNonFileEntriesAreDropped(): void
    {
        self::assertSame([], PsrRequestBridge::files(['bogus' => 'not-a-file'], $this->spool()));
    }

    // ─── $_SERVER ────────────────────────────────────────────────────

    public function testServerParamsBuildTheExpectedRequestUri(): void
    {
        $server = PsrRequestBridge::serverParams(
            'post',
            'https',
            'api.example.test',
            8443,
            '/api/v1/users',
            'page=2',
            []
        );

        self::assertSame('POST', $server['REQUEST_METHOD']);
        self::assertSame('/api/v1/users?page=2', $server['REQUEST_URI']);
        self::assertSame('page=2', $server['QUERY_STRING']);
        self::assertSame('8443', $server['SERVER_PORT']);
        self::assertSame('on', $server['HTTPS']);
    }

    public function testServerParamsOmitTheQuestionMarkWhenThereIsNoQuery(): void
    {
        $server = PsrRequestBridge::serverParams('GET', 'http', 'localhost', null, '/health', '', []);

        self::assertSame('/health', $server['REQUEST_URI']);
        self::assertSame('80', $server['SERVER_PORT']);
        self::assertSame('', $server['HTTPS']);
    }

    public function testHeadersBecomeHttpPrefixedServerKeys(): void
    {
        $server = PsrRequestBridge::serverParams(
            'GET',
            'http',
            'localhost',
            80,
            '/',
            '',
            ['X-Request-Id' => ['abc'], 'Accept' => ['application/json']]
        );

        self::assertSame('abc', $server['HTTP_X_REQUEST_ID']);
        self::assertSame('application/json', $server['HTTP_ACCEPT']);
    }

    public function testContentTypeAndLengthAreExposedWithoutTheHttpPrefix(): void
    {
        // PHP surfaces these two as CONTENT_TYPE / CONTENT_LENGTH, and the
        // content-type middleware reads them there.
        $server = PsrRequestBridge::serverParams(
            'POST',
            'http',
            'localhost',
            80,
            '/',
            '',
            ['Content-Type' => ['application/json'], 'Content-Length' => ['42']]
        );

        self::assertSame('application/json', $server['CONTENT_TYPE']);
        self::assertSame('42', $server['CONTENT_LENGTH']);
        self::assertArrayNotHasKey('HTTP_CONTENT_TYPE', $server);
    }

    public function testRemoteAddrFallsBackWhenTheServerParamsAreEmpty(): void
    {
        $server = PsrRequestBridge::serverParams('GET', 'http', 'localhost', 80, '/', '', []);

        self::assertSame('127.0.0.1', $server['REMOTE_ADDR']);
    }

    public function testRemoteAddrIsTakenFromTheServerParamsWhenPresent(): void
    {
        $server = PsrRequestBridge::serverParams(
            'GET',
            'http',
            'localhost',
            80,
            '/',
            '',
            [],
            ['REMOTE_ADDR' => '198.51.100.9']
        );

        self::assertSame('198.51.100.9', $server['REMOTE_ADDR']);
    }
}
