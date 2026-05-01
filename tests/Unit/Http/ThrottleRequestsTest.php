<?php

declare(strict_types=1);

use App\Http\Middleware\ThrottleRequests;
use Core\Http\Request;
use PHPUnit\Framework\TestCase;

final class ThrottleRequestsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Request::setCurrent(null);
        $_GET = [];
        $_POST = [];
        $_FILES = [];
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/danger-zone',
            'SCRIPT_NAME' => '/index.php',
            'HTTP_HOST' => 'localhost',
            'REMOTE_ADDR' => '203.0.113.15',
        ];

        $GLOBALS['config']['security']['trusted'] = [
            'hosts' => ['localhost'],
            'proxies' => [],
        ];
        $GLOBALS['config']['security']['trusted_proxies'] = [];
    }

    public function testAggressiveThrottleUsesRemoteAddressWhenForwardedHeaderComesFromUntrustedSource(): void
    {
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.77';
        Request::setCurrent(new Request([], [], $_SERVER, []));

        $middleware = new ThrottleRequests();
        $method = new ReflectionMethod($middleware, 'getClientIp');

        self::assertSame('203.0.113.15', $method->invoke($middleware));
    }

    public function testAggressiveThrottleTrustsForwardedHeaderFromConfiguredProxy(): void
    {
        $_SERVER['REMOTE_ADDR'] = '10.0.0.12';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.77, 10.0.0.12';
        $GLOBALS['config']['security']['trusted']['proxies'] = ['10.0.0.0/24'];
        $GLOBALS['config']['security']['trusted_proxies'] = ['10.0.0.0/24'];
        Request::setCurrent(new Request([], [], $_SERVER, []));

        $middleware = new ThrottleRequests();
        $method = new ReflectionMethod($middleware, 'getClientIp');

        self::assertSame('198.51.100.77', $method->invoke($middleware));
    }

    public function testAggressiveThrottleMarksUntrustedForwardedHeaderAsSpoofed(): void
    {
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.77';
        Request::setCurrent(new Request([], [], $_SERVER, []));

        $middleware = new ThrottleRequests();
        $method = new ReflectionMethod($middleware, 'isSpoofedIP');

        self::assertTrue($method->invoke($middleware, '203.0.113.15'));
    }
}