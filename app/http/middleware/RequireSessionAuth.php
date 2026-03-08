<?php

namespace App\Http\Middleware;

use Core\Http\Request;
use Core\Http\Response;
use Core\Http\Middleware\MiddlewareInterface;

class RequireSessionAuth implements MiddlewareInterface
{
    public function handle(Request $request, callable $next)
    {
        if (!auth()->checkSession()) {
            if ($request->expectsJson()) {
                Response::json(['code' => 401, 'message' => 'Unauthorized'], 401);
            }

            Response::redirect(url(REDIRECT_LOGIN));
        }

        return $next($request);
    }
}
