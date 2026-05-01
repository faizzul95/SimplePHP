<?php

declare(strict_types=1);

use App\Http\Middleware\ValidateTrustedProxies;
use Core\Http\Request;
use PHPUnit\Framework\TestCase;

final class ValidateTrustedProxiesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/api/v1/example',
            'SCRIPT_NAME' => '/index.php',
            'HTTP_HOST' => 'localhost',
            'HTTP_ACCEPT' => 'application/json',
            'REMOTE_ADDR' => '127.0.0.1',
        ];

        $GLOBALS['config']['security']['trusted'] = [
            'hosts' => ['localhost'],
            'proxies' => ['127.0.0.1'],
        ];
    }

    public function testAllowsRequestsWithoutForwardedHeaders(): void
    {
        $middleware = new class extends ValidateTrustedProxies {
            protected function reject(Request $request, int $status, string $message)
            {
                return ['code' => $status, 'message' => $message];
            }
        };

        $response = $middleware->handle(new Request([], [], $_SERVER, []), fn(Request $request) => ['code' => 200]);

        self::assertSame(200, $response['code']);
    }

    public function testRejectsForwardedHeadersFromUntrustedRemote(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.20';

        $middleware = new class extends ValidateTrustedProxies {
            protected function reject(Request $request, int $status, string $message)
            {
                return ['code' => $status, 'message' => $message];
            }
        };

        $response = $middleware->handle(new Request([], [], $_SERVER, []), fn(Request $request) => ['code' => 200]);

        self::assertSame(400, $response['code']);
        self::assertSame('Forwarded headers are not allowed from untrusted proxies.', $response['message']);
    }

    public function testAllowsForwardedHeadersFromTrustedProxyRange(): void
    {
        $_SERVER['REMOTE_ADDR'] = '10.0.0.12';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.20';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        $GLOBALS['config']['security']['trusted']['proxies'] = ['10.0.0.0/24'];

        $middleware = new class extends ValidateTrustedProxies {
            protected function reject(Request $request, int $status, string $message)
            {
                return ['code' => $status, 'message' => $message];
            }
        };

        $response = $middleware->handle(new Request([], [], $_SERVER, []), fn(Request $request) => ['code' => 200]);

        self::assertSame(200, $response['code']);
    }

    public function testRejectsUnsafeWildcardTrustedProxyConfig(): void
    {
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.20';
        $GLOBALS['config']['security']['trusted']['proxies'] = ['*'];

        $middleware = new class extends ValidateTrustedProxies {
            protected function reject(Request $request, int $status, string $message)
            {
                return ['code' => $status, 'message' => $message];
            }
        };

        $response = $middleware->handle(new Request([], [], $_SERVER, []), fn(Request $request) => ['code' => 200]);

        self::assertSame(500, $response['code']);
        self::assertSame('Unsafe trusted proxy configuration.', $response['message']);
    }
}