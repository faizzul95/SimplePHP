<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use Core\Http\Abort;
use Core\Http\BinaryFileResponse;
use Core\Http\Emitter;
use Core\Http\HtmlResponse;
use Core\Http\JsonResponse;
use Core\Http\RedirectResponse;
use Core\Http\Responsable;
use Core\Http\Response;
use Core\Http\ResponseEmitted;
use Core\Http\StreamedResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The framework used to write responses from ~37 places, each ending in exit().
 * That skipped every middleware's post-$next() code, left no single place to apply
 * cross-cutting concerns, and terminated the worker process instead of the request
 * under RoadRunner or FrankenPHP.
 *
 * Everything now describes a Responsable and hands it to Core\Http\Emitter.
 *
 */
final class ResponseEmissionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Emitter::reset();
        Response::resetLinkHeaders();
    }

    protected function tearDown(): void
    {
        Emitter::reset();
        Response::resetLinkHeaders();
        parent::tearDown();
    }

    /** Render a response's body without touching real headers. */
    private function bodyOf(Responsable $response): string
    {
        ob_start();
        $response->emitBody();

        return (string) ob_get_clean();
    }

    // ─── JsonResponse ────────────────────────────────────────────────

    public function testJsonResponseCarriesPayloadStatusAndContentType(): void
    {
        $response = new JsonResponse(['code' => 201, 'id' => 7], 201);

        self::assertSame(201, $response->status());
        self::assertSame(['code' => 201, 'id' => 7], $response->payload());
        self::assertSame('application/json; charset=UTF-8', $response->headers()['Content-Type']);
        self::assertSame('{"code":201,"id":7}', $this->bodyOf($response));
    }

    public function testJsonResponseDoesNotEscapeSlashesOrUnicode(): void
    {
        $response = new JsonResponse(['url' => 'https://a/b', 'name' => 'Ålesund']);

        $body = $this->bodyOf($response);

        self::assertStringContainsString('https://a/b', $body);
        self::assertStringContainsString('Ålesund', $body);
    }

    public function testJsonResponseFallsBackToValidJsonWhenEncodingFails(): void
    {
        $recursive = [];
        $recursive['self'] = &$recursive;

        $body = (new JsonResponse($recursive))->body();

        self::assertJson($body, 'An unencodable payload must still yield parseable JSON.');
        self::assertSame(500, json_decode($body, true)['code']);
    }

    public function testJsonResponseStripsCrlfFromCustomHeaders(): void
    {
        $response = new JsonResponse([], 200, ["X-Evil\r\nInjected" => "value\r\nX-Other: 1"]);

        $headers = $response->headers();

        self::assertArrayHasKey('X-EvilInjected', $headers);
        self::assertSame('valueX-Other: 1', $headers['X-EvilInjected']);
    }

    // ─── Emitter::toResponse ─────────────────────────────────────────

    public function testToResponseMapsNullToNothing(): void
    {
        self::assertNull(Emitter::toResponse(null));
    }

    public function testToResponsePassesResponsableThrough(): void
    {
        $response = new HtmlResponse('hi');

        self::assertSame($response, Emitter::toResponse($response));
    }

    public function testToResponseTakesTheStatusFromAnArrayCodeKey(): void
    {
        // Controllers have always returned ['code' => 422, ...]; that convention
        // has to keep producing a 422 rather than a 200 with an error body.
        $response = Emitter::toResponse(['code' => 422, 'message' => 'nope']);

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(422, $response->status());
    }

    public function testToResponseIgnoresANonsenseCodeKey(): void
    {
        $response = Emitter::toResponse(['code' => 99999, 'message' => 'x']);

        // 500, not 200: an out-of-range code is a bug, and a controller that
        // produced one is far more likely to have failed than succeeded.
        self::assertSame(500, $response->status());
    }

    public function testToResponseMapsAStringToHtml(): void
    {
        $response = Emitter::toResponse('<p>hi</p>');

        self::assertInstanceOf(HtmlResponse::class, $response);
        self::assertSame('<p>hi</p>', $this->bodyOf($response));
    }

    /** @return list<array{0:int,1:int}> */
    public static function statusProvider(): array
    {
        return [
            [200, 200],
            [404, 404],
            [599, 599],
            [100, 100],
            // An out-of-range status is a bug — usually a driver error code or an
            // uninitialised 0. Reporting it as OK makes a client treat the failure as
            // a success, so it clamps to 500.
            [99, 500],
            [600, 500],
            [0, 500],
            [-1, 500],
        ];
    }

    #[DataProvider('statusProvider')]
    public function testNormalizeStatusRejectsValuesOutsideTheHttpRange(int $given, int $expected): void
    {
        self::assertSame($expected, Emitter::normalizeStatus($given));
    }

    // ─── ResponseEmitted ─────────────────────────────────────────────

    public function testResponseEmittedKeepsTheArrayConstructorForBackwardsCompatibility(): void
    {
        $emitted = new ResponseEmitted(['code' => 418, 'message' => 'teapot'], 418);

        self::assertSame(418, $emitted->status());
        self::assertSame(['code' => 418, 'message' => 'teapot'], $emitted->payload());
        self::assertInstanceOf(JsonResponse::class, $emitted->response());
    }

    public function testResponseEmittedExposesNonJsonResponses(): void
    {
        $emitted = new ResponseEmitted(new RedirectResponse('/login', 302));

        self::assertSame(302, $emitted->status());
        self::assertSame([], $emitted->payload(), 'A redirect has no array payload.');
        self::assertInstanceOf(RedirectResponse::class, $emitted->response());
    }

    // ─── Response static helpers ─────────────────────────────────────

    public function testResponseJsonThrowsInsteadOfExiting(): void
    {
        try {
            Response::json(['code' => 401, 'message' => 'Unauthorized'], 401);
            self::fail('Response::json() must not return.');
        } catch (ResponseEmitted $emitted) {
            self::assertSame(401, $emitted->status());
            self::assertSame(['code' => 401, 'message' => 'Unauthorized'], $emitted->payload());
        }
    }

    public function testResponseRedirectThrowsInsteadOfExiting(): void
    {
        try {
            Response::redirect('/dashboard', 303);
            self::fail('Response::redirect() must not return.');
        } catch (ResponseEmitted $emitted) {
            self::assertSame(303, $emitted->status());
            self::assertSame('/dashboard', $emitted->response()->headers()['Location']);
        }
    }

    public function testPendingPreloadHeadersRideAlongOnTheJsonResponse(): void
    {
        Response::preload('/app.css', 'style');
        Response::preload('/app.js', 'script');

        try {
            Response::json(['ok' => true]);
            self::fail('unreachable');
        } catch (ResponseEmitted $emitted) {
            $link = $emitted->response()->headers()['Link'] ?? '';

            self::assertStringContainsString('/app.css', $link);
            self::assertStringContainsString('/app.js', $link);
            self::assertStringContainsString(', ', $link, 'Multiple links fold into one comma-separated header.');
        }
    }

    // ─── Abort ───────────────────────────────────────────────────────

    public function testAbortJsonCarriesStatusAndPayload(): void
    {
        try {
            Abort::json(['code' => 429, 'message' => 'slow down'], 429, ['Retry-After' => '30']);
            self::fail('unreachable');
        } catch (ResponseEmitted $emitted) {
            self::assertSame(429, $emitted->status());
            self::assertSame('30', $emitted->response()->headers()['Retry-After']);
        }
    }

    public function testAbortTextUsesAPlainTextContentType(): void
    {
        try {
            Abort::text('CSRF token mismatch.', 419);
            self::fail('unreachable');
        } catch (ResponseEmitted $emitted) {
            self::assertSame(419, $emitted->status());
            self::assertSame('text/plain; charset=UTF-8', $emitted->response()->headers()['Content-Type']);
            self::assertSame('CSRF token mismatch.', $this->bodyOf($emitted->response()));
        }
    }

    public function testAbortStatusSendsNoBody(): void
    {
        try {
            Abort::status(403);
            self::fail('unreachable');
        } catch (ResponseEmitted $emitted) {
            self::assertSame(403, $emitted->status());
            self::assertSame('0', $emitted->response()->headers()['Content-Length']);
            self::assertSame('', $this->bodyOf($emitted->response()));
        }
    }

    public function testAbortResponsePassesTheResponsableThrough(): void
    {
        $stream = new StreamedResponse(static fn() => print('chunk'), 200);

        try {
            Abort::response($stream);
            self::fail('unreachable');
        } catch (ResponseEmitted $emitted) {
            self::assertSame($stream, $emitted->response());
        }
    }

    // ─── Response objects implement the contract ─────────────────────

    /** @return list<array{0:string}> */
    public static function responsableProvider(): array
    {
        return [
            [HtmlResponse::class],
            [JsonResponse::class],
            [RedirectResponse::class],
            [StreamedResponse::class],
            [BinaryFileResponse::class],
        ];
    }

    #[DataProvider('responsableProvider')]
    public function testEveryResponseTypeImplementsResponsable(string $class): void
    {
        self::assertTrue(
            is_subclass_of($class, Responsable::class),
            $class . ' must be emittable through the single emission point.'
        );
    }

    #[DataProvider('responsableProvider')]
    public function testNoResponseTypeCallsExit(string $class): void
    {
        $file = (new \ReflectionClass($class))->getFileName();
        $source = (string) file_get_contents((string) $file);

        self::assertDoesNotMatchRegularExpression(
            '/\bexit\s*[;(]/',
            $source,
            $class . ' still terminates the process instead of returning a response.'
        );
    }

    public function testStreamedResponseEmitsThroughItsCallback(): void
    {
        $response = new StreamedResponse(static function (): void {
            echo 'a';
            echo 'b';
        }, 200, ['X-Accel-Buffering' => 'no']);

        self::assertSame('ab', $this->bodyOf($response));
        self::assertSame('no', $response->headers()['X-Accel-Buffering']);
    }

    public function testRedirectResponseSanitisesTheLocationHeader(): void
    {
        $response = new RedirectResponse('javascript:alert(1)');

        self::assertSame('/', $response->headers()['Location']);
    }

    public function testRedirectResponseKeepsRelativeTargets(): void
    {
        self::assertSame('/dashboard', (new RedirectResponse('/dashboard'))->headers()['Location']);
    }

    // ─── Emitter send-once ───────────────────────────────────────────

    public function testEmitterSendsOnlyOnce(): void
    {
        $first = new HtmlResponse('first');
        $second = new HtmlResponse('second');

        ob_start();
        Emitter::send($first);
        Emitter::send($second);
        $output = (string) ob_get_clean();

        self::assertSame('first', $output, 'A second emission would corrupt the first response body.');
        self::assertTrue(Emitter::hasSent());
    }

    public function testEmitterResetAllowsANewRequestCycle(): void
    {
        ob_start();
        Emitter::send(new HtmlResponse('one'));
        ob_end_clean();

        Emitter::reset();
        self::assertFalse(Emitter::hasSent());

        ob_start();
        Emitter::send(new HtmlResponse('two'));
        $output = (string) ob_get_clean();

        self::assertSame('two', $output);
    }
}
