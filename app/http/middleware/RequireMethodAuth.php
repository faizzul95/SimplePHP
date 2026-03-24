<?php

namespace App\Http\Middleware;

use Core\Http\Request;
use Core\Http\Response;
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

            if ($request->expectsJson() || !$this->redirectOnFailure) {
                Response::json(['code' => 401, 'message' => 'Unauthorized'], 401);
            }

            Response::redirect(url(REDIRECT_LOGIN));
        }

        return $next($request);
    }
}
