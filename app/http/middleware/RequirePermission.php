<?php

namespace App\Http\Middleware;

use Core\Http\Request;
use Core\Http\Response;
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
            if ($request->expectsJson()) {
                Response::json(['code' => 401, 'message' => 'Unauthorized'], 401);
            }

            Response::redirect(url(REDIRECT_LOGIN));
        }

        if (empty($this->permissions)) {
            return $next($request);
        }

        foreach ($this->permissions as $permissionSlug) {
            $hasPermission = auth()->can($permissionSlug);

            if (!$hasPermission) {
                if ($request->expectsJson()) {
                    Response::json([
                        'code' => 403,
                        'message' => 'Forbidden: Missing permission',
                        'permission' => $permissionSlug,
                    ], 403);
                }

                show_403();
                exit;
            }
        }

        return $next($request);
    }
}
