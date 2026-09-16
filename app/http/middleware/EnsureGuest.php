<?php

namespace App\Http\Middleware;

use Core\Http\RedirectResponse;
use Core\Http\Abort;
use Core\Http\Request;
use Core\Http\Middleware\MiddlewareInterface;

class EnsureGuest implements MiddlewareInterface
{
    private const AUTH_GUARDS = ['session', 'token', 'jwt', 'api_key', 'oauth2', 'basic', 'digest', 'oauth'];

    public function handle(Request $request, callable $next)
    {
        if (auth()->check(self::AUTH_GUARDS)) {
            if ($request->expectsJson()) {
                Abort::json(['code' => 403, 'message' => 'Already authenticated'], 403);
            }

            // A browser that is already signed in belongs on its landing page,
            // not on a 403 — being logged in is not an error.
            $landingUrl = menu_manager()->resolveAuthenticatedLandingUrl();
            if ($landingUrl !== null) {
                Abort::response(new RedirectResponse($landingUrl));
            }

            Abort::forbidden('You are already signed in.');
        }

        return $next($request);
    }
}
