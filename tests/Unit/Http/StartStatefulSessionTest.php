<?php

declare(strict_types=1);

use App\Http\Middleware\StartStatefulSession;
use Core\Http\Request;
use PHPUnit\Framework\TestCase;

final class StartStatefulSessionProbe extends StartStatefulSession
{
    public bool $startNativeSessionResult = true;
    public array $reportedFailures = [];

    protected function startNativeSession(): bool
    {
        return $this->startNativeSessionResult;
    }

    protected function reportSessionStartFailure(string $source): void
    {
        $this->reportedFailures[] = $source;
    }
}

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

    public function testShouldStartSessionReturnsTrueForForcedJsonRequests(): void
    {
        $middleware = new StartStatefulSession();
        $middleware->setParameters(['force']);

        $request = new Request([], [], [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/api/v1/dashboard/count-admin',
            'SCRIPT_NAME' => '/index.php',
            'HTTP_HOST' => 'localhost',
            'HTTP_ACCEPT' => 'application/json',
        ]);

        $GLOBALS['config']['framework']['bootstrap']['session']['cli'] = true;

        self::assertTrue($middleware->shouldStartSession($request));
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

    public function testHandleReportsSessionStartFailuresWithoutExecutingNextTwice(): void
    {
        $middleware = new StartStatefulSessionProbe();
        $request = new Request([], [], [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/dashboard',
            'SCRIPT_NAME' => '/index.php',
            'HTTP_HOST' => 'localhost',
        ]);

        $GLOBALS['config']['framework']['bootstrap']['session']['cli'] = true;
        $middleware->startNativeSessionResult = false;

        $calls = 0;
        $result = $middleware->handle($request, static function () use (&$calls) {
            $calls++;
            return 'next';
        });

        self::assertSame('next', $result);
        self::assertSame(1, $calls);
        self::assertSame(['App\\Http\\Middleware\\StartStatefulSession'], $middleware->reportedFailures);
    }
}