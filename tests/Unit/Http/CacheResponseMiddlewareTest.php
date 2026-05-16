<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Middleware\CacheResponse;
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

        self::assertNull($first);
        self::assertNull($second);
        self::assertSame('<html>catalog</html>', $firstBody);
        self::assertSame('<html>catalog</html>', $secondBody);
        self::assertSame(1, $executions);
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