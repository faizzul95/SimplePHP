<?php

namespace App\Http\Middleware;

use Core\Http\Abort;
use Core\Http\Request;
use Core\Http\Middleware\MiddlewareInterface;

class RequireRole implements MiddlewareInterface
{
    private array $roles = [];
    private const AUTH_GUARDS = ['session', 'token', 'jwt', 'api_key', 'oauth2', 'basic', 'digest', 'oauth'];

    public function setParameters(array $parameters): void
    {
        $this->roles = array_values(array_filter(array_map('trim', $parameters), static function ($role) {
            return $role !== '';
        }));
    }

    public function handle(Request $request, callable $next)
    {
        if (!auth()->check(self::AUTH_GUARDS)) {
            Abort::unauthenticated($request);
        }

        if (empty($this->roles)) {
            return $next($request);
        }

        if (!auth()->hasAnyRole($this->roles)) {
            Abort::denied($request, 'Forbidden: Missing role', ['roles' => $this->roles]);
        }

        return $next($request);
    }
}
