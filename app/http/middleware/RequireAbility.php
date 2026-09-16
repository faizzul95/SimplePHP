<?php

namespace App\Http\Middleware;

use Core\Http\Abort;
use Core\Http\Request;
use Core\Http\Middleware\MiddlewareInterface;

class RequireAbility implements MiddlewareInterface
{
    private array $abilities = [];

    public function setParameters(array $parameters): void
    {
        $this->abilities = array_values(array_filter(array_map('trim', $parameters), static function ($ability) {
            return $ability !== '';
        }));
    }

    public function handle(Request $request, callable $next)
    {
        if (!auth()->check(['token', 'api_key', 'jwt', 'oauth2'])) {
            // Token guards only: a redirect would be meaningless to an API client,
            // so JSON is forced regardless of the Accept header.
            Abort::unauthenticated($request, forceJson: true);
        }

        if (empty($this->abilities)) {
            return $next($request);
        }

        foreach ($this->abilities as $ability) {
            if (auth()->hasAbility($ability)) {
                return $next($request);
            }
        }

        Abort::json([
            'code' => 403,
            'message' => 'Forbidden: Missing token ability',
            'abilities' => $this->abilities,
        ], 403);
    }
}
