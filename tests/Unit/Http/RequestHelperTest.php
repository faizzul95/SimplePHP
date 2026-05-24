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

    // --- ip() proxy resolution ---

    public function testIpReturnsFallbackWhenNoTrustedProxyConfigured(): void
    {
        $GLOBALS['config']['security']['trusted']['proxies'] = [];

        $server = array_merge($_SERVER, [
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.5',
        ]);

        $request = new Request([], [], $server, []);

        // No trusted proxies configured — forwarded header is ignored, proxy IP returned
        self::assertSame('10.0.0.1', $request->ip());
    }

    public function testIpReturnsForwardedIpWhenRemoteAddrIsTrustedProxy(): void
    {
        $GLOBALS['config']['security']['trusted']['proxies'] = ['10.0.0.1'];

        $server = array_merge($_SERVER, [
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.5',
        ]);

        $request = new Request([], [], $server, []);

        self::assertSame('203.0.113.5', $request->ip());
    }

    public function testIpIgnoresForwardedHeaderFromUntrustedProxy(): void
    {
        $GLOBALS['config']['security']['trusted']['proxies'] = ['192.168.1.1'];

        $server = array_merge($_SERVER, [
            'REMOTE_ADDR' => '10.0.0.5',          // NOT in trusted list
            'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
        ]);

        $request = new Request([], [], $server, []);

        self::assertSame('10.0.0.5', $request->ip());
    }

    // --- isSecureRequest() / secure() via X-Forwarded-Proto ---

    public function testSecureReturnsTrueWhenHttpsServerVar(): void
    {
        $server = array_merge($_SERVER, [
            'HTTPS' => 'on',
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_HOST' => 'example.com',
            'REQUEST_URI' => '/',
        ]);
        $GLOBALS['config']['security']['trusted']['proxies'] = [];

        $request = new Request([], [], $server, []);

        self::assertStringStartsWith('https://', $request->fullUrl());
    }

    public function testSecureReturnsFalseForForgedXForwardedProtoFromUntrustedProxy(): void
    {
        $GLOBALS['config']['security']['trusted']['proxies'] = ['192.168.1.100'];

        $server = array_merge($_SERVER, [
            'REMOTE_ADDR' => '5.5.5.5',            // not a trusted proxy
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_HOST' => 'example.com',
            'REQUEST_URI' => '/',
        ]);

        $request = new Request([], [], $server, []);

        // Attacker forged X-Forwarded-Proto — must be rejected
        self::assertStringStartsWith('http://', $request->fullUrl());
    }

    public function testSecureReturnsTrueForXForwardedProtoFromTrustedProxy(): void
    {
        $GLOBALS['config']['security']['trusted']['proxies'] = ['10.0.0.1'];

        $server = array_merge($_SERVER, [
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_HOST' => 'example.com',
            'REQUEST_URI' => '/',
        ]);

        $request = new Request([], [], $server, []);

        self::assertStringStartsWith('https://', $request->fullUrl());
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        unset($GLOBALS['config']['security']['trusted']);
    }
}