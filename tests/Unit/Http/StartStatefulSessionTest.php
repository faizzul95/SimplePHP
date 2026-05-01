<?php

declare(strict_types=1);

use App\Http\Middleware\StartStatefulSession;
use Core\Http\Request;
use PHPUnit\Framework\TestCase;

final class StartStatefulSessionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['config']['framework']['bootstrap']['session'] = [
            'enabled' => true,
            'cli' => false,
            'api' => false,
        ];
    }

    public function testShouldStartSessionReturnsFalseForCliWhenCliSessionsDisabled(): void
    {
        $middleware = new StartStatefulSession();
        $request = new Request([], [], [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/dashboard',
            'SCRIPT_NAME' => '/index.php',
            'HTTP_HOST' => 'localhost',
        ]);

        self::assertFalse($middleware->shouldStartSession($request));
    }

    public function testShouldStartSessionReturnsFalseForJsonRequestsWhenApiSessionsDisabled(): void
    {
        $middleware = new StartStatefulSession();
        $request = new Request([], [], [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/dashboard',
            'SCRIPT_NAME' => '/index.php',
            'HTTP_HOST' => 'localhost',
            'HTTP_ACCEPT' => 'application/json',
        ]);

        $GLOBALS['config']['framework']['bootstrap']['session']['cli'] = true;

        self::assertFalse($middleware->shouldStartSession($request));
    }

    public function testShouldStartSessionReturnsTrueWhenStatefulSessionsEnabledForCurrentRuntime(): void
    {
        $middleware = new StartStatefulSession();
        $request = new Request([], [], [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/dashboard',
            'SCRIPT_NAME' => '/index.php',
            'HTTP_HOST' => 'localhost',
        ]);

        $GLOBALS['config']['framework']['bootstrap']['session']['cli'] = true;

        self::assertTrue($middleware->shouldStartSession($request));
    }
}