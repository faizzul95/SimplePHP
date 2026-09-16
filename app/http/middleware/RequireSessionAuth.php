<?php

namespace App\Http\Middleware;

use Core\Http\Abort;
use Core\Http\Request;
use Core\Http\Middleware\MiddlewareInterface;

class RequireSessionAuth implements MiddlewareInterface
{
    public function handle(Request $request, callable $next)
    {
        if (!auth()->checkSession()) {
            $debugEnabled = (bool) config('auth.session_security.debug_log_enabled');
            if ($debugEnabled && function_exists('logger')) {
                try {
                    logger()->log_debug('[AuthDebug] RequireSessionAuth unauthorized | Context: ' . json_encode(auth()->debugAuthState(['session']), JSON_UNESCAPED_SLASHES));
                } catch (\Throwable $e) {
                    // Never break auth flow when debug logging fails.
                }
            }

            Abort::unauthenticated($request);
        }

        // Who the failing request belonged to is the second thing you want after
        // the request id, and it is only knowable once auth has resolved.
        \Core\Support\LogContext::putSafely('user_id', static fn() => auth()->id(['session']));

        return $next($request);
    }
}
