<?php

declare(strict_types=1);

use App\Http\Middleware\RequireFeature;
use Components\FeatureManager;
use Core\Http\Request;
use PHPUnit\Framework\TestCase;

final class RequireFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        reset_framework_service();
        register_framework_service('feature', fn() => new FeatureManager([
            'exports.async' => true,
            'beta.dashboard' => false,
        ]));
    }

    public function testAllowsRequestWhenFeatureIsEnabled(): void
    {
        $middleware = new class extends RequireFeature {
            protected function reject(Request $request, int $status, string $message)
            {
                return ['code' => $status, 'message' => $message];
            }
        };
        $middleware->setParameters(['exports.async']);

        $request = new Request([], [], [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/exports',
            'SCRIPT_NAME' => '/index.php',
            'HTTP_HOST' => 'localhost',
            'HTTP_ACCEPT' => 'text/html',
        ], []);

        $response = $middleware->handle($request, fn(Request $request) => ['code' => 200]);

        self::assertSame(200, $response['code']);
    }

    public function testRejectsRequestWhenFeatureIsDisabled(): void
    {
        $middleware = new class extends RequireFeature {
            protected function reject(Request $request, int $status, string $message)
            {
                return ['code' => $status, 'message' => $message];
            }
        };
        $middleware->setParameters(['beta.dashboard']);

        $request = new Request([], [], [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/beta',
            'SCRIPT_NAME' => '/index.php',
            'HTTP_HOST' => 'localhost',
            'HTTP_ACCEPT' => 'application/json',
        ], []);

        $response = $middleware->handle($request, fn(Request $request) => ['code' => 200]);

        self::assertSame(403, $response['code']);
        self::assertSame('Feature disabled.', $response['message']);
    }
}