<?php

namespace App\Http\Middleware;

use Core\Http\Abort;
use Core\Http\Request;
use Core\Http\Middleware\MiddlewareInterface;

class RequireApiToken implements MiddlewareInterface
{
    public function handle(Request $request, callable $next)
    {
        $methods = auth()->apiMethods();

        if (!auth()->check($methods)) {
            $debugEnabled = (bool) config('auth.session_security.debug_log_enabled');
            if ($debugEnabled && function_exists('logger')) {
                try {
                    logger()->log_debug('[AuthDebug] RequireApiToken unauthorized | Context: ' . json_encode(auth()->debugAuthState($methods), JSON_UNESCAPED_SLASHES));
                } catch (\Throwable $e) {
                    // Never break auth flow when debug logging fails.
                }
            }

            $normalized = array_map('strtolower', $methods);

            if (in_array('basic', $normalized, true)) {
                header('WWW-Authenticate: ' . auth()->basicChallengeHeader());
            }

            if (in_array('digest', $normalized, true)) {
                header('WWW-Authenticate: ' . auth()->digestChallengeHeader());
            }

            // The external API surface is token-only; a login redirect would break
            // the WWW-Authenticate challenge sent just above.
            Abort::unauthenticated($request, forceJson: true);
        }

        // Who the failing request belonged to is the second thing you want after
        // the request id, and it is only knowable once auth has resolved.
        \Core\Support\LogContext::putSafely('user_id', static fn() => auth()->id($methods));

        return $next($request);
    }
}
