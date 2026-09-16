<?php

declare(strict_types=1);

use Core\Http\Request;
use Core\Http\ResponseEmitted;
use PHPUnit\Framework\TestCase;
use Middleware\DetectIdor;

final class DetectIdorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
    }

    public function testAllowsRequestsWhenRouteOwnerParameterIsMissing(): void
    {
        $_SESSION['user_id'] = 44;

        $request = new Request([], [], [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/users/profile',
            'SCRIPT_NAME' => '/index.php',
            'HTTP_HOST' => 'localhost',
        ]);

        $middleware = new DetectIdor();
        $calls = 0;

        $result = $middleware->handle($request, static function () use (&$calls) {
            $calls++;
            return 'next';
        });

        self::assertSame('next', $result);
        self::assertSame(1, $calls);
    }

    public function testRejectsMalformedOwnerIdentifierInsteadOfCoercingToZero(): void
    {
        $_SESSION['user_id'] = 44;

        $request = new Request([], [], [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/users/abc',
            'SCRIPT_NAME' => '/index.php',
            'HTTP_HOST' => 'localhost',
        ]);
        $request->setRouteParams(['user_id' => 'abc']);

        $middleware = new DetectIdor();

        // Was: called a global abort() and echoed JSON before exit. Now it throws
        // ResponseEmitted, so the status and body can be asserted directly and the
        // middleware stack unwinds instead of the process dying.
        try {
            $middleware->handle($request, static fn () => 'next');
            self::fail('A malformed owner identifier must not reach the route action.');
        } catch (ResponseEmitted $emitted) {
            self::assertSame(404, $emitted->status());
            self::assertSame(
                ['code' => 404, 'error' => 'Resource not found.'],
                $emitted->payload()
            );
        }
    }

    public function testBlocksAccessToAnotherUsersResource(): void
    {
        $_SESSION['user_id'] = 44;

        $request = new Request([], [], [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/users/99',
            'SCRIPT_NAME' => '/index.php',
            'HTTP_HOST' => 'localhost',
        ]);
        $request->setRouteParams(['user_id' => '99']);

        $middleware = new DetectIdor();

        try {
            $middleware->handle($request, static fn () => 'next');
            self::fail('An IDOR attempt must not reach the route action.');
        } catch (ResponseEmitted $emitted) {
            self::assertSame(403, $emitted->status());
            self::assertSame(
                ['code' => 403, 'error' => 'Access denied.'],
                $emitted->payload()
            );
        }
    }

    public function testAllowsAccessToOwnedResource(): void
    {
        $_SESSION['user_id'] = 44;

        $request = new Request([], [], [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/users/44',
            'SCRIPT_NAME' => '/index.php',
            'HTTP_HOST' => 'localhost',
        ]);
        $request->setRouteParams(['user_id' => '44']);

        $middleware = new DetectIdor();
        $calls = 0;

        $result = $middleware->handle($request, static function () use (&$calls) {
            $calls++;
            return 'owned';
        });

        self::assertSame('owned', $result);
        self::assertSame(1, $calls);
    }
}