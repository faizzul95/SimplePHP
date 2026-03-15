<?php

namespace App\Http\Middleware;

use Core\Http\Request;
use Core\Http\Response;
use Core\Http\Middleware\MiddlewareInterface;

class RequireApiToken implements MiddlewareInterface
{
    public function handle(Request $request, callable $next)
    {
        $configuredMethods = config('auth.api_methods') ?? ['token'];
        $methods = is_string($configuredMethods)
            ? array_map('trim', explode(',', $configuredMethods))
            : (array) $configuredMethods;

        $methods = array_values(array_filter($methods, static function ($method) {
            return is_string($method) && trim($method) !== '';
        }));

        if (empty($methods)) {
            $methods = ['token'];
        }

        if (!auth()->check($methods)) {
            $normalized = array_map('strtolower', $methods);

            if (in_array('basic', $normalized, true)) {
                header('WWW-Authenticate: ' . auth()->basicChallengeHeader());
            }

            if (in_array('digest', $normalized, true)) {
                header('WWW-Authenticate: ' . auth()->digestChallengeHeader());
            }

            Response::json(['code' => 401, 'message' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
