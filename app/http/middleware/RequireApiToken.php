<?php

namespace App\Http\Middleware;

use Core\Http\Request;
use Core\Http\Response;
use Core\Http\Middleware\MiddlewareInterface;

class RequireApiToken implements MiddlewareInterface
{
    public function handle(Request $request, callable $next)
    {
        if (!auth()->checkToken()) {
            Response::json(['code' => 401, 'message' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
