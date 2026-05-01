<?php

declare(strict_types=1);

use App\Http\Middleware\ValidateUploadGuard;
use Core\Http\Request;
use PHPUnit\Framework\TestCase;

final class ValidateUploadGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['config']['framework']['upload_guards'] = [
            'image-cropper' => [
                'require_ajax' => true,
                'required_fields' => ['entity_id', 'entity_type', 'entity_file_type', 'image'],
                'entity_types' => ['users'],
                'entity_file_types' => ['USER_PROFILE', 'avatar'],
                'folder_groups' => ['directory'],
                'folder_types' => ['avatar'],
                'base64_image_field' => 'image',
                'base64_image_mime_types' => ['image/jpeg', 'image/png'],
            ],
            'delete' => [
                'require_ajax' => true,
                'required_fields' => ['id'],
            ],
        ];
    }

    public function testAllowsValidCropperUploadContract(): void
    {
        $request = new Request([], [
            'entity_id' => 'enc_123',
            'entity_type' => 'users',
            'entity_file_type' => 'USER_PROFILE',
            'folder_group' => 'directory',
            'folder_type' => 'avatar',
            'image' => 'data:image/jpeg;base64,ZmFrZQ==',
        ], [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/api/v1/uploads/image-cropper',
            'SCRIPT_NAME' => '/index.php',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
            'CONTENT_TYPE' => 'multipart/form-data; boundary=----phpunit',
            'CONTENT_LENGTH' => '128',
        ], []);

        $middleware = new class extends ValidateUploadGuard {
            protected function reject(Request $request, int $status, string $message)
            {
                return ['code' => $status, 'message' => $message, 'files' => [], 'isUpload' => false];
            }
        };
        $middleware->setParameters(['image-cropper']);

        $response = $middleware->handle($request, fn(Request $request) => ['code' => 200, 'isUpload' => true]);

        self::assertSame(200, $response['code']);
        self::assertTrue($response['isUpload']);
    }

    public function testRejectsInvalidUploadEntityMetadata(): void
    {
        $request = new Request([], [
            'entity_id' => 'enc_123',
            'entity_type' => 'admins',
            'entity_file_type' => 'USER_PROFILE',
            'folder_group' => 'directory',
            'folder_type' => 'avatar',
            'image' => 'data:image/jpeg;base64,ZmFrZQ==',
        ], [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/api/v1/uploads/image-cropper',
            'SCRIPT_NAME' => '/index.php',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
            'CONTENT_TYPE' => 'multipart/form-data; boundary=----phpunit',
            'CONTENT_LENGTH' => '128',
        ], []);

        $middleware = new class extends ValidateUploadGuard {
            protected function reject(Request $request, int $status, string $message)
            {
                return ['code' => $status, 'message' => $message, 'files' => [], 'isUpload' => false];
            }
        };
        $middleware->setParameters(['image-cropper']);

        $response = $middleware->handle($request, fn(Request $request) => ['code' => 200, 'isUpload' => true]);

        self::assertSame(422, $response['code']);
        self::assertSame('Upload entity type is not allowed.', $response['message']);
        self::assertFalse($response['isUpload']);
    }

    public function testRejectsNonAjaxDeleteUploadAction(): void
    {
        $request = new Request([], ['id' => 'enc_123'], [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/api/v1/uploads/delete',
            'SCRIPT_NAME' => '/index.php',
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            'CONTENT_LENGTH' => '8',
        ], []);

        $middleware = new class extends ValidateUploadGuard {
            protected function reject(Request $request, int $status, string $message)
            {
                return ['code' => $status, 'message' => $message, 'files' => [], 'isUpload' => false];
            }
        };
        $middleware->setParameters(['delete']);

        $response = $middleware->handle($request, fn(Request $request) => ['code' => 200]);

        self::assertSame(403, $response['code']);
        self::assertSame('Upload requests must use XMLHttpRequest.', $response['message']);
        self::assertFalse($response['isUpload']);
    }

    public function testAllowsAjaxDeleteUploadAction(): void
    {
        $request = new Request([], ['id' => 'enc_123'], [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/api/v1/uploads/delete',
            'SCRIPT_NAME' => '/index.php',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            'CONTENT_LENGTH' => '8',
        ], []);

        $middleware = new class extends ValidateUploadGuard {
            protected function reject(Request $request, int $status, string $message)
            {
                return ['code' => $status, 'message' => $message, 'files' => [], 'isUpload' => false];
            }
        };
        $middleware->setParameters(['delete']);

        $response = $middleware->handle($request, fn(Request $request) => ['code' => 200]);

        self::assertSame(200, $response['code']);
    }
}