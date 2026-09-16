<?php

namespace App\Http\Middleware;

use Core\Http\Abort;
use Core\Http\Request;
use Core\Http\Middleware\MiddlewareInterface;

/**
 * Unified auth middleware that supports both session and token authentication.
 *
 * Usage in routes:
 *   'auth'          - Accept either session or token
 *   'auth:session'  - Only accept session auth
 *   'auth:token'    - Only accept token auth
 *   'auth:web'      - Alias for session
 *   'auth:api'      - Alias for token
 */
class RequireAuth implements MiddlewareInterface
{
    private array $guards = [];

    public function setParameters(array $parameters): void
    {
        $this->guards = array_values(array_filter(array_map('trim', $parameters), function ($g) {
            return $g !== '';
        }));
    }

    public function handle(Request $request, callable $next)
    {
        $guards = $this->guards;
        if (empty($guards)) {
            $guards = (array) config('auth.methods', ['session']);
        }

        if (!auth()->check($guards)) {
            $debugEnabled = (bool) config('auth.session_security.debug_log_enabled');
            if ($debugEnabled && function_exists('logger')) {
                try {
                    logger()->log_debug('[AuthDebug] RequireAuth unauthorized | Context: ' . json_encode(auth()->debugAuthState($guards), JSON_UNESCAPED_SLASHES));
                } catch (\Throwable $e) {
                    // Never break auth flow when debug logging fails.
                }
            }

            $normalizedGuards = array_map('strtolower', $guards);

            if (in_array('basic', $normalizedGuards, true)) {
                header('WWW-Authenticate: ' . auth()->basicChallengeHeader());
            }

            if (in_array('digest', $normalizedGuards, true)) {
                header('WWW-Authenticate: ' . auth()->digestChallengeHeader());
            }

            // Basic and Digest send a WWW-Authenticate challenge above; redirecting
            // would swallow it, so those guards always answer with JSON.
            $hasChallengeGuard = !empty(array_intersect($normalizedGuards, ['basic', 'digest']));

            Abort::unauthenticated($request, forceJson: $hasChallengeGuard);
        }

        // Who the failing request belonged to is the second thing you want after
        // the request id, and it is only knowable once auth has resolved.
        \Core\Support\LogContext::putSafely('user_id', static fn() => auth()->id($guards));

        return $next($request);
    }
}
