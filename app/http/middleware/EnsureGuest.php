<?php

namespace App\Http\Middleware;

use Core\Http\Request;
use Core\Http\Response;
use Core\Http\Middleware\MiddlewareInterface;

class EnsureGuest implements MiddlewareInterface
{
    public function handle(Request $request, callable $next)
    {
        if (auth()->check()) {
            if ($request->expectsJson()) {
                Response::json(['code' => 403, 'message' => 'Already authenticated'], 403);
            }

            Response::redirect(url('dashboard'));
        }

        return $next($request);
    }
}
