<?php

declare(strict_types=1);

namespace Core\Http\Middleware;

use Core\Http\Request;
use Core\Http\ResponseEmitted;

class Pipeline
{
    /**
     * Run the request through the middleware stack.
     *
     * Each layer is wrapped so that a ResponseEmitted thrown further in is caught
     * at that boundary and handed back as an ordinary return value. Without this,
     * the exception unwinds straight past every outer middleware and their
     * post-$next() code never runs:
     *
     *     Tracing->handle()          // 'before'
     *       Blocking->handle()       // throws
     *     // 'after' never reached — the exception skipped it
     *
     * The Router already caught ResponseEmitted around the *destination*, so an
     * abort from a controller unwound correctly. An abort from a middleware — a
     * rate limit, a failed CSRF check, a permission denial, which is most of them
     * — did not. So compression, response caching, timing headers and request
     * logging were all silently skipped on exactly the responses where they
     * matter most.
     *
     * @param  list<object> $middleware
     */
    public function process(Request $request, array $middleware, callable $destination)
    {
        $runner = array_reduce(
            array_reverse($middleware),
            static function (callable $next, object $pipe): callable {
                return static function (Request $request) use ($pipe, $next) {
                    try {
                        return $pipe->handle($request, $next);
                    } catch (ResponseEmitted $emitted) {
                        // Convert to a return value here, so every layer outside
                        // this one sees a normal response and completes.
                        return $emitted->response();
                    }
                };
            },
            $destination
        );

        return $runner($request);
    }
}
