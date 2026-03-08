<?php

namespace App\Http\Middleware;

use Core\Http\Request;
use Core\Http\Middleware\MiddlewareInterface;
use Middleware\Traits\RateLimitingThrottleTrait;

/**
 * Aggressive rate limiter middleware using RateLimitingThrottleTrait.
 * 
 * Provides IP-based throttling with temporary and permanent blocking.
 * For a simpler Laravel-style rate limiter, use the `throttle` alias (RateLimit middleware).
 *
 * Usage in routes:
 *   ->middleware('aggressive-throttle')
 *
 * Usage in route groups:
 *   $router->group(['middleware' => ['aggressive-throttle']], function ($router) { ... });
 */
class ThrottleRequests implements MiddlewareInterface
{
    use RateLimitingThrottleTrait;

    public function handle(Request $request, callable $next)
    {
        $this->isRateLimiting();
        return $next($request);
    }
}
