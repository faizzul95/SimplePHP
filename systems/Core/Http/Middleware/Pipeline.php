<?php

namespace Core\Http\Middleware;

use Core\Http\Request;

class Pipeline
{
    public function process(Request $request, array $middleware, callable $destination)
    {
        $runner = array_reduce(
            array_reverse($middleware),
            function ($next, $pipe) {
                return function (Request $request) use ($pipe, $next) {
                    return $pipe->handle($request, $next);
                };
            },
            $destination
        );

        return $runner($request);
    }
}
