<?php

declare(strict_types=1);

use App\Http\Middleware\AttachRequestFingerprint;
use Core\Http\Request;
use PHPUnit\Framework\TestCase;

final class AttachRequestFingerprintTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        reset_framework_service();
        reset_event_dispatcher();

        $_GET = [];
        $_POST = [];
        $_FILES = [];
        $_SERVER = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/api/audit/events',
            'SCRIPT_NAME' => '/index.php',
            'HTTP_HOST' => 'localhost',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_USER_AGENT' => 'PHPUnit/Fingerprint',
            'REMOTE_ADDR' => '127.0.0.15',
        ];
    }

    public function testMiddlewareAttachesRequestMetadataAndDispatchesCapturedEvent(): void
    {
        $request = new Request([], ['action' => 'inspect'], $_SERVER, []);
        $middleware = new AttachRequestFingerprint();
        $observed = [];

        on_event('request.captured', function (array $payload) use (&$observed) {
            $observed[] = $payload;
        });

        $response = $middleware->handle($request, function (Request $request) {
            return [
                'code' => 200,
                'fingerprint' => $request->attributes('client_fingerprint'),
                'context' => $request->attributes('security_context'),
            ];
        });

        self::assertIsString($request->attributes('request_id'));
        self::assertStringStartsWith('req_', $request->attributes('request_id'));
        self::assertIsString($request->attributes('trace_id'));
        self::assertStringStartsWith('trace_', $request->attributes('trace_id'));
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $request->attributes('client_fingerprint'));
        self::assertSame($request->attributes('request_id'), $response['request_id']);
        self::assertSame($request->attributes('trace_id'), $response['trace_id']);
        self::assertSame($request->attributes('client_fingerprint'), $response['fingerprint']);
        self::assertSame('request.captured', $response['context']['event']);
        self::assertSame('/api/audit/events', $response['context']['path']);
        self::assertTrue($response['context']['expects_json']);
        self::assertTrue($response['context']['is_api']);
        self::assertCount(1, $observed);
        self::assertSame($request->attributes('request_id'), $observed[0]['request_id']);
    }

    public function testMiddlewarePreservesExistingRequestAndTraceIdentifiers(): void
    {
        $_SERVER['MYTH_REQUEST_ID'] = 'req_existing';
        $_SERVER['MYTH_TRACE_ID'] = 'trace_existing';

        $request = new Request([], [], $_SERVER, []);
        $middleware = new AttachRequestFingerprint();

        $response = $middleware->handle($request, function () {
            return ['code' => 200];
        });

        self::assertSame('req_existing', $request->attributes('request_id'));
        self::assertSame('trace_existing', $request->attributes('trace_id'));
        self::assertSame('req_existing', $response['request_id']);
        self::assertSame('trace_existing', $response['trace_id']);
    }
}