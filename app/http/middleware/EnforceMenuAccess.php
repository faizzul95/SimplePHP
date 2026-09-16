<?php

namespace App\Http\Middleware;

use Core\Http\Abort;
use Core\Http\Request;
use Core\Http\Middleware\MiddlewareInterface;

class EnforceMenuAccess implements MiddlewareInterface
{
    private const AUTH_GUARDS = ['session', 'token', 'jwt', 'api_key', 'oauth2', 'basic', 'digest', 'oauth'];

    public function handle(Request $request, callable $next)
    {
        if (!function_exists('menu_manager')) {
            return $next($request);
        }

        $manager = menu_manager();
        $item = $manager->findItemByPath($request->path());

        if ($item === null) {
            return $next($request);
        }

        $requiresAuth = trim((string) ($item['permission'] ?? '')) !== '' || !empty($item['role_ids'] ?? []);
        $isAuthenticated = function_exists('auth') ? auth()->check(self::AUTH_GUARDS) : false;

        if ($requiresAuth && !$isAuthenticated) {
            Abort::unauthenticated($request);
        }

        if (!$manager->canAccessPath($request->path())) {
            Abort::denied(
                $request,
                'Forbidden: Menu route is not accessible in the current state',
                ['state' => (string) ($item['state'] ?? '')]
            );
        }

        return $next($request);
    }
}