<?php

namespace App\Http\Middleware;

use Core\Http\Middleware\MiddlewareInterface;
use Core\Http\Request;
use Core\Security\AuditLogger;
use Core\Security\IpBlocklist;

class BlocklistIp implements MiddlewareInterface
{
    protected IpBlocklist $blocklist;

    public function __construct(?IpBlocklist $blocklist = null)
    {
        $this->blocklist = $blocklist ?? new IpBlocklist();
    }

    public function handle(Request $request, callable $next)
    {
        $decision = $this->blocklist->decisionFor($request);
        if ($decision !== null) {
            AuditLogger::log(
                'security.ip_blocked',
                [
                    'ip' => $decision['ip'] ?? $this->blocklist->resolveClientIp($request),
                    'source' => $decision['source'] ?? 'blocklist',
                ],
                'warning',
                blocked: true,
                blockReason: (string) ($decision['reason'] ?? 'IP blocked')
            );

            return $this->reject($request, 403, (string) ($decision['reason'] ?? 'IP blocked'));
        }

        return $next($request);
    }

    protected function reject(Request $request, int $status, string $message)
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Length: 0');
        }

        exit;
    }
}