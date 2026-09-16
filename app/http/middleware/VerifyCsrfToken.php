<?php

namespace App\Http\Middleware;

use Core\Http\Abort;
use Core\Http\Middleware\MiddlewareInterface;
use Core\Http\Request;

class VerifyCsrfToken implements MiddlewareInterface
{
    private bool $forceValidation = false;

    private bool $statefulOnly = false;

    public function setParameters(array $parameters): void
    {
        $mode = strtolower(trim((string) ($parameters[0] ?? '')));
        $this->forceValidation = ($mode === 'force');
        $this->statefulOnly = ($mode === 'stateful');
    }

    public function handle(Request $request, callable $next)
    {
        if ($this->statefulOnly && $this->authenticatedWithoutCookie()) {
            return $next($request);
        }

        $csrf = csrf();
        $currentToken = $csrf->getToken() ?: $csrf->init();
        $this->sendTokenHeader($currentToken);

        if ($csrf->validate($request->path(), $this->forceValidation)) {
            return $next($request);
        }

        $freshToken = $csrf->regenerate();
        $this->sendTokenHeader($freshToken);

        Abort::problem($request, 419, 'CSRF token mismatch.', ['csrf_token' => $freshToken]);
    }

    /**
     * CSRF exists because a browser attaches cookies to cross-site requests on
     * its own. A bearer token has to be read out of storage and set as a header
     * by the caller, so a request that got in on one carries no CSRF risk.
     *
     * The test is a *successful* stateless authentication, not the presence of an
     * Authorization header — otherwise attaching a junk bearer alongside the
     * victim's cookie would turn the skip into a bypass. Only the methods the app
     * actually enabled are probed, and the token lookup is memoised by Auth, so
     * this costs nothing on top of the auth middleware that just ran.
     */
    private function authenticatedWithoutCookie(): bool
    {
        $methods = array_values(array_diff(auth()->apiMethods(), ['session']));

        return $methods !== [] && auth()->check($methods);
    }

    private function sendTokenHeader(string $token): void
    {
        if ($token === '' || headers_sent()) {
            return;
        }

        header('X-CSRF-TOKEN: ' . $token);
    }
}
