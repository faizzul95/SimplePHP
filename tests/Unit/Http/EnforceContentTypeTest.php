<?php

declare(strict_types=1);

use App\Http\Middleware\EnforceContentType;
use Core\Http\Request;
use PHPUnit\Framework\TestCase;

final class EnforceContentTypeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['config']['framework']['content_type_profiles'] = [
            'json' => ['application/json', 'application/*+json'],
            'form' => ['application/x-www-form-urlencoded'],
            'multipart' => ['multipart/form-data'],
        ];

        $GLOBALS['config']['security']['request_hardening']['allowed_write_content_types'] = [
            'application/json',
            'application/x-www-form-urlencoded',
            'multipart/form-data',
        ];
    }

    public function testAllowsJsonProfileForJsonPayload(): void
    {
        $middleware = new class extends EnforceContentType {
            protected function reject(Request $request, int $status, string $message)
            {
                return ['code' => $status, 'message' => $message];
            }
        };
        $middleware->setParameters(['json']);

        $request = new Request([], ['name' => 'test'], [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/api/v1/example',
            'SCRIPT_NAME' => '/index.php',
            'CONTENT_TYPE' => 'application/vnd.api+json; charset=utf-8',
            'CONTENT_LENGTH' => '18',
            'HTTP_ACCEPT' => 'application/json',
        ], []);

        $response = $middleware->handle($request, fn(Request $request) => ['code' => 200]);

        self::assertSame(200, $response['code']);
    }

    public function testRejectsMultipartWhenJsonOrFormAreRequired(): void
    {
        $middleware = new class extends EnforceContentType {
            protected function reject(Request $request, int $status, string $message)
            {
                return ['code' => $status, 'message' => $message];
            }
        };
        $middleware->setParameters(['json', 'form']);

        $request = new Request([], ['name' => 'test'], [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/api/v1/example',
            'SCRIPT_NAME' => '/index.php',
            'CONTENT_TYPE' => 'multipart/form-data; boundary=----test',
            'CONTENT_LENGTH' => '18',
            'HTTP_ACCEPT' => 'application/json',
        ], []);

        $response = $middleware->handle($request, fn(Request $request) => ['code' => 200]);

        self::assertSame(415, $response['code']);
        self::assertSame('Unsupported content type.', $response['message']);
    }

    public function testAllowsMultipartProfileForFileUploads(): void
    {
        $middleware = new class extends EnforceContentType {
            protected function reject(Request $request, int $status, string $message)
            {
                return ['code' => $status, 'message' => $message];
            }
        };
        $middleware->setParameters(['multipart']);

        $request = new Request([], [], [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/api/v1/uploads/image-cropper',
            'SCRIPT_NAME' => '/index.php',
            'CONTENT_TYPE' => 'multipart/form-data; boundary=----test',
            'CONTENT_LENGTH' => '128',
            'HTTP_ACCEPT' => 'application/json',
        ], [
            'image' => [
                'name' => 'avatar.png',
                'type' => 'image/png',
                'tmp_name' => '/tmp/phpunit',
                'error' => 0,
                'size' => 128,
            ],
        ]);

        $response = $middleware->handle($request, fn(Request $request) => ['code' => 200]);

        self::assertSame(200, $response['code']);
    }

    public function testAllowsMultipartPayloadWhenUsingDefaultAllowedTypes(): void
    {
        $middleware = new class extends EnforceContentType {
            protected function reject(Request $request, int $status, string $message)
            {
                return ['code' => $status, 'message' => $message];
            }
        };

        $request = new Request([], ['name' => 'test'], [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/api/v1/users/save',
            'SCRIPT_NAME' => '/index.php',
            'CONTENT_TYPE' => 'multipart/form-data; boundary=----test',
            'CONTENT_LENGTH' => '18',
            'HTTP_ACCEPT' => 'application/json',
        ], []);

        $response = $middleware->handle($request, fn(Request $request) => ['code' => 200]);

        self::assertSame(200, $response['code']);
    }

    public function testSkipsSafeMethodsWithoutPayload(): void
    {
        $middleware = new class extends EnforceContentType {
            protected function reject(Request $request, int $status, string $message)
            {
                return ['code' => $status, 'message' => $message];
            }
        };
        $middleware->setParameters(['json']);

        $request = new Request([], [], [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/api/v1/example',
            'SCRIPT_NAME' => '/index.php',
            'HTTP_ACCEPT' => 'application/json',
        ], []);

        $response = $middleware->handle($request, fn(Request $request) => ['code' => 200]);

        self::assertSame(200, $response['code']);
    }
}