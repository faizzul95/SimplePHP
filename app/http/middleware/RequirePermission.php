<?php

namespace App\Http\Middleware;

use Core\Http\Request;
use Core\Http\Response;
use Core\Http\Middleware\MiddlewareInterface;

class RequirePermission implements MiddlewareInterface
{
    private array $permissions = [];

    public function setParameters(array $parameters): void
    {
        $this->permissions = array_values(array_filter(array_map('trim', $parameters), function ($perm) {
            return $perm !== '';
        }));
    }

    public function handle(Request $request, callable $next)
    {
        // Check both session and token authentication
        if (!auth()->check()) {
            if ($request->expectsJson()) {
                Response::json(['code' => 401, 'message' => 'Unauthorized'], 401);
            }

            Response::redirect(url(REDIRECT_LOGIN));
        }

        if (empty($this->permissions)) {
            return $next($request);
        }

        foreach ($this->permissions as $permissionSlug) {
            $hasPermission = false;

            // Check session-based permissions
            if (auth()->checkSession() && function_exists('permission')) {
                $hasPermission = permission($permissionSlug);
            }

            // Check token-based abilities
            if (!$hasPermission && auth()->checkToken()) {
                $hasPermission = auth()->hasAbility($permissionSlug);
            }

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
