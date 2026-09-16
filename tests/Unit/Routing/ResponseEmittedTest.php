<?php

declare(strict_types=1);

namespace Tests\Unit\Routing;

use Core\Http\Middleware\Pipeline;
use Core\Http\Request;
use Core\Http\ResponseEmitted;
use PHPUnit\Framework\TestCase;

/**
 * jsonResponse() used to echo and exit. That skipped every middleware's post-$next()
 * code, skipped the Kernel's finally block, and killed the worker process under
 * FrankenPHP or RoadRunner. It now throws, and the Router converts it back to a
 * return value at the innermost point so the stack unwinds normally.
 */
final class ResponseEmittedTest extends TestCase
{
    protected function setUp(): void
    {
        // The suite bootstrap does not load app/helpers, and jsonResponse() lives there.
        loadHelperFiles();
    }

    private function request(): Request
    {
        return Request::capture();
    }

    /** Middleware that appends to a shared log so ordering can be asserted. */
    private function recordingMiddleware(string $name, \ArrayObject $log): object
    {
        return new class ($name, $log) {
            public function __construct(private string $name, private \ArrayObject $log)
            {
            }

            public function handle(Request $request, callable $next)
            {
                $this->log[] = $this->name . ':before';
                $response = $next($request);
                $this->log[] = $this->name . ':after';

                return $response;
            }
        };
    }

    public function testJsonResponseThrowsInsteadOfExiting(): void
    {
        $this->expectException(ResponseEmitted::class);

        jsonResponse(['code' => 201, 'message' => 'created']);
    }

    public function testThePayloadAndStatusSurviveTheThrow(): void
    {
        try {
            jsonResponse(['code' => 422, 'message' => 'invalid']);
            self::fail('Expected ResponseEmitted.');
        } catch (ResponseEmitted $e) {
            self::assertSame(['code' => 422, 'message' => 'invalid'], $e->payload());
            self::assertSame(422, $e->status());
        }
    }

    public function testAnInvalidStatusFallsBackToBadRequest(): void
    {
        try {
            jsonResponse(['message' => 'x'], 799);
            self::fail('Expected ResponseEmitted.');
        } catch (ResponseEmitted $e) {
            self::assertSame(400, $e->status());
        }
    }

    public function testANonArrayPayloadIsStillCarried(): void
    {
        try {
            jsonResponse('plain string', 200);
            self::fail('Expected ResponseEmitted.');
        } catch (ResponseEmitted $e) {
            self::assertSame(['data' => 'plain string'], $e->payload());
        }
    }

    /**
     * The behaviour the whole change exists for: a controller that emits mid-action
     * must still let every middleware finish its post-$next() work.
     */
    public function testMiddlewareStillUnwindWhenTheControllerEmits(): void
    {
        $log = new \ArrayObject();
        $pipeline = new Pipeline();

        $stack = [
            $this->recordingMiddleware('outer', $log),
            $this->recordingMiddleware('inner', $log),
        ];

        // Mirrors how Router::dispatch() wraps the destination.
        $result = $pipeline->process($this->request(), $stack, static function (Request $request) {
            try {
                jsonResponse(['code' => 200, 'message' => 'ok']);
            } catch (ResponseEmitted $emitted) {
                return $emitted->payload();
            }

            return null;
        });

        self::assertSame(['code' => 200, 'message' => 'ok'], $result);
        self::assertSame(
            ['outer:before', 'inner:before', 'inner:after', 'outer:after'],
            $log->getArrayCopy(),
            'Middleware post-$next() code was skipped — the whole point of this change.'
        );
    }

    public function testRouterCatchesAtBothTheDestinationAndThePipeline(): void
    {
        $router = (string) file_get_contents(
            dirname(__DIR__, 3) . '/systems/Core/Routing/Router.php'
        );

        // Counted regardless of whether the class is written fully-qualified or
        // imported, so tidying the imports cannot silently break the guarantee.
        $catches = preg_match_all(
            '/catch\s*\(\s*\\\\?(?:Core\\\\Http\\\\)?ResponseEmitted\s+\$/',
            $router
        );

        self::assertGreaterThanOrEqual(
            2,
            $catches,
            'At least two catches are required: the inner one lets middleware unwind '
            . 'after a controller emits; the outer one handles a middleware short-circuit '
            . 'such as a rate limit, which throws from outside the destination closure.'
        );
    }

    public function testRouterReturnsTheResponseObjectRatherThanOnlyItsPayload(): void
    {
        $router = (string) file_get_contents(
            dirname(__DIR__, 3) . '/systems/Core/Routing/Router.php'
        );

        // Returning ->payload() discarded the status code and every header for
        // anything that was not a plain JSON array — redirects, HTML, downloads.
        self::assertStringContainsString('$emitted->response()', $router);
        self::assertStringNotContainsString('return $emitted->payload();', $router);
    }

    public function testJsonResponseNoLongerExitsOrEchoes(): void
    {
        $helper = (string) file_get_contents(
            dirname(__DIR__, 3) . '/app/helpers/custom_api_helper.php'
        );

        $body = substr($helper, (int) strpos($helper, 'function jsonResponse'));
        $body = substr($body, 0, (int) strpos($body, "\n    }"));

        self::assertStringNotContainsString('exit;', $body, 'jsonResponse() still terminates the request.');
        self::assertStringNotContainsString('echo ', $body, 'jsonResponse() still writes output directly.');
        self::assertStringContainsString('throw new \Core\Http\ResponseEmitted', $body);
    }
}
