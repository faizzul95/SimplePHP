<?php

namespace App\Http\Middleware;

use Core\Http\Abort;
use Core\Http\Request;
use Core\Http\Middleware\MiddlewareInterface;

class RequireAnyPermission implements MiddlewareInterface
{
    private array $permissions = [];
    private const AUTH_GUARDS = ['session', 'token', 'jwt', 'api_key', 'oauth2', 'basic', 'digest', 'oauth'];

    public function setParameters(array $parameters): void
    {
        $this->permissions = array_values(array_filter(array_map('trim', $parameters), static function ($perm) {
            return $perm !== '';
        }));
    }

    public function handle(Request $request, callable $next)
    {
        if (!auth()->check(self::AUTH_GUARDS)) {
            Abort::unauthenticated($request);
        }

        if (empty($this->permissions)) {
            return $next($request);
        }

        $hasPermission = auth()->hasAnyPermission($this->permissions);

        if (!$hasPermission) {
            Abort::denied($request, 'Forbidden: Missing permission', ['permissions' => $this->permissions]);
        }

        return $next($request);
    }
}
