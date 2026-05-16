<?php

namespace App\Http\Middleware;

use Core\Http\Middleware\MiddlewareInterface;
use Core\Http\Request;

class StartStatefulSession implements MiddlewareInterface
{
    public function handle(Request $request, callable $next)
    {
        if ($this->shouldStartSession($request)) {
            $this->startSession();
        }

        return $next($request);
    }

    public function shouldStartSession(Request $request): bool
    {
        $configuration = (array) config('framework.bootstrap.session', []);
        if (($configuration['enabled'] ?? true) !== true) {
            return false;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            return false;
        }

        if (PHP_SAPI === 'cli' && ($configuration['cli'] ?? false) !== true) {
            return false;
        }

        if ($request->expectsJson() && ($configuration['api'] ?? false) !== true) {
            return false;
        }

        return true;
    }

    protected function startSession(): void
    {
        if (headers_sent() || session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        if (function_exists('bootstrapConfigureSessionIni')) {
            bootstrapConfigureSessionIni();
        }

        @session_start();

        if (function_exists('bootstrapRefreshSessionCookie')) {
            bootstrapRefreshSessionCookie();
        }

        if (function_exists('initializeFlashSessionState')) {
            initializeFlashSessionState();
        }
    }
}