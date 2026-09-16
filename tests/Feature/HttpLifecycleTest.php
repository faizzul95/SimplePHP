<?php

declare(strict_types=1);

namespace Tests\Feature;

use Core\Http\Abort;
use Core\Http\HtmlResponse;
use Core\Http\JsonResponse;
use Core\Http\RedirectResponse;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\Responsable;
use Core\Http\StreamedResponse;
use Core\Routing\Router;

/**
 * End-to-end coverage of the request lifecycle: routing, the middleware pipeline,
 * content negotiation, error paths and response emission.
 *
 * Every case here is one that a unit test could not have caught, because the bug
 * lives in how the pieces compose rather than in any one of them.
 */
final class HttpLifecycleTest extends FeatureTestCase
{
    protected function middlewareAliases(): array
    {
        return [
            'compress' => \Middleware\CompressResponse::class,
            'fingerprint' => \App\Http\Middleware\AttachRequestFingerprint::class,
        ];
    }

    protected function middlewareGroups(): array
    {
        return [
            'api' => ['fingerprint'],
        ];
    }

    // ─── Routing basics ──────────────────────────────────────────────

    public function testAStaticRouteReachesItsAction(): void
    {
        $response = $this->dispatch(
            $this->request('GET', '/ping'),
            static fn(Router $r) => $r->get('/ping', static fn(): array => ['code' => 200, 'pong' => true])
        );

        $this->assertStatus(200, $response);
        $this->assertJsonSubset(['pong' => true], $response);
    }

    public function testRouteParametersReachTheAction(): void
    {
        $response = $this->dispatch(
            $this->request('GET', '/users/42'),
            static fn(Router $r) => $r->get('/users/{id}', static fn($id): array => ['code' => 200, 'id' => $id])
        );

        $this->assertJsonSubset(['id' => '42'], $response);
    }

    public function testTheRequestObjectIsInjected(): void
    {
        $response = $this->dispatch(
            $this->request('GET', '/echo?q=hello'),
            static fn(Router $r) => $r->get('/echo', static fn(Request $request): array => [
                'code' => 200,
                'q' => $request->query('q'),
            ])
        );

        $this->assertJsonSubset(['q' => 'hello'], $response);
    }

    /**
     * A browser hitting an unknown route is sent to the login page rather than
     * shown a 404 — framework.not_found_redirect.web, which defaults to 'login'.
     */
    public function testAnUnknownWebRouteRedirectsToLogin(): void
    {
        $response = $this->dispatch(
            $this->request('GET', '/nope'),
            static fn(Router $r) => $r->get('/known', static fn(): array => [])
        );

        $this->assertStatus(302, $response);
        self::assertStringContainsString('login', $response->headers()['Location'] ?? '');
    }

    /**
     * The redirect target itself must render the 404 page, not redirect to itself.
     */
    public function testTheRedirectTargetDoesNotLoop(): void
    {
        $response = $this->dispatch(
            $this->request('GET', '/login'),
            static fn(Router $r) => $r->get('/known', static fn(): array => [])
        );

        $this->assertStatus(404, $response, 'An unregistered /login must 404, not redirect to itself.');
    }

    public function testAnUnknownApiRouteReturnsJson(): void
    {
        $response = $this->dispatch(
            $this->request('GET', '/api/v1/nope', headers: ['Accept' => 'application/json']),
            static fn(Router $r) => $r->get('/api/v1/known', static fn(): array => [])
        );

        $this->assertStatus(404, $response);
        self::assertInstanceOf(JsonResponse::class, $response);
        $this->assertJsonSubset(['code' => 404], $response);
    }

    public function testAWrongMethodReturns405WithAnAllowHeader(): void
    {
        $response = $this->dispatch(
            $this->request('POST', '/only-get', headers: ['Accept' => 'application/json']),
            static fn(Router $r) => $r->get('/only-get', static fn(): array => [])
        );

        $this->assertStatus(405, $response);
        $this->assertHeader('Allow', 'GET', $response);
    }

    public function testOptionsReturns204WithTheAllowedMethods(): void
    {
        $response = $this->dispatch(
            $this->request('OPTIONS', '/resource'),
            static function (Router $r): void {
                $r->get('/resource', static fn(): array => []);
                $r->post('/resource', static fn(): array => []);
            }
        );

        $this->assertStatus(204, $response);
        $this->assertHeader('Allow', 'GET, OPTIONS, POST', $response);
    }

    // ─── Return-value normalisation ──────────────────────────────────

    public function testAnArrayCodeKeyBecomesTheHttpStatus(): void
    {
        // The long-standing ['code' => 422, ...] convention must not degrade to 200.
        $response = $this->dispatch(
            $this->request('POST', '/save'),
            static fn(Router $r) => $r->post('/save', static fn(): array => ['code' => 422, 'message' => 'Invalid'])
        );

        $this->assertStatus(422, $response);
    }

    public function testAStringReturnBecomesHtml(): void
    {
        $response = $this->dispatch(
            $this->request('GET', '/page'),
            static fn(Router $r) => $r->get('/page', static fn(): string => '<h1>Hi</h1>')
        );

        $this->assertStatus(200, $response);
        self::assertSame('<h1>Hi</h1>', $this->bodyOf($response));
    }

    public function testAResponsableReturnPassesThrough(): void
    {
        $response = $this->dispatch(
            $this->request('GET', '/download'),
            static fn(Router $r) => $r->get('/download', static fn(): Responsable => new StreamedResponse(
                static fn() => print('chunk'),
                200,
                ['Content-Type' => 'text/csv']
            ))
        );

        self::assertInstanceOf(StreamedResponse::class, $response);
        self::assertSame('chunk', $this->bodyOf($response));
    }

    // ─── Middleware pipeline ─────────────────────────────────────────

    public function testMiddlewareRunsBeforeAndAfterTheAction(): void
    {
        $log = [];

        $response = $this->dispatch(
            $this->request('GET', '/traced'),
            function (Router $r) use (&$log): void {
                $r->aliasMiddleware(['trace' => TracingMiddleware::class]);
                TracingMiddleware::$log = &$log;

                $r->get('/traced', static function () use (&$log): array {
                    $log[] = 'action';

                    return ['code' => 200];
                })->middleware('trace');
            }
        );

        $this->assertStatus(200, $response);
        self::assertSame(['before', 'action', 'after'], $log);
    }

    /**
     * The whole reason ResponseEmitted exists: a short-circuit must still unwind
     * the stack so outer middleware finish their work.
     */
    public function testMiddlewarePostProcessingRunsEvenWhenAnInnerOneAborts(): void
    {
        $log = [];

        $response = $this->dispatch(
            $this->request('GET', '/blocked'),
            function (Router $r) use (&$log): void {
                $r->aliasMiddleware([
                    'trace' => TracingMiddleware::class,
                    'block' => BlockingMiddleware::class,
                ]);
                TracingMiddleware::$log = &$log;

                $r->get('/blocked', static function () use (&$log): array {
                    $log[] = 'action';

                    return ['code' => 200];
                })->middleware('trace')->middleware('block');
            }
        );

        $this->assertStatus(403, $response);
        self::assertSame(
            ['before', 'after'],
            $log,
            'The outer middleware must still run its post-next() half after an inner abort.'
        );
    }

    public function testAnAbortFromMiddlewareNegotiatesContent(): void
    {
        $routes = static function (Router $r): void {
            $r->aliasMiddleware(['block' => BlockingMiddleware::class]);
            $r->get('/blocked', static fn(): array => ['code' => 200])->middleware('block');
        };

        $json = $this->dispatch(
            $this->request('GET', '/blocked', headers: ['Accept' => 'application/json']),
            $routes
        );
        self::assertInstanceOf(JsonResponse::class, $json);
        $this->assertStatus(403, $json);

        $html = $this->dispatch($this->request('GET', '/blocked'), $routes);
        self::assertInstanceOf(HtmlResponse::class, $html);
        $this->assertStatus(403, $html);
    }

    // ─── Regressions that shipped silently ───────────────────────────

    public function testCompressionActuallyCompressesAReturnedResponse(): void
    {
        $body = '<html>' . str_repeat('<p>compress me</p>', 400) . '</html>';

        $response = $this->dispatch(
            $this->request('GET', '/big', headers: ['Accept-Encoding' => 'gzip']),
            static function (Router $r) use ($body): void {
                $r->get('/big', static fn(): Responsable => new HtmlResponse($body, 200, [
                    'Content-Type' => 'text/html',
                ]))->middleware('compress');
            }
        );

        $this->assertHeader('Content-Encoding', 'gzip', $response);
        self::assertSame($body, gzdecode($this->bodyOf($response)));
    }

    public function testTheRequestFingerprintReachesTheJsonBody(): void
    {
        $response = $this->dispatch(
            $this->request('GET', '/api/v1/me', headers: ['Accept' => 'application/json']),
            static function (Router $r): void {
                $r->group(['middleware' => ['api']], static function (Router $r): void {
                    $r->get('/api/v1/me', static fn(): Responsable => new JsonResponse(['code' => 200]));
                });
            }
        );

        $payload = $this->jsonOf($response);

        self::assertArrayHasKey('request_id', $payload);
        self::assertArrayHasKey('trace_id', $payload);
    }

    // ─── Error paths ─────────────────────────────────────────────────

    public function testAThrowingActionBecomesA500(): void
    {
        $response = $this->dispatch(
            $this->request('GET', '/boom', headers: ['Accept' => 'application/json']),
            static fn(Router $r) => $r->get('/boom', static function (): never {
                throw new \RuntimeException('kaboom');
            })
        );

        $this->assertStatus(500, $response);
        $this->assertJsonSubset(['code' => 500], $response);
    }

    public function testTheInternalErrorMessageIsNotLeaked(): void
    {
        $response = $this->dispatch(
            $this->request('GET', '/boom', headers: ['Accept' => 'application/json']),
            static fn(Router $r) => $r->get('/boom', static function (): never {
                throw new \RuntimeException('SELECT * FROM users WHERE secret=1');
            })
        );

        self::assertStringNotContainsString('secret', $this->bodyOf($response));
    }

    public function testARedirectCarriesItsLocation(): void
    {
        $response = $this->dispatch(
            $this->request('GET', '/old'),
            static fn(Router $r) => $r->get('/old', static fn(): Responsable => new RedirectResponse('/new', 301))
        );

        $this->assertStatus(301, $response);
        $this->assertHeader('Location', '/new', $response);
    }

    public function testAnOpenRedirectIsNeutralised(): void
    {
        $response = $this->dispatch(
            $this->request('GET', '/away'),
            static fn(Router $r) => $r->get(
                '/away',
                static fn(): Responsable => new RedirectResponse('https://evil.test/steal')
            )
        );

        $this->assertHeader('Location', '/', $response);
    }

    // ─── Response helpers used from a controller ─────────────────────

    public function testJsonResponseHelperUnwindsThroughThePipeline(): void
    {
        $response = $this->dispatch(
            $this->request('POST', '/create', headers: ['Accept' => 'application/json']),
            static fn(Router $r) => $r->post('/create', static function (): never {
                jsonResponse(['code' => 201, 'id' => 9]);
            })
        );

        $this->assertStatus(201, $response);
        $this->assertJsonSubset(['id' => 9], $response);
    }

    public function testResponseJsonThrowsRatherThanTerminating(): void
    {
        $response = $this->dispatch(
            $this->request('GET', '/legacy', headers: ['Accept' => 'application/json']),
            static fn(Router $r) => $r->get('/legacy', static function (): never {
                Response::json(['code' => 200, 'legacy' => true]);
            })
        );

        $this->assertStatus(200, $response);
        $this->assertJsonSubset(['legacy' => true], $response);
    }
}

final class TracingMiddleware implements \Core\Http\Middleware\MiddlewareInterface
{
    /** @var list<string> */
    public static array $log = [];

    public function handle(Request $request, callable $next)
    {
        self::$log[] = 'before';
        $response = $next($request);
        self::$log[] = 'after';

        return $response;
    }
}

final class BlockingMiddleware implements \Core\Http\Middleware\MiddlewareInterface
{
    public function handle(Request $request, callable $next)
    {
        Abort::denied($request, 'Forbidden: blocked by test middleware');
    }
}
