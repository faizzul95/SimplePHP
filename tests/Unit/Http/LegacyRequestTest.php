<?php

declare(strict_types=1);

use Components\Request as LegacyRequest;
use PHPUnit\Framework\TestCase;

final class LegacyRequestTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $_GET = [];
        $_POST = [];
        $_FILES = [];
        $_SERVER = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/legacy/request',
            'SCRIPT_NAME' => '/index.php',
            'HTTP_HOST' => 'localhost',
        ];
    }

    public function testConstructorPreservesRawCapturedInput(): void
    {
        $_POST = ['bio' => '<script>alert(1)</script>'];

        $request = new LegacyRequest();

        self::assertSame('<script>alert(1)</script>', $request->input('bio'));
        self::assertSame('<script>alert(1)</script>', $request->all()['bio']);
    }

    public function testLegacyPostCanStillReturnSanitizedValueWhenRequested(): void
    {
        $_POST = ['bio' => '<script>alert(1)</script>'];

        $request = new LegacyRequest();

        self::assertNotSame('<script>alert(1)</script>', $request->post('bio'));
        self::assertSame('<script>alert(1)</script>', $request->post('bio', null, false));
    }

    public function testConstructorPreservesRawUploadedFileNames(): void
    {
        $_FILES = [
            'avatar' => [
                'name' => '<svg onload=alert(1)>.png',
                'type' => 'image/png',
                'tmp_name' => '/tmp/phpunit-avatar',
                'error' => 0,
                'size' => 128,
            ],
        ];

        $request = new LegacyRequest();

        self::assertSame('<svg onload=alert(1)>.png', $request->files('avatar')['name']);
    }
}