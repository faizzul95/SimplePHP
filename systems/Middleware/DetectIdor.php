<?php

declare(strict_types=1);

namespace Middleware;

use Core\Http\Request;
use Core\Security\AuditLogger;

/**
 * IDOR (Insecure Direct Object Reference) detection middleware.
 *
 * Detects when an authenticated user attempts to access a resource owned
 * by a different user. Logs the attempt and returns 403.
 *
 * Usage — add to route:
 *   ->middleware('idor:user_id')    // checks route param 'user_id' against auth user
 *   ->middleware('idor:owner_id')   // checks route param 'owner_id'
 *
 * Super-admins (with 'admin.access.any' permission) are allowed to bypass.
 *
 */
final class DetectIdor
{
    /**
     * @param Request  $request
     * @param \Closure $next
     * @param string   $ownerParam  Name of the route parameter that holds the resource owner's user ID
     */
    public function handle(Request $request, \Closure $next, string $ownerParam = 'user_id'): mixed
    {
        $authUserId = $_SESSION['user_id'] ?? null;

        // Not authenticated — let the auth middleware handle it
        if ($authUserId === null) {
            return $next($request);
        }

        $routeParam = (int) ($request->route($ownerParam) ?? 0);

        // No owner param in route — nothing to check
        if ($routeParam === 0) {
            return $next($request);
        }

        // Allow if IDs match (user accessing their own resource)
        if ($routeParam === (int) $authUserId) {
            return $next($request);
        }

        // Allow super-admins to access any resource
        if (function_exists('can') && can('admin.access.any')) {
            return $next($request);
        }

        // IDOR detected — log and block
        AuditLogger::idor(
            userId:     (int) $authUserId,
            resource:   $request->path(),
            resourceId: $routeParam,
            ownerId:    $routeParam
        );

        if (function_exists('abort')) {
            abort(403, 'Access denied.');
        }

        http_response_code(403);
        echo json_encode(['error' => 'Access denied.']);
        exit;
    }
}
