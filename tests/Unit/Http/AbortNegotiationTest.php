<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use Core\Http\Abort;
use Core\Http\HtmlResponse;
use Core\Http\JsonResponse;
use Core\Http\RedirectResponse;
use Core\Http\Request;
use Core\Http\ResponseEmitted;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Twenty-one middleware each hand-rolled the same negotiation:
 *
 *     if ($request->expectsJson()) {
 *         Response::json(['code' => $status, 'message' => $message], $status);
 *     }
 *     Abort::text($message, $status);
 *
 * Three variants that differed only in what the non-JSON branch produced. The
 * duplication let the JSON payload shape drift between middleware, so a client
 * could not rely on it.
 */
final class AbortNegotiationTest extends TestCase
{
    private function request(bool $wantsJson, string $path = '/dashboard'): Request
    {
        $server = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => $path,
            'SCRIPT_NAME' => '/index.php',
            'HTTP_HOST' => 'localhost',
        ];

        if ($wantsJson) {
            $server['HTTP_ACCEPT'] = 'application/json';
        }

        return new Request([], [], $server);
    }

    /** @return array{0:int,1:array<string,mixed>,2:array<string,string>,3:object} */
    private function capture(callable $abort): array
    {
        try {
            $abort();
            self::fail('An Abort helper must never return.');
        } catch (ResponseEmitted $emitted) {
            $response = $emitted->response();

            return [
                $emitted->status(),
                $emitted->payload(),
                $response->headers(),
                $response,
            ];
        }
    }

    // ─── problem(): JSON or plain text ───────────────────────────────

    public function testProblemReturnsJsonForApiClients(): void
    {
        [$status, $payload, $headers] = $this->capture(
            fn() => Abort::problem($this->request(true), 400, 'Potentially unsafe content detected')
        );

        self::assertSame(400, $status);
        self::assertSame(['code' => 400, 'message' => 'Potentially unsafe content detected'], $payload);
        self::assertSame('application/json; charset=UTF-8', $headers['Content-Type']);
    }

    public function testProblemReturnsPlainTextForBrowsers(): void
    {
        [$status, , $headers, $response] = $this->capture(
            fn() => Abort::problem($this->request(false), 400, 'Potentially unsafe content detected')
        );

        self::assertSame(400, $status);
        self::assertInstanceOf(HtmlResponse::class, $response);
        self::assertSame('text/plain; charset=UTF-8', $headers['Content-Type']);
    }

    public function testProblemMergesExtraFieldsIntoTheJsonBody(): void
    {
        [, $payload] = $this->capture(
            fn() => Abort::problem($this->request(true), 429, 'Too many requests', ['retry_after' => 30])
        );

        self::assertSame(30, $payload['retry_after']);
        self::assertSame(429, $payload['code']);
    }

    public function testProblemSendsHeadersOnBothBranches(): void
    {
        foreach ([true, false] as $wantsJson) {
            [, , $headers] = $this->capture(
                fn() => Abort::problem($this->request($wantsJson), 429, 'Too many requests', [], ['Retry-After' => '30'])
            );

            self::assertSame('30', $headers['Retry-After'], 'Retry-After must reach the client either way.');
        }
    }

    /** An unparseable status is a bug; answering OK would report it as a success. */
    public function testProblemClampsAnOutOfRangeStatus(): void
    {
        [$status] = $this->capture(fn() => Abort::problem($this->request(true), 9999, 'nonsense'));

        self::assertSame(500, $status);
    }

    // ─── denied(): JSON or the 403 page ──────────────────────────────

    public function testDeniedReturnsJsonForApiClients(): void
    {
        [$status, $payload] = $this->capture(
            fn() => Abort::denied($this->request(true), 'Forbidden: Missing permission', ['permission' => 'user-view'])
        );

        self::assertSame(403, $status);
        self::assertSame('Forbidden: Missing permission', $payload['message']);
        self::assertSame('user-view', $payload['permission']);
    }

    public function testDeniedRendersThe403PageForBrowsers(): void
    {
        [$status, , , $response] = $this->capture(
            fn() => Abort::denied($this->request(false), 'Forbidden: Missing permission')
        );

        self::assertSame(403, $status);
        self::assertInstanceOf(HtmlResponse::class, $response);
    }

    public function testDeniedAlwaysUses403(): void
    {
        // The status is not a parameter: "authenticated but not allowed" is 403,
        // and letting each middleware pick was how 401/403 got mixed up.
        [$status] = $this->capture(fn() => Abort::denied($this->request(true)));

        self::assertSame(403, $status);
    }

    // ─── unauthenticated(): JSON or redirect to login ────────────────

    public function testUnauthenticatedReturnsJsonForApiClients(): void
    {
        [$status, $payload] = $this->capture(
            fn() => Abort::unauthenticated($this->request(true))
        );

        self::assertSame(401, $status);
        self::assertSame(['code' => 401, 'message' => 'Unauthorized'], $payload);
    }

    public function testUnauthenticatedRedirectsBrowsersToLogin(): void
    {
        [$status, , $headers, $response] = $this->capture(
            fn() => Abort::unauthenticated($this->request(false))
        );

        self::assertSame(302, $status);
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertArrayHasKey('Location', $headers);
    }

    /**
     * Basic and Digest send a WWW-Authenticate challenge; a 302 would swallow it
     * and the client would never be prompted for credentials.
     */
    public function testForceJsonOverridesTheRedirectForChallengeGuards(): void
    {
        [$status, $payload, , $response] = $this->capture(
            fn() => Abort::unauthenticated($this->request(false), forceJson: true)
        );

        self::assertSame(401, $status);
        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame('Unauthorized', $payload['message']);
    }

    public function testAnExplicitRedirectTargetIsHonoured(): void
    {
        [, , $headers] = $this->capture(
            fn() => Abort::unauthenticated($this->request(false), redirectTo: '/sign-in')
        );

        self::assertSame('/sign-in', $headers['Location']);
    }

    public function testUnauthenticatedCarriesHeadersOnBothBranches(): void
    {
        foreach ([true, false] as $wantsJson) {
            [, , $headers] = $this->capture(
                fn() => Abort::unauthenticated(
                    $this->request($wantsJson),
                    headers: ['WWW-Authenticate' => 'Basic realm="MythPHP"']
                )
            );

            self::assertSame('Basic realm="MythPHP"', $headers['WWW-Authenticate']);
        }
    }

    // ─── The middleware actually use them ────────────────────────────

    /** @return list<array{0:string}> */
    public static function middlewareProvider(): array
    {
        $files = glob(dirname(__DIR__, 3) . '/app/http/middleware/*.php') ?: [];

        return array_map(static fn(string $path): array => [$path], $files);
    }

    #[DataProvider('middlewareProvider')]
    public function testNoMiddlewareStillHandRollsTheNegotiation(string $path): void
    {
        $source = (string) file_get_contents($path);

        self::assertStringNotContainsString(
            'Response::json(',
            $source,
            basename($path) . ' still emits JSON directly instead of going through Abort.'
        );
    }

    #[DataProvider('middlewareProvider')]
    public function testNoMiddlewareCallsExit(string $path): void
    {
        $source = (string) file_get_contents($path);

        self::assertDoesNotMatchRegularExpression(
            '/\bexit\s*[;(]/',
            $source,
            basename($path) . ' terminates the process instead of throwing.'
        );
    }

    #[DataProvider('middlewareProvider')]
    public function testNoMiddlewareWritesTheStatusCodeItself(string $path): void
    {
        $source = (string) file_get_contents($path);

        // Reading it — http_response_code() with no argument — is fine; that is
        // how ApiRequestLogger records what was actually sent. Setting it bypasses
        // the emitter and makes the final status depend on call order.
        self::assertDoesNotMatchRegularExpression(
            '/http_response_code\s*\(\s*[^)\s]/',
            $source,
            basename($path) . ' sets the status outside the single emission point.'
        );
    }

    #[DataProvider('middlewareProvider')]
    public function testNoMiddlewareEchoesAResponseBody(string $path): void
    {
        $source = (string) file_get_contents($path);

        self::assertDoesNotMatchRegularExpression(
            '/^\s*echo\s/m',
            $source,
            basename($path) . ' writes to the output stream directly instead of returning a response.'
        );
    }
}
