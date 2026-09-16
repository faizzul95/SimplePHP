<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use Core\Http\BinaryFileResponse;
use Core\Http\HtmlResponse;
use Core\Http\JsonResponse;
use Core\Http\Request;
use Core\Http\Responsable;
use Core\Http\StreamedResponse;
use Middleware\CompressResponse;
use PHPUnit\Framework\TestCase;

/**
 * The middleware buffered echoed output. Once responses became objects returned
 * through the pipeline, that buffer was always empty — so it compressed nothing,
 * echoed an empty string and handed the real response on uncompressed. No error,
 * just silently larger responses on every route.
 */
final class CompressResponseTest extends TestCase
{
    private function request(string $acceptEncoding = 'gzip'): Request
    {
        return new Request([], [], [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'SCRIPT_NAME' => '/index.php',
            'HTTP_HOST' => 'localhost',
            'HTTP_ACCEPT_ENCODING' => $acceptEncoding,
        ]);
    }

    private function largeHtml(): string
    {
        // Repetitive, so gzip reliably beats the original size.
        return '<html><body>' . str_repeat('<p>compress me</p>', 400) . '</body></html>';
    }

    /** Run the middleware, discarding anything it writes directly. */
    private function dispatch(Request $request, callable $next): mixed
    {
        ob_start();

        try {
            return (new CompressResponse())->handle($request, \Closure::fromCallable($next));
        } finally {
            ob_end_clean();
        }
    }

    private function bodyOf(Responsable $response): string
    {
        ob_start();
        $response->emitBody();

        return (string) ob_get_clean();
    }

    // ─── The regression ──────────────────────────────────────────────

    public function testAReturnedHtmlResponseIsActuallyCompressed(): void
    {
        $original = $this->largeHtml();

        $response = $this->dispatch(
            $this->request('gzip'),
            static fn(): Responsable => new HtmlResponse($original, 200, ['Content-Type' => 'text/html'])
        );

        self::assertInstanceOf(Responsable::class, $response);

        $headers = $response->headers();
        self::assertSame('gzip', $headers['Content-Encoding'] ?? null, 'The response was handed on uncompressed.');

        $body = $this->bodyOf($response);
        self::assertLessThan(strlen($original), strlen($body));
        self::assertSame($original, gzdecode($body), 'The compressed body must decode back to the original.');
    }

    public function testAReturnedJsonResponseIsCompressed(): void
    {
        $payload = ['items' => array_fill(0, 300, ['id' => 1, 'name' => 'a repeated value'])];

        $response = $this->dispatch(
            $this->request('gzip'),
            static fn(): Responsable => new JsonResponse($payload)
        );

        self::assertSame('gzip', $response->headers()['Content-Encoding'] ?? null);
        self::assertSame($payload, json_decode((string) gzdecode($this->bodyOf($response)), true));
    }

    public function testContentLengthMatchesTheCompressedBody(): void
    {
        $response = $this->dispatch(
            $this->request('gzip'),
            fn(): Responsable => new HtmlResponse($this->largeHtml(), 200, ['Content-Type' => 'text/html'])
        );

        self::assertSame(
            strlen($this->bodyOf($response)),
            (int) $response->headers()['Content-Length'],
            'A Content-Length describing the uncompressed body truncates the response.'
        );
    }

    public function testTheStatusCodeSurvivesCompression(): void
    {
        $response = $this->dispatch(
            $this->request('gzip'),
            fn(): Responsable => new HtmlResponse($this->largeHtml(), 503, ['Content-Type' => 'text/html'])
        );

        self::assertSame(503, $response->status());
    }

    public function testExistingHeadersAreKept(): void
    {
        $response = $this->dispatch(
            $this->request('gzip'),
            fn(): Responsable => new HtmlResponse($this->largeHtml(), 200, [
                'Content-Type' => 'text/html',
                'X-Response-Cache' => 'HIT',
            ])
        );

        self::assertSame('HIT', $response->headers()['X-Response-Cache']);
    }

    public function testVaryIsMergedRatherThanOverwritten(): void
    {
        $response = $this->dispatch(
            $this->request('gzip'),
            fn(): Responsable => new HtmlResponse($this->largeHtml(), 200, [
                'Content-Type' => 'text/html',
                'Vary' => 'Accept',
            ])
        );

        $vary = $response->headers()['Vary'];

        // Dropping the existing Vary would let a cache serve the wrong variant.
        self::assertStringContainsString('Accept', $vary);
        self::assertStringContainsString('Accept-Encoding', $vary);
    }

    // ─── When not to compress ────────────────────────────────────────

    public function testASmallResponseIsLeftAlone(): void
    {
        $response = $this->dispatch(
            $this->request('gzip'),
            static fn(): Responsable => new HtmlResponse('<p>tiny</p>', 200, ['Content-Type' => 'text/html'])
        );

        self::assertArrayNotHasKey('Content-Encoding', $response->headers());
        self::assertSame('<p>tiny</p>', $this->bodyOf($response));
    }

    public function testAlreadyCompressedContentTypesAreSkipped(): void
    {
        $response = $this->dispatch(
            $this->request('gzip'),
            fn(): Responsable => new HtmlResponse($this->largeHtml(), 200, ['Content-Type' => 'image/png'])
        );

        self::assertArrayNotHasKey('Content-Encoding', $response->headers());
    }

    public function testAttachmentsAreSkipped(): void
    {
        $response = $this->dispatch(
            $this->request('gzip'),
            fn(): Responsable => new HtmlResponse($this->largeHtml(), 200, [
                'Content-Type' => 'text/html',
                'Content-Disposition' => 'attachment; filename="report.html"',
            ])
        );

        self::assertArrayNotHasKey('Content-Encoding', $response->headers());
    }

    public function testAnAlreadyEncodedResponseIsNotDoubleCompressed(): void
    {
        $response = $this->dispatch(
            $this->request('gzip'),
            fn(): Responsable => new HtmlResponse($this->largeHtml(), 200, [
                'Content-Type' => 'text/html',
                'Content-Encoding' => 'br',
            ])
        );

        self::assertSame('br', $response->headers()['Content-Encoding']);
    }

    /** Buffering a stream to compress it would defeat the reason it is a stream. */
    public function testStreamedResponsesPassThroughUntouched(): void
    {
        $stream = new StreamedResponse(static fn() => print('chunk'), 200);

        $response = $this->dispatch($this->request('gzip'), static fn(): Responsable => $stream);

        self::assertSame($stream, $response);
    }

    public function testFileDownloadsPassThroughUntouched(): void
    {
        $file = new BinaryFileResponse(__FILE__);

        $response = $this->dispatch($this->request('gzip'), static fn(): Responsable => $file);

        self::assertArrayNotHasKey('Content-Encoding', $response->headers());
    }

    // ─── Negotiation ─────────────────────────────────────────────────

    public function testNoAcceptEncodingMeansNoCompression(): void
    {
        $response = $this->dispatch(
            $this->request(''),
            fn(): Responsable => new HtmlResponse($this->largeHtml(), 200, ['Content-Type' => 'text/html'])
        );

        self::assertArrayNotHasKey('Content-Encoding', $response->headers());
    }

    /**
     * `str_contains($accept, 'br')` matched values like "sabre". Tokens must be
     * compared exactly, after q-values are stripped.
     */
    public function testASubstringMatchDoesNotSelectBrotli(): void
    {
        $response = $this->dispatch(
            $this->request('sabre, deflate'),
            fn(): Responsable => new HtmlResponse($this->largeHtml(), 200, ['Content-Type' => 'text/html'])
        );

        self::assertArrayNotHasKey('Content-Encoding', $response->headers());
    }

    public function testQValuesAreStrippedFromTokens(): void
    {
        $response = $this->dispatch(
            $this->request('gzip;q=0.9, deflate;q=0.5'),
            fn(): Responsable => new HtmlResponse($this->largeHtml(), 200, ['Content-Type' => 'text/html'])
        );

        self::assertSame('gzip', $response->headers()['Content-Encoding'] ?? null);
    }

    public function testAWildcardAcceptsGzip(): void
    {
        $response = $this->dispatch(
            $this->request('*'),
            fn(): Responsable => new HtmlResponse($this->largeHtml(), 200, ['Content-Type' => 'text/html'])
        );

        self::assertSame('gzip', $response->headers()['Content-Encoding'] ?? null);
    }

    public function testAnExceptionFromTheRouteIsNotSwallowed(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->dispatch($this->request('gzip'), static function (): never {
            throw new \RuntimeException('route blew up');
        });
    }
}
