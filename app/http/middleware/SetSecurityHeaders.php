<?php

namespace App\Http\Middleware;

use Core\Http\Request;
use Core\Http\Middleware\MiddlewareInterface;
use Core\Http\WithHeaders;
use Middleware\Traits\SecurityHeadersTrait;

class SetSecurityHeaders implements MiddlewareInterface
{
    use SecurityHeadersTrait;

    public function handle(Request $request, callable $next)
    {
        /*
        | Attached to the response, not sent with header().
        |
        | A raw header() call lives outside the response object, so the response
        | cache stored bodies with no security headers on them, a test could not
        | observe them, and a worker SAPI building its own response never saw
        | them. Every other middleware moved onto the response object; this was
        | the last one that had not.
        |
        | set_security_headers() still runs so that anything writing directly to
        | the output stream — an error page rendered before the response object
        | exists — is still covered.
        */
        $this->set_security_headers();

        return WithHeaders::attach($next($request), $this->securityHeaderMap());
    }
}
