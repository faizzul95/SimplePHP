<?php

namespace App\Http\Middleware;

use Core\Http\Abort;
use Core\Http\Request;
use Core\Http\Middleware\MiddlewareInterface;

class RequirePermission implements MiddlewareInterface
{
    private array $permissions = [];
    private const AUTH_GUARDS = ['session', 'token', 'jwt', 'api_key', 'oauth2', 'basic', 'digest', 'oauth'];

    public function setParameters(array $parameters): void
    {
        $this->permissions = array_values(array_filter(array_map('trim', $parameters), function ($perm) {
            return $perm !== '';
        }));
    }

    public function handle(Request $request, callable $next)
    {
        // Validate auth using all supported guards (not config-default only)
        // so token/JWT/api_key requests are not rejected when AUTH_METHODS=session.
        if (!auth()->check(self::AUTH_GUARDS)) {
            Abort::unauthenticated($request);
        }

        if (empty($this->permissions)) {
            return $next($request);
        }

        foreach ($this->permissions as $permissionSlug) {
            $hasPermission = auth()->can($permissionSlug);

            if (!$hasPermission) {
                Abort::denied($request, 'Forbidden: Missing permission', ['permission' => $permissionSlug]);
            }
        }

        return $next($request);
    }
}
