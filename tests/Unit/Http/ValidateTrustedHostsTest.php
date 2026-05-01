<?php

declare(strict_types=1);

use App\Http\Middleware\ValidateTrustedHosts;
use Core\Http\Request;
use PHPUnit\Framework\TestCase;

final class ValidateTrustedHostsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/dashboard',
            'SCRIPT_NAME' => '/index.php',
            'HTTP_HOST' => 'localhost',
            'HTTP_ACCEPT' => 'application/json',
        ];

        $GLOBALS['config']['security']['trusted'] = [
            'hosts' => ['localhost'],
            'proxies' => [],
        ];
    }

    public function testAllowsConfiguredTrustedHost(): void
    {
        $middleware = new class extends ValidateTrustedHosts {
            protected function reject(Request $request, int $status, string $message)
            {
                return ['code' => $status, 'message' => $message];
            }
        };

        $response = $middleware->handle(new Request([], [], $_SERVER, []), fn(Request $request) => ['code' => 200]);

        self::assertSame(200, $response['code']);
    }

    public function testRejectsUntrustedHost(): void
    {
        $_SERVER['HTTP_HOST'] = 'malicious.example';

        $middleware = new class extends ValidateTrustedHosts {
            protected function reject(Request $request, int $status, string $message)
            {
                return ['code' => $status, 'message' => $message];
            }
        };

        $response = $middleware->handle(new Request([], [], $_SERVER, []), fn(Request $request) => ['code' => 200]);

        self::assertSame(400, $response['code']);
        self::assertSame('Untrusted host header.', $response['message']);
    }

    public function testAllowsExplicitMiddlewareParametersToOverrideConfig(): void
    {
        $_SERVER['HTTP_HOST'] = 'api.example.test:8080';

        $middleware = new class extends ValidateTrustedHosts {
            protected function reject(Request $request, int $status, string $message)
            {
                return ['code' => $status, 'message' => $message];
            }
        };
        $middleware->setParameters(['api.example.test']);

        $response = $middleware->handle(new Request([], [], $_SERVER, []), fn(Request $request) => ['code' => 200]);

        self::assertSame(200, $response['code']);
    }
}