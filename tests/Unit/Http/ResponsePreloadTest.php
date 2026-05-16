<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Middleware\PreloadCriticalAssets;
use Core\Http\Request;
use Core\Http\Response;
use PHPUnit\Framework\TestCase;

final class ResponsePreloadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Response::resetLinkHeaders();
        $GLOBALS['config']['framework']['preload'] = [
            ['path' => 'assets/app.css', 'as' => 'style', 'type' => 'text/css'],
            ['path' => 'assets/app.js', 'as' => 'script'],
        ];
    }

    public function testBuildLinkHeaderValueIncludesAttributes(): void
    {
        $header = Response::buildLinkHeaderValue('/assets/app.css', 'preload', 'style', 'text/css');

        self::assertSame('</assets/app.css>; rel=preload; as=style; type="text/css"', $header);
    }

    public function testPreloadMiddlewareQueuesConfiguredAssets(): void
    {
        $middleware = new PreloadCriticalAssets();
        $request = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/dashboard', 'HTTP_HOST' => 'example.test'], []);

        $middleware->handle($request, static fn(Request $request): string => 'ok');

        self::assertCount(2, Response::pendingLinkHeaders());
        self::assertStringContainsString('as=style', Response::pendingLinkHeaders()[0]);
        self::assertStringContainsString('as=script', Response::pendingLinkHeaders()[1]);
    }
}