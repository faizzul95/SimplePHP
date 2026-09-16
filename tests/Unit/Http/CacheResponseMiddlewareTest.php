<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Middleware\CacheResponse;
use Core\Http\HtmlResponse;
use Core\Http\Request;
use PHPUnit\Framework\TestCase;

final class CacheResponseMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        bootstrapTestFrameworkServices([
            'cache' => [
                'default' => 'array',
                'stores' => [
                    'array' => ['driver' => 'array'],
                ],
                'prefix' => 'test_',
            ],
        ]);

        cache()->flush();

        header_remove();
        http_response_code(200);
    }

    public function testMiddlewareCachesEchoedHtmlAndServesHitWithoutExecutingCallbackAgain(): void
    {
        $middleware = new CacheResponse();
        $middleware->setParameters(['300', 'public', 'tag=products']);

        $request = new Request([], [], [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/products',
            'HTTP_HOST' => 'example.test',
        ], []);
        $request->setAttribute('route.middleware', ['web', 'cache.response:300,public,tag=products']);

        $executions = 0;

        ob_start();
        $first = $middleware->handle($request, static function () use (&$executions): null {
            $executions++;
            header('Content-Type: text/html; charset=UTF-8');
            echo '<html>catalog</html>';
            return null;
        });
        $firstBody = ob_get_clean();

        ob_start();
        $second = $middleware->handle($request, static function () use (&$executions): null {
            $executions++;
            echo '<html>miss</html>';
            return null;
        });
        $secondBody = ob_get_clean();

        self::assertSame(1, $executions, 'A cache hit must not run the route again.');

        // Miss: the route echoed its own body, which the buffer captured.
        self::assertNull($first);
        self::assertSame('<html>catalog</html>', $firstBody);

        // Hit: the middleware used to echo the cached body and return null, which
        // wrote a response outside the single emission point — so compression,
        // timing headers and HEAD handling were all skipped on cache hits. It now
        // returns a response object for the kernel to emit.
        self::assertInstanceOf(HtmlResponse::class, $second);
        self::assertSame('', $secondBody, 'Nothing may be written directly on a hit.');
        self::assertSame(200, $second->status());
        self::assertSame('HIT', $second->headers()['X-Response-Cache']);

        ob_start();
        $second->emitBody();
        self::assertSame('<html>catalog</html>', (string) ob_get_clean());
    }

    /**
     * A hit must be marked so a proxy or a developer can tell it apart from a
     * freshly rendered response.
     *
     * Only the marker is asserted, not the stored Content-Type: header capture
     * goes through headers_list(), which records nothing under the CLI SAPI, so
     * the snapshot is legitimately empty here.
     */
    public function testACacheHitIsMarkedAndDoesNotRunTheRoute(): void
    {
        $middleware = new CacheResponse();
        $middleware->setParameters(['300', 'public']);

        $request = new Request([], [], [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/articles',
            'HTTP_HOST' => 'example.test',
        ], []);
        $request->setAttribute('route.middleware', ['web', 'cache.response:300,public']);

        ob_start();
        $middleware->handle($request, static function (): null {
            echo '<html>article</html>';
            return null;
        });
        ob_end_clean();

        $ranAgain = false;

        ob_start();
        $hit = $middleware->handle($request, static function () use (&$ranAgain): null {
            $ranAgain = true;
            return null;
        });
        ob_end_clean();

        self::assertInstanceOf(HtmlResponse::class, $hit);
        self::assertSame('HIT', $hit->headers()['X-Response-Cache']);
        self::assertFalse($ranAgain);
    }

    public function testMiddlewareSkipsProtectedRoutes(): void
    {
        $middleware = new CacheResponse();
        $middleware->setParameters(['300', 'public']);

        $request = new Request([], [], [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/dashboard',
            'HTTP_HOST' => 'example.test',
        ], []);
        $request->setAttribute('route.middleware', ['web', 'auth.web', 'cache.response:300,public']);

        $executions = 0;
        $result = $middleware->handle($request, static function () use (&$executions): string {
            $executions++;
            return '<html>private</html>';
        });

        self::assertSame('<html>private</html>', $result);
        self::assertSame(1, $executions);
    }
}