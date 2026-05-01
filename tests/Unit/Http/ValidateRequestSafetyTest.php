<?php

declare(strict_types=1);

use App\Http\Middleware\ValidateRequestSafety;
use Core\Http\Request;
use PHPUnit\Framework\TestCase;

final class ValidateRequestSafetyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['config']['security']['request_hardening'] = [
            'enabled' => true,
            'max_uri_length' => 2000,
            'max_body_bytes' => 1048576,
            'max_user_agent_length' => 1024,
            'max_header_count' => 64,
            'max_input_vars' => 200,
            'max_json_fields' => 200,
            'max_multipart_parts' => 50,
            'allowed_hosts' => [],
            'allowed_write_content_types' => [
                'application/json',
                'application/x-www-form-urlencoded',
                'multipart/form-data',
                'text/plain',
            ],
        ];

        $_GET = [];
        $_POST = [];
        $_FILES = [];
        $_SERVER = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/api/security/check',
            'SCRIPT_NAME' => '/index.php',
            'HTTP_HOST' => 'localhost',
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
            'CONTENT_LENGTH' => '2',
            'HTTP_USER_AGENT' => 'PHPUnit/Safety',
            'REMOTE_ADDR' => '127.0.0.20',
        ];
    }

    public function testRejectsTooManyHeaders(): void
    {
        $_SERVER['HTTP_X_CUSTOM_1'] = 'a';
        $_SERVER['HTTP_X_CUSTOM_2'] = 'b';
        $_SERVER['HTTP_X_CUSTOM_3'] = 'c';

        $this->setRequestHardeningConfig([
            'max_header_count' => 6,
        ]);

        $middleware = new class extends ValidateRequestSafety {
            protected function reject(Request $request, int $status, string $message)
            {
                return ['code' => $status, 'message' => $message];
            }
        };

        $response = $middleware->handle(new Request([], [], $_SERVER, []), fn(Request $request) => ['code' => 200]);

        self::assertSame(431, $response['code']);
        self::assertSame('Too many request headers.', $response['message']);
    }

    public function testRejectsJsonPayloadWithTooManyFields(): void
    {
        $payload = ['one' => 1, 'two' => ['three' => 3, 'four' => 4]];
        $request = new Request([], $payload, $_SERVER, []);

        $this->setRequestHardeningConfig([
            'max_json_fields' => 3,
        ]);

        $middleware = new class extends ValidateRequestSafety {
            protected function reject(Request $request, int $status, string $message)
            {
                return ['code' => $status, 'message' => $message];
            }
        };

        $response = $middleware->handle($request, fn(Request $request) => ['code' => 200]);

        self::assertSame(413, $response['code']);
        self::assertSame('JSON payload contains too many fields.', $response['message']);
    }

    public function testRejectsMultipartPayloadWithTooManyParts(): void
    {
        $_SERVER['CONTENT_TYPE'] = 'multipart/form-data; boundary=----phpunit';
        $_SERVER['CONTENT_LENGTH'] = '100';
        $_POST = ['meta' => ['a' => 1, 'b' => 2]];
        $_FILES = [
            'upload' => [
                'name' => ['a.txt', 'b.txt'],
                'type' => ['text/plain', 'text/plain'],
                'tmp_name' => ['a', 'b'],
                'error' => [0, 0],
                'size' => [10, 10],
            ],
        ];

        $this->setRequestHardeningConfig([
            'max_multipart_parts' => 3,
        ]);

        $middleware = new class extends ValidateRequestSafety {
            protected function reject(Request $request, int $status, string $message)
            {
                return ['code' => $status, 'message' => $message];
            }
        };

        $response = $middleware->handle(new Request([], [], $_SERVER, []), fn(Request $request) => ['code' => 200]);

        self::assertSame(413, $response['code']);
        self::assertSame('Too many multipart parts.', $response['message']);
    }

    private function setRequestHardeningConfig(array $overrides): void
    {
        $GLOBALS['config']['security']['request_hardening'] = array_merge(
            $GLOBALS['config']['security']['request_hardening'] ?? [],
            $overrides
        );
    }
}