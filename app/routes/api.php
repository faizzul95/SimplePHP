<?php

use App\Http\Controllers\AuthController;

/** @var \Core\Routing\Router $router */

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Central API/AJAX route registry.
|
| Prefix resolution:
|   - Base prefix comes from `api.versioning.prefix` (default: /api)
|   - When API versioning is enabled, routes are mounted under
|     `/{prefix}/{version}` (default: /api/v1)
|   - When versioning is disabled, routes are mounted under `/{prefix}`
|
| Web views and JavaScript may call these routes via `route('name')`,
| so the configured API prefix/version can change without touching callers.
|
| Two sections:
|   1. External API
|      - Intended for token-based / programmatic access
|      - Uses `auth.api` with methods resolved from `api.auth.methods`
|        and `auth.api_methods` as fallback
|      - Public login route is protected by `throttle:auth` and `xss`
|
|   2. Application API
|      - Used by the web front-end for authenticated AJAX/data requests
|      - Uses `auth` (configured auth resolution order)
|      - Group-level `throttle:api` applies a baseline limiter
|      - Sensitive endpoints may add tighter route-specific throttles
|        such as `throttle:5,1,auth-route`
|
| Security notes:
|   - `xss` is applied on write-heavy endpoints and the external API group
|   - RBAC checks are enforced with `permission:*` middleware
|   - External and internal APIs intentionally use different auth flows
|     to keep browser and machine access concerns separated
|
*/

$apiVersioning = (array) (config('api.versioning') ?? []);
$apiPrefixBase = '/' . trim((string) ($apiVersioning['prefix'] ?? '/api'), '/');

if (($apiVersioning['enabled'] ?? true) === true) {
	$apiVersion = trim((string) ($apiVersioning['current'] ?? 'v1'), '/');
	$apiPrefix = $apiPrefixBase . '/' . ($apiVersion !== '' ? $apiVersion : 'v1');
} else {
	$apiPrefix = $apiPrefixBase;
}

// ─── External Token API ──────────────────────────────────────────────────────

$router->post($apiPrefix . '/auth/login', [AuthController::class, 'loginApi'])
	->middleware('api.public.submit')
	->name('api.auth.login');

$router->group(['prefix' => $apiPrefix, 'middleware' => ['api.external.auth']], function ($router) {
	$router->get('/auth/me', [AuthController::class, 'me'])->name('api.auth.me');
	$router->post('/auth/logout', [AuthController::class, 'logout'])->name('api.auth.logout');
	$router->get('/auth/tokens/current', [AuthController::class, 'currentToken'])->name('api.auth.tokens.current');
	$router->post('/auth/tokens/rotate', [AuthController::class, 'rotateCurrentToken'])->name('api.auth.tokens.rotate');
});

// ─── Application API (Web Front-End) ────────────────────────────────────────

$router->group(['prefix' => $apiPrefix, 'middleware' => ['api.app']], function ($router) {
	require_once __DIR__ . '/API/auth.php';
	require_once __DIR__ . '/API/dashboard.php';
	require_once __DIR__ . '/API/users.php';
	require_once __DIR__ . '/API/rbac_roles_permissions.php';
	require_once __DIR__ . '/API/email_templates.php';
	require_once __DIR__ . '/API/uploads.php';
});
