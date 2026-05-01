<?php

declare(strict_types=1);

use Components\Api;
use Core\Http\Request;
use PHPUnit\Framework\TestCase;

final class ApiRequestIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Request::setCurrent(null);
        $_SERVER = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/api/v1/profile?tab=security',
            'SCRIPT_NAME' => '/index.php',
            'HTTP_HOST' => 'localhost',
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
        ];
    }

    public function testApiReadsJsonInputFromUnifiedRequest(): void
    {
        Request::setCurrent(new Request([], ['name' => 'Alya', 'role' => 'admin'], $_SERVER, []));
        $api = new Api(new PDO('sqlite::memory:'), ['auth' => ['required' => false]]);

        self::assertSame(['name' => 'Alya', 'role' => 'admin'], $api->getJsonInput());
    }

    public function testApiUsesUnifiedRequestForUriAndHeaders(): void
    {
        Request::setCurrent(new Request([], [], array_merge($_SERVER, [
            'REQUEST_URI' => '/resiEmas/api/audit/logs?limit=20',
            'HTTP_AUTHORIZATION' => 'Bearer token-123',
        ]), []));

        $api = new Api(new PDO('sqlite::memory:'), ['auth' => ['required' => false]]);

        $requestUri = new ReflectionProperty(Api::class, 'requestUri');
        $requestUri->setAccessible(true);
        $headers = new ReflectionProperty(Api::class, 'headers');
        $headers->setAccessible(true);

        self::assertSame('audit/logs', $requestUri->getValue($api));
        self::assertSame('Bearer token-123', $headers->getValue($api)['Authorization']);
    }
}