<?php

declare(strict_types=1);

use Core\Http\Request;
use PHPUnit\Framework\TestCase;
use Middleware\DetectIdor;

if (!function_exists('abort')) {
    function abort(int $statusCode, string $message = ''): never
    {
        throw new RuntimeException($statusCode . ':' . $message);
    }
}

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

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('404:Resource not found.');

        $middleware->handle($request, static fn () => 'next');
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