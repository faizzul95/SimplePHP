<?php

declare(strict_types=1);

use App\Http\Middleware\ValidatePayloadLimits;
use Core\Http\Request;
use PHPUnit\Framework\TestCase;

final class ValidatePayloadLimitsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $_GET = [];
        $_POST = [];
        $_FILES = [];

        $GLOBALS['config']['security']['request_hardening'] = [
            'enabled' => true,
            'max_body_bytes' => 64,
            'max_header_count' => 3,
            'max_input_vars' => 4,
            'max_json_fields' => 3,
            'max_multipart_parts' => 2,
        ];
    }

    public function testRejectsTooManyHeaders(): void
    {
        $middleware = new class extends ValidatePayloadLimits {
            protected function reject(Request $request, int $status, string $message)
            {
                return ['code' => $status, 'message' => $message];
            }
        };

        $request = new Request([], [], [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/dashboard',
            'SCRIPT_NAME' => '/index.php',
            'HTTP_HOST' => 'localhost',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_ONE' => '1',
            'HTTP_X_TWO' => '2',
            'HTTP_X_THREE' => '3',
            'HTTP_X_FOUR' => '4',
        ], []);

        $response = $middleware->handle($request, fn(Request $request) => ['code' => 200]);

        self::assertSame(431, $response['code']);
        self::assertSame('Too many request headers.', $response['message']);
    }

    public function testRejectsTooLargeBody(): void
    {
        $middleware = new class extends ValidatePayloadLimits {
            protected function reject(Request $request, int $status, string $message)
            {
                return ['code' => $status, 'message' => $message];
            }
        };

        $request = new Request([], [], [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/api/v1/example',
            'SCRIPT_NAME' => '/index.php',
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_LENGTH' => '128',
            'CONTENT_TYPE' => 'application/json',
        ], []);

        $response = $middleware->handle($request, fn(Request $request) => ['code' => 200]);

        self::assertSame(413, $response['code']);
        self::assertSame('Request body too large.', $response['message']);
    }

    public function testRejectsJsonPayloadWithTooManyFields(): void
    {
        $middleware = new class extends ValidatePayloadLimits {
            protected function reject(Request $request, int $status, string $message)
            {
                return ['code' => $status, 'message' => $message];
            }
        };

        $request = new Request([], ['a' => 1, 'b' => 2, 'c' => ['d' => 4]], [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/api/v1/example',
            'SCRIPT_NAME' => '/index.php',
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
            'CONTENT_LENGTH' => '32',
        ], []);

        $response = $middleware->handle($request, fn(Request $request) => ['code' => 200]);

        self::assertSame(413, $response['code']);
        self::assertSame('JSON payload contains too many fields.', $response['message']);
    }

    public function testRejectsMultipartPayloadWithTooManyParts(): void
    {
        $_POST = ['entity_id' => '1', 'entity_type' => 'users'];
        $_FILES = [
            'image' => [
                'name' => ['a.png'],
                'type' => ['image/png'],
                'tmp_name' => ['/tmp/a'],
                'error' => [0],
                'size' => [100],
            ],
        ];

        $middleware = new class extends ValidatePayloadLimits {
            protected function reject(Request $request, int $status, string $message)
            {
                return ['code' => $status, 'message' => $message];
            }
        };

        $request = new Request([], $_POST, [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/api/v1/uploads/image-cropper',
            'SCRIPT_NAME' => '/index.php',
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'multipart/form-data; boundary=----test',
            'CONTENT_LENGTH' => '32',
        ], $_FILES);

        $response = $middleware->handle($request, fn(Request $request) => ['code' => 200]);

        self::assertSame(413, $response['code']);
        self::assertSame('Too many multipart parts.', $response['message']);
    }
}