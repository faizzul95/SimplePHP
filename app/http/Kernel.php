<?php

namespace App\Http;

use Core\Diagnostics\MemoryProfiler;
use Core\Http\Emitter;
use Core\Http\JsonResponse;
use Core\Http\Request;
use Core\Http\Responsable;
use Core\Http\ResponseEmitted;
use Core\Routing\Router;

/**
 * The HTTP kernel.
 *
 * This is the only place a response is written. Routes, controllers and
 * middleware describe a response — by returning a Responsable, an array or a
 * string, or by throwing ResponseEmitted — and the kernel emits it exactly once
 * through Core\Http\Emitter.
 */
class Kernel
{
    private array $frameworkConfig;

    public function __construct()
    {
        $this->frameworkConfig = config('framework') ?? [];
    }

    public function handle(Request $request): void
    {
        $profileHandle = MemoryProfiler::begin($request);

        try {
            $response = $this->resolve($request);

            if ($response !== null) {
                $this->emit($request, $response);
            }
        } finally {
            MemoryProfiler::end($profileHandle, $request);
        }
    }

    /**
     * Run the request through routing and middleware, normalising whatever comes
     * back — including a short-circuit thrown from outside the router — into a
     * Responsable.
     */
    private function resolve(Request $request): ?Responsable
    {
        try {
            $router = new Router();
            $router->aliasMiddleware((array) ($this->frameworkConfig['middleware_aliases'] ?? []));
            $router->middlewareGroup((array) ($this->frameworkConfig['middleware_groups'] ?? []));
            $router->globalMiddleware((array) ($this->frameworkConfig['middleware_global'] ?? []));

            $routeProvider = framework_service('route.provider');
            $routeProvider->map($request, $router);

            return Emitter::toResponse($router->dispatch($request));
        } catch (ResponseEmitted $emitted) {
            // Thrown by the router's own 404/405 handling, or by anything that ran
            // before the middleware pipeline was entered.
            return $emitted->response();
        }
    }

    /**
     * Write the response.
     *
     * HEAD is answered with the headers of the equivalent GET and no body, which
     * is what RFC 9110 requires and what the router's HEAD-to-GET folding assumed
     * but never enforced.
     */
    private function emit(Request $request, Responsable $response): void
    {
        if (strtoupper($request->method()) === 'HEAD') {
            Emitter::sendHeaders($response);
            return;
        }

        Emitter::send($response);
    }

    /**
     * Build the response for an unhandled throwable.
     *
     * Exposed so the front controllers can render a consistent error without
     * duplicating content negotiation.
     */
    public static function errorResponse(Request $request, int $status, string $message): Responsable
    {
        return new JsonResponse(['code' => $status, 'message' => $message], $status);
    }
}
