<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\MasterEmailTemplateController;
use App\Http\Controllers\UploadController;

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

	// Auth
	$router->post('/auth/reset-password', [AuthController::class, 'resetPassword'])
		->middleware('throttle:5,1,auth-route')
		->middleware('xss')
		->permission('user-update')
		->name('auth.reset-password');
	$router->get('/auth/devices', [AuthController::class, 'devices'])->name('auth.devices');
	$router->delete('/auth/devices/{sessionId}', [AuthController::class, 'revokeDevice'])->name('auth.devices.revoke');
	$router->post('/auth/logout-other-devices', [AuthController::class, 'logoutOtherDevices'])->middleware('xss')->name('auth.logout-other-devices');
	$router->get('/auth/tokens', [AuthController::class, 'tokens'])->name('auth.tokens');

	require_once __DIR__ . '/API/dashboard.php';
	require_once __DIR__ . '/API/users.php';
	require_once __DIR__ . '/API/rbac_roles_permissions.php';

	// Email Templates
	$router->group(['prefix' => 'email-templates', 'middleware' => ['permission:rbac-email-view', 'feature:email-template']], function ($router) {
		$router->post('/list', [MasterEmailTemplateController::class, 'listEmailTemplateDatatable'])->name('email-templates.list');
		$router->get('/show/{id}', [MasterEmailTemplateController::class, 'show'])->name('email-templates.show');
		$router->post('/save', [MasterEmailTemplateController::class, 'save'])->middleware('xss:email_body')->permissionAny(['rbac-email-create', 'rbac-email-update'])->name('email-templates.save');
		$router->delete('/delete/{id}', [MasterEmailTemplateController::class, 'destroy'])->permission('rbac-email-delete')->name('email-templates.delete');
	});

	// Uploads
	$router->group(['prefix' => 'uploads'], function ($router) {
		$router->post('/image-cropper', [UploadController::class, 'uploadImageCropper'])->permission('settings-upload-image')->middleware('api.upload.image')->middleware('xss:image')->name('uploads.image-cropper');
		$router->post('/delete', [UploadController::class, 'removeUploadFiles'])->permission('settings-upload-image')->middleware('api.upload.action')->middleware('xss')->name('uploads.delete');
	});
});
