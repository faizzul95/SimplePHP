<?php

namespace App\Http\Middleware;

use Core\Http\Request;
use Core\Http\Response;
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

            Response::json(['code' => 401, 'message' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
