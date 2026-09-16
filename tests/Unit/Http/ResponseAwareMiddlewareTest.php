<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Middleware\AttachRequestFingerprint;
use App\Http\Middleware\ApiRequestLogger;
use Core\Http\HtmlResponse;
use Core\Http\JsonResponse;
use Core\Http\RedirectResponse;
use Core\Http\Request;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Middleware that inspects what the route returned.
 *
 * Both of these only understood plain arrays. Once controllers started returning
 * JsonResponse objects they kept working — silently doing nothing useful. The
 * fingerprint stopped reaching API bodies, and every log line recorded HTTP 200
 * regardless of the real status, which is precisely the case the log exists for.
 */
final class ResponseAwareMiddlewareTest extends TestCase
{
    private function request(string $path = '/api/v1/users'): Request
    {
        return new Request([], [], [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => $path,
            'SCRIPT_NAME' => '/index.php',
            'HTTP_HOST' => 'localhost',
            'HTTP_ACCEPT' => 'application/json',
        ]);
    }

    // ─── AttachRequestFingerprint ────────────────────────────────────

    public function testTheFingerprintReachesAJsonResponseBody(): void
    {
        $response = (new AttachRequestFingerprint())->handle(
            $this->request(),
            static fn(): JsonResponse => new JsonResponse(['code' => 200, 'data' => []])
        );

        self::assertInstanceOf(JsonResponse::class, $response);

        $payload = $response->payload();
        self::assertArrayHasKey('request_id', $payload);
        self::assertArrayHasKey('trace_id', $payload);
        self::assertNotSame('', $payload['request_id']);
    }

    public function testTheStatusAndHeadersSurviveStamping(): void
    {
        $response = (new AttachRequestFingerprint())->handle(
            $this->request(),
            static fn(): JsonResponse => new JsonResponse(['code' => 422], 422, ['X-Custom' => 'kept'])
        );

        self::assertSame(422, $response->status());
        self::assertSame('kept', $response->headers()['X-Custom']);
    }

    public function testAnArrayResponseIsStillStamped(): void
    {
        $response = (new AttachRequestFingerprint())->handle(
            $this->request(),
            static fn(): array => ['code' => 200]
        );

        self::assertIsArray($response);
        self::assertArrayHasKey('request_id', $response);
    }

    public function testAControllerSuppliedCorrelationIdIsNotOverwritten(): void
    {
        $response = (new AttachRequestFingerprint())->handle(
            $this->request(),
            static fn(): JsonResponse => new JsonResponse(['request_id' => 'caller-supplied'])
        );

        self::assertSame('caller-supplied', $response->payload()['request_id']);
    }

    public function testNonJsonResponsesPassThroughUnchanged(): void
    {
        $redirect = new RedirectResponse('/dashboard');

        $response = (new AttachRequestFingerprint())->handle(
            $this->request('/dashboard'),
            static fn(): RedirectResponse => $redirect
        );

        self::assertSame($redirect, $response, 'A redirect carries its ids in headers, not a body.');
    }

    // ─── ApiRequestLogger status resolution ──────────────────────────

    /** @return array{0:int,1:string} */
    private function describe(mixed $response): array
    {
        $method = new ReflectionMethod(ApiRequestLogger::class, 'describeResponse');
        $method->setAccessible(true);

        return $method->invoke(new ApiRequestLogger(), $response);
    }

    public function testTheLoggedStatusComesFromTheResponseNotTheGlobal(): void
    {
        // http_response_code() is read before the response is emitted, so it is
        // still 200 here. The response object is the only source of truth.
        [$status] = $this->describe(new JsonResponse(['message' => 'Unauthorized'], 401));

        self::assertSame(401, $status, 'A 401 logged as 200 makes the log useless for error monitoring.');
    }

    public function testAnEnvelopeCodeOverridesTheHttpStatus(): void
    {
        // A 200 response whose body says code 422 is a failure.
        [$status] = $this->describe(new JsonResponse(['code' => 422, 'message' => 'Invalid'], 200));

        self::assertSame(422, $status);
    }

    public function testTheStatusIsReadFromAnyResponsable(): void
    {
        [$status, $summary] = $this->describe(new HtmlResponse('<p>gone</p>', 410));

        self::assertSame(410, $status);
        self::assertSame(HtmlResponse::class, $summary);
    }

    public function testAnArrayResponseStillResolvesItsCode(): void
    {
        [$status] = $this->describe(['code' => 500, 'message' => 'boom']);

        self::assertSame(500, $status);
    }

    public function testOnlyTheEnvelopeIsSummarised(): void
    {
        // The log must not persist row data outside the database.
        [, $summary] = $this->describe(new JsonResponse([
            'code' => 200,
            'message' => 'OK',
            'data' => ['email' => 'private@example.test'],
        ]));

        self::assertStringNotContainsString('private@example.test', $summary);
        self::assertStringContainsString('"code":200', $summary);
    }
}
