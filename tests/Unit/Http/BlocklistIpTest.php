<?php

declare(strict_types=1);

use App\Http\Middleware\BlocklistIp;
use Core\Http\Request;
use Core\Security\IpBlocklist;
use PHPUnit\Framework\TestCase;

final class BlocklistIpTest extends TestCase
{
    public function testMiddlewareRejectsBlockedIpBeforeNextHandler(): void
    {
        $middleware = new class(new class extends IpBlocklist {
            public function decisionFor(?Request $request = null): ?array
            {
                return ['blocked' => true, 'ip' => '203.0.113.15', 'reason' => 'Manual block', 'source' => 'dynamic-manual'];
            }
        }) extends BlocklistIp {
            protected function reject(Request $request, int $status, string $message)
            {
                return ['code' => $status, 'message' => $message];
            }
        };

        $request = new Request([], [], [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/admin',
            'SCRIPT_NAME' => '/index.php',
            'HTTP_HOST' => 'localhost',
            'REMOTE_ADDR' => '203.0.113.15',
        ], []);

        $response = $middleware->handle($request, static fn() => ['code' => 200]);

        self::assertSame(403, $response['code']);
        self::assertSame('Manual block', $response['message']);
    }

    public function testMiddlewareAllowsRequestsWhenIpIsNotBlocked(): void
    {
        $middleware = new BlocklistIp(new class extends IpBlocklist {
            public function decisionFor(?Request $request = null): ?array
            {
                return null;
            }
        });

        $request = new Request([], [], [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/health',
            'SCRIPT_NAME' => '/index.php',
            'HTTP_HOST' => 'localhost',
            'REMOTE_ADDR' => '203.0.113.15',
        ], []);

        $response = $middleware->handle($request, static fn() => ['code' => 200]);

        self::assertSame(200, $response['code']);
    }
}