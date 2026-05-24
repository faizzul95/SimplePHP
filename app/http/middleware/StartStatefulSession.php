<?php

namespace App\Http\Middleware;

use Components\Logger;
use Core\Http\Middleware\MiddlewareInterface;
use Core\Http\Request;

class StartStatefulSession implements MiddlewareInterface
{
    private bool $forceForJsonRequests = false;

    public function setParameters(array $parameters): void
    {
        $normalized = array_map(static fn ($value): string => strtolower(trim((string) $value)), $parameters);
        $this->forceForJsonRequests = in_array('force', $normalized, true);
    }

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

        if ($request->expectsJson() && !$this->forceForJsonRequests && ($configuration['api'] ?? false) !== true) {
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

        if (!$this->startNativeSession() || session_status() !== PHP_SESSION_ACTIVE) {
            $this->reportSessionStartFailure('App\\Http\\Middleware\\StartStatefulSession');
            return;
        }

        if (function_exists('bootstrapRefreshSessionCookie')) {
            bootstrapRefreshSessionCookie();
        }

        if (function_exists('initializeFlashSessionState')) {
            initializeFlashSessionState();
        }
    }

    protected function startNativeSession(): bool
    {
        return session_start();
    }

    protected function reportSessionStartFailure(string $source): void
    {
        $message = sprintf(
            '%s failed to start the session; headers_sent=%s status=%s',
            $source,
            headers_sent() ? 'true' : 'false',
            (string) session_status()
        );

        if (function_exists('logger')) {
            logger()->log_error($message);
            return;
        }

        Logger::instance()->log_error($message);
    }
}