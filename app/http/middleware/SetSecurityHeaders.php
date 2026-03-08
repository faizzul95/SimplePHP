<?php

namespace App\Http\Middleware;

use Core\Http\Request;
use Core\Http\Middleware\MiddlewareInterface;
use Middleware\Traits\SecurityHeadersTrait;

class SetSecurityHeaders implements MiddlewareInterface
{
    use SecurityHeadersTrait;

    public function handle(Request $request, callable $next)
    {
        $this->set_security_headers();
        return $next($request);
    }
}
