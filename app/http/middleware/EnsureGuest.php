<?php

namespace App\Http\Middleware;

use Core\Http\Request;
use Core\Http\Response;
use Core\Http\Middleware\MiddlewareInterface;

class EnsureGuest implements MiddlewareInterface
{
    private const AUTH_GUARDS = ['session', 'token', 'jwt', 'api_key', 'oauth2', 'basic', 'digest', 'oauth'];

    public function handle(Request $request, callable $next)
    {
        if (auth()->check(self::AUTH_GUARDS)) {
            if ($request->expectsJson()) {
                Response::json(['code' => 403, 'message' => 'Already authenticated'], 403);
            }

            $landingUrl = menu_manager()->resolveAuthenticatedLandingUrl();
            if ($landingUrl !== null) {
                Response::redirect($landingUrl);
            }

            show_403();
            exit;
        }

        return $next($request);
    }
}
