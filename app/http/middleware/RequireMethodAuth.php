<?php

namespace App\Http\Middleware;

use Core\Http\Abort;
use Core\Http\Request;
use Core\Http\Middleware\MiddlewareInterface;

abstract class RequireMethodAuth implements MiddlewareInterface
{
    protected array $methods = ['token'];
    protected bool $redirectOnFailure = false;
    protected bool $sendBasicChallenge = false;
    protected bool $sendDigestChallenge = false;

    public function handle(Request $request, callable $next)
    {
        if (!auth()->check($this->methods)) {
            $debugEnabled = (bool) config('auth.session_security.debug_log_enabled');
            if ($debugEnabled && function_exists('logger')) {
                try {
                    logger()->log_debug('[AuthDebug] RequireMethodAuth unauthorized | Context: ' . json_encode(auth()->debugAuthState($this->methods), JSON_UNESCAPED_SLASHES));
                } catch (\Throwable $e) {
                    // Never break auth flow when debug logging fails.
                }
            }

            if ($this->sendBasicChallenge) {
                header('WWW-Authenticate: ' . auth()->basicChallengeHeader());
            }

            if ($this->sendDigestChallenge) {
                header('WWW-Authenticate: ' . auth()->digestChallengeHeader());
            }

            Abort::unauthenticated($request, forceJson: !$this->redirectOnFailure);
        }

        // Who the failing request belonged to is the second thing you want after
        // the request id, and it is only knowable once auth has resolved.
        $methods = $this->methods;
        \Core\Support\LogContext::putSafely('user_id', static fn() => auth()->id($methods));

        return $next($request);
    }
}
