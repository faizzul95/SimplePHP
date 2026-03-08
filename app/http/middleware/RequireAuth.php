<?php

namespace App\Http\Middleware;

use Core\Http\Request;
use Core\Http\Response;
use Core\Http\Middleware\MiddlewareInterface;

/**
 * Unified auth middleware that supports both session and token authentication.
 *
 * Usage in routes:
 *   'auth'          - Accept either session or token
 *   'auth:session'  - Only accept session auth
 *   'auth:token'    - Only accept token auth
 *   'auth:web'      - Alias for session
 *   'auth:api'      - Alias for token
 */
class RequireAuth implements MiddlewareInterface
{
    private array $guards = [];

    public function setParameters(array $parameters): void
    {
        $this->guards = array_values(array_filter(array_map('trim', $parameters), function ($g) {
            return $g !== '';
        }));
    }

    public function handle(Request $request, callable $next)
    {
        $authenticated = false;

        // Normalize guard names
        $guards = $this->guards;
        if (empty($guards)) {
            // No guard specified: accept either
            $guards = ['session', 'token'];
        }

        $guardMap = [
            'web' => 'session',
            'api' => 'token',
            'session' => 'session',
            'token' => 'token',
        ];

        foreach ($guards as $guard) {
            $resolved = $guardMap[strtolower($guard)] ?? $guard;

            if ($resolved === 'session' && auth()->checkSession()) {
                $authenticated = true;
                break;
            }

            if ($resolved === 'token' && auth()->checkToken()) {
                $authenticated = true;
                break;
            }
        }

        if (!$authenticated) {
            if ($request->expectsJson()) {
                Response::json(['code' => 401, 'message' => 'Unauthorized'], 401);
            }

            Response::redirect(url(REDIRECT_LOGIN));
        }

        return $next($request);
    }
}
