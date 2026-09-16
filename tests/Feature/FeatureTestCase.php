<?php

declare(strict_types=1);

namespace Tests\Feature;

use Core\Http\Emitter;
use Core\Http\JsonResponse;
use Core\Http\Request;
use Core\Http\Responsable;
use Core\Http\Response;
use Core\Http\ResponseEmitted;
use Core\Routing\Router;
use Core\Server\WorkerState;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end harness: builds a real Request, runs it through a real Router with
 * the real middleware stack, and returns the response the kernel would emit.
 *
 * This is the suite the codebase did not have, and its absence is why several
 * regressions shipped silently — compression that stopped compressing, an API log
 * that recorded every status as 200, a fingerprint that stopped reaching response
 * bodies. Each was a *behavioural* change that no unit test asserted on, because
 * no test drove a request end to end.
 *
 * It became possible only after responses stopped calling exit(): a test cannot
 * observe a response that terminates the process.
 */
abstract class FeatureTestCase extends TestCase
{
    /** @var array<string, mixed> */
    private array $serverBackup = [];

    /** @var array<string, mixed> */
    private array $sessionBackup = [];

    /** @var array<string, mixed> */
    private array $configBackup = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->serverBackup = $_SERVER;
        $this->sessionBackup = $_SESSION ?? [];

        /*
        | bootstrapTestFrameworkServices() merges into the global config rather
        | than replacing it, so without a snapshot every feature test inherits
        | whatever the previous one configured — and a merge cannot remove a key,
        | only add to it. A test that sets features as a flat map then silently
        | loses to an earlier test's features.flags, which is a failure that only
        | appears in the full run and never in isolation.
        */
        $this->configBackup = $GLOBALS['config'] ?? [];

        WorkerState::reset();
        Emitter::reset();
        Response::resetLinkHeaders();
        Request::setCurrent(null);

        bootstrapTestFrameworkServices($this->frameworkConfig());
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        $_SESSION = $this->sessionBackup;
        $_GET = [];
        $_POST = [];
        $_COOKIE = [];

        Emitter::reset();
        Response::resetLinkHeaders();
        Request::setCurrent(null);
        reset_framework_service();

        $GLOBALS['config'] = $this->configBackup;

        parent::tearDown();
    }

    /**
     * Config for the application under test.
     *
     * Deliberately minimal and explicit rather than loading app/config: a feature
     * test that depends on the demo application's configuration breaks whenever
     * that configuration changes, which makes it useless as a regression guard.
     *
     * @return array<string, mixed>
     */
    protected function frameworkConfig(): array
    {
        return [
            'cache' => [
                'default' => 'array',
                'stores' => ['array' => ['driver' => 'array']],
                'prefix' => 'feature_',
            ],
            'framework' => [
                'middleware_aliases' => $this->middlewareAliases(),
                'middleware_groups' => $this->middlewareGroups(),
                'middleware_global' => [],
                'error_views' => [
                    '404' => 'app/views/errors/404.php',
                    'general' => 'app/views/errors/general_error.php',
                    'error_image' => 'general/images/nodata/403.png',
                ],
                // Left empty so a 404 renders the view rather than redirecting,
                // which is what the assertions need to see.
                'not_found_redirect' => [],
            ],
        ];
    }

    /** @return array<string, class-string> */
    protected function middlewareAliases(): array
    {
        return [];
    }

    /** @return array<string, list<string>> */
    protected function middlewareGroups(): array
    {
        return [];
    }

    /**
     * Build a Request without touching the real superglobals beyond what the
     * Request constructor reads.
     *
     * @param array<string, mixed>  $query
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     */
    protected function request(
        string $method,
        string $uri,
        array $query = [],
        array $body = [],
        array $headers = []
    ): Request {
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $queryString = (string) (parse_url($uri, PHP_URL_QUERY) ?? '');

        if ($queryString !== '') {
            parse_str($queryString, $parsed);
            $query = array_merge($parsed, $query);
        }

        $server = [
            'REQUEST_METHOD' => strtoupper($method),
            'REQUEST_URI' => $path . ($queryString !== '' ? '?' . $queryString : ''),
            'QUERY_STRING' => $queryString,
            'SCRIPT_NAME' => '/index.php',
            // Same host as BASE_URL: a generated URL on a different host reads as
            // external and is neutralised by the open-redirect guard.
            'HTTP_HOST' => 'example.test',
            'SERVER_PORT' => '443',
            'HTTPS' => 'on',
            'REMOTE_ADDR' => '127.0.0.1',
        ];

        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }

        // Request reads $_GET/$_POST for input(), so mirror them.
        $_GET = $query;
        $_POST = $body;
        $_SERVER = array_merge($_SERVER, $server);

        $request = new Request($query, $body, $server);

        // The kernel populates this in production and anything that negotiates
        // content reads it — a Reply that cannot find a current request answers
        // JSON, so without this every browser assertion below would be testing
        // the fallback rather than the negotiation.
        Request::setCurrent($request);

        return $request;
    }

    /**
     * Dispatch a request through the real router and return the response.
     *
     * @param callable(Router):void $routes
     */
    protected function dispatch(Request $request, callable $routes): Responsable
    {
        $config = (array) config('framework', []);

        $router = new Router();
        $router->aliasMiddleware((array) ($config['middleware_aliases'] ?? []));
        $router->middlewareGroup((array) ($config['middleware_groups'] ?? []));
        $router->globalMiddleware((array) ($config['middleware_global'] ?? []));

        $routes($router);

        try {
            $result = $router->dispatch($request);
        } catch (ResponseEmitted $emitted) {
            return $emitted->response();
        }

        return Emitter::toResponse($result) ?? new JsonResponse([], 204);
    }

    /** Render a response body without emitting headers. */
    protected function bodyOf(Responsable $response): string
    {
        ob_start();
        $response->emitBody();

        return (string) ob_get_clean();
    }

    /** @return array<mixed, mixed> */
    protected function jsonOf(Responsable $response): array
    {
        if ($response instanceof JsonResponse) {
            return $response->payload();
        }

        $decoded = json_decode($this->bodyOf($response), true);

        return is_array($decoded) ? $decoded : [];
    }

    // ─── Assertions ──────────────────────────────────────────────────

    protected function assertStatus(int $expected, Responsable $response, string $message = ''): void
    {
        self::assertSame($expected, $response->status(), $message !== '' ? $message : sprintf(
            'Expected HTTP %d, got %d. Body: %s',
            $expected,
            $response->status(),
            substr($this->bodyOf($response), 0, 200)
        ));
    }

    protected function assertHeader(string $name, string $expected, Responsable $response): void
    {
        $headers = $response->headers();

        self::assertArrayHasKey($name, $headers, "Response is missing the {$name} header.");
        self::assertSame($expected, $headers[$name]);
    }

    protected function assertHeaderPresent(string $name, Responsable $response): void
    {
        self::assertArrayHasKey($name, $response->headers(), "Response is missing the {$name} header.");
    }

    protected function assertHeaderMissing(string $name, Responsable $response): void
    {
        self::assertArrayNotHasKey($name, $response->headers(), "Response should not carry a {$name} header.");
    }

    /** @param array<mixed, mixed> $expected */
    protected function assertJsonSubset(array $expected, Responsable $response): void
    {
        $actual = $this->jsonOf($response);

        foreach ($expected as $key => $value) {
            self::assertArrayHasKey($key, $actual, "JSON body is missing '{$key}'.");
            self::assertSame($value, $actual[$key], "JSON key '{$key}' does not match.");
        }
    }
}
