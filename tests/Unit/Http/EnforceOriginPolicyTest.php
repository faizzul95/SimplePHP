<?php

declare(strict_types=1);

use App\Http\Middleware\EnforceOriginPolicy;
use Core\Http\Request;
use PHPUnit\Framework\TestCase;

final class EnforceOriginPolicyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $_SERVER = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/auth/login',
            'SCRIPT_NAME' => '/index.php',
            'HTTP_HOST' => 'localhost',
            'HTTP_ACCEPT' => 'application/json',
        ];

        $GLOBALS['config']['security']['csrf'] = [
            'csrf_origin_check' => true,
            'csrf_allow_missing_origin' => true,
            'csrf_trusted_origins' => [],
        ];
    }

    public function testAllowsMatchingSameOrigin(): void
    {
        $_SERVER['HTTP_ORIGIN'] = 'http://localhost';

        $middleware = new class extends EnforceOriginPolicy {
            protected function reject(Request $request, int $status, string $message)
            {
                return ['code' => $status, 'message' => $message];
            }
        };

        $response = $middleware->handle(new Request([], [], $_SERVER, []), fn(Request $request) => ['code' => 200]);

        self::assertSame(200, $response['code']);
    }

    public function testRejectsCrossSiteOrigin(): void
    {
        $_SERVER['HTTP_ORIGIN'] = 'https://evil.example';

        $middleware = new class extends EnforceOriginPolicy {
            protected function reject(Request $request, int $status, string $message)
            {
                return ['code' => $status, 'message' => $message];
            }
        };

        $response = $middleware->handle(new Request([], [], $_SERVER, []), fn(Request $request) => ['code' => 200]);

        self::assertSame(403, $response['code']);
        self::assertSame('Origin policy violation.', $response['message']);
    }

    public function testAllowsTrustedConfiguredOrigin(): void
    {
        $_SERVER['HTTP_ORIGIN'] = 'https://portal.example.test';
        $GLOBALS['config']['security']['csrf']['csrf_trusted_origins'] = ['https://portal.example.test'];

        $middleware = new class extends EnforceOriginPolicy {
            protected function reject(Request $request, int $status, string $message)
            {
                return ['code' => $status, 'message' => $message];
            }
        };

        $response = $middleware->handle(new Request([], [], $_SERVER, []), fn(Request $request) => ['code' => 200]);

        self::assertSame(200, $response['code']);
    }

    public function testAllowsMissingOriginWhenConfigured(): void
    {
        $middleware = new class extends EnforceOriginPolicy {
            protected function reject(Request $request, int $status, string $message)
            {
                return ['code' => $status, 'message' => $message];
            }
        };

        $response = $middleware->handle(new Request([], [], $_SERVER, []), fn(Request $request) => ['code' => 200]);

        self::assertSame(200, $response['code']);
    }

    public function testRejectsMissingOriginInStrictMode(): void
    {
        $middleware = new class extends EnforceOriginPolicy {
            protected function reject(Request $request, int $status, string $message)
            {
                return ['code' => $status, 'message' => $message];
            }
        };
        $middleware->setParameters(['strict']);

        $response = $middleware->handle(new Request([], [], $_SERVER, []), fn(Request $request) => ['code' => 200]);

        self::assertSame(403, $response['code']);
        self::assertSame('Origin policy violation.', $response['message']);
    }

    public function testSkipsSafeMethods(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_ORIGIN'] = 'https://evil.example';

        $middleware = new class extends EnforceOriginPolicy {
            protected function reject(Request $request, int $status, string $message)
            {
                return ['code' => $status, 'message' => $message];
            }
        };

        $response = $middleware->handle(new Request([], [], $_SERVER, []), fn(Request $request) => ['code' => 200]);

        self::assertSame(200, $response['code']);
    }
}