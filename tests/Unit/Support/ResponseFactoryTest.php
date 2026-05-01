<?php

declare(strict_types=1);

use Core\Http\BinaryFileResponse;
use Core\Http\HtmlResponse;
use Core\Http\ResponseFactory;
use Core\Http\StreamedResponse;
use PHPUnit\Framework\TestCase;

final class ResponseFactoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        bootstrapTestFrameworkServices([
            'framework' => [
                'view_path' => 'tests/Fixtures/views',
                'view_cache_path' => 'storage/cache/views-test',
                'maintenance' => [],
            ],
        ]);
    }

    public function testResponseHelperResolvesManagedFactory(): void
    {
        self::assertInstanceOf(ResponseFactory::class, response());
    }

    public function testResponseFactoryCreatesHtmlResponseForViewRendering(): void
    {
        $rendered = response()->view('response_fixture', ['name' => 'Runtime']);

        self::assertInstanceOf(HtmlResponse::class, $rendered);
        self::assertSame(200, $rendered->status());
        self::assertIsString($rendered->content());
        self::assertStringContainsString('Hello Runtime', $rendered->content());
    }

    public function testResponseFactoryCanCreateBinaryDownloads(): void
    {
        $download = response()->download(ROOT_DIR . 'README.md', 'project-readme.txt');

        self::assertInstanceOf(BinaryFileResponse::class, $download);
        self::assertSame(ROOT_DIR . 'README.md', $download->path());
        self::assertArrayHasKey('Content-Disposition', $download->headers());
        self::assertStringContainsString('project-readme.txt', $download->headers()['Content-Disposition']);
    }

    public function testResponseFactoryCanCreateStreamedDownloads(): void
    {
        $streamed = response()->streamDownload(static function (): void {
            echo 'export';
        }, 'export.csv', ['Content-Type' => 'text/csv']);

        self::assertInstanceOf(StreamedResponse::class, $streamed);
        self::assertSame('text/csv', $streamed->headers()['Content-Type']);
        self::assertStringContainsString('export.csv', $streamed->headers()['Content-Disposition']);
    }

    public function testHtmlResponsesSanitizeHeaderNamesAndValues(): void
    {
        $response = response()->make('ok', 200, [
            "X-Test\r\nInjected: nope" => "safe\r\nvalue",
        ]);

        self::assertSame([
            'X-TestInjected: nope' => 'safevalue',
        ], $response->headers());
    }

    public function testStreamedResponsesSanitizeHeaderNamesAndValues(): void
    {
        $streamed = response()->stream(static function (): void {
            echo 'ok';
        }, 200, [
            "X-Stream\nHeader" => "value\r\nsecond",
        ]);

        self::assertSame([
            'X-StreamHeader' => 'valuesecond',
        ], $streamed->headers());
    }
}