<?php

declare(strict_types=1);

use Core\Http\Request;
use PHPUnit\Framework\TestCase;

final class RequestHelperTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Request::setCurrent(null);
        $_GET = [];
        $_POST = [];
        $_FILES = [];
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/users/list',
            'SCRIPT_NAME' => '/index.php',
            'HTTP_HOST' => 'localhost',
            'REMOTE_ADDR' => '127.0.0.1',
        ];
    }

    public function testRequestHelperReturnsUnifiedCoreRequestInstance(): void
    {
        $_GET = ['page' => '2'];
        $_POST = ['name' => 'Alice'];

        $request = request();

        self::assertInstanceOf(Request::class, $request);
        self::assertSame('Alice', $request->input('name'));
        self::assertSame('2', $request->query('page'));
        self::assertSame('users', $request->segment(0));
    }

    public function testDetectXssFlagsInlineEventPayloads(): void
    {
        $request = new Request([], ['payload' => '<img src=x onerror=alert(1)>'], $_SERVER, []);

        self::assertTrue($request->detectXss());
    }

    public function testDetectXssAllowsIgnoredKeys(): void
    {
        $request = new Request([], ['content' => '<b onclick="alert(1)">x</b>'], $_SERVER, []);

        self::assertFalse($request->detectXss('content'));
    }

    public function testDetectXssAllowsPlainTextInput(): void
    {
        $request = new Request([], ['search' => 'normal report query'], $_SERVER, []);

        self::assertFalse($request->detectXss());
    }
}