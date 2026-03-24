<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MasterEmailTemplateController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\UploadController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\Api\AuthApiController;
use App\Http\Controllers\Api\UserApiController;

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

$router->post($apiPrefix . '/auth/login', [AuthApiController::class, 'login'])
	->middleware('throttle:auth')
	->middleware('xss')
	->name('api.auth.login');

$router->group(['prefix' => $apiPrefix, 'middleware' => ['auth.api', 'xss']], function ($router) {
	$router->get('/auth/me', [AuthApiController::class, 'me'])->name('api.auth.me');
	$router->post('/auth/logout', [AuthApiController::class, 'logout'])->name('api.auth.logout');
	$router->resource('/users', UserApiController::class);
});

// ─── Application API (Web Front-End) ────────────────────────────────────────

$router->group(['prefix' => $apiPrefix, 'middleware' => ['auth', 'throttle:api']], function ($router) {

	// Auth
	$router->post('/auth/reset-password', [AuthController::class, 'resetPassword'])
		->middleware('throttle:5,1,auth-route')
		->middleware('xss')
		->middleware('permission:user-update')
		->name('auth.reset-password');

	// Dashboard
	$router->post('/dashboard/count-admin', [DashboardController::class, 'countAdminDashboard'])
		->middleware('throttle:30,1,auth-route')
		->middleware('permission:management-view')
		->name('dashboard.count-admin');

	// Users
	$router->group(['prefix' => 'users'], function ($router) {
		$router->post('/list', [UserController::class, 'listUserDatatable'])->middleware('permission:user-view')->name('users.list');
		$router->get('/show/{id}', [UserController::class, 'show'])->middleware('permission:user-view')->name('users.show');
		$router->post('/save', [UserController::class, 'save'])->middleware('xss')->middleware('permission.any:user-create,user-update')->name('users.save');
		$router->delete('/delete/{id}', [UserController::class, 'destroy'])->middleware('permission:user-delete')->name('users.delete');
	});

	// Roles
	$router->group(['prefix' => 'roles', 'middleware' => ['permission:rbac-roles-view']], function ($router) {
		$router->post('/list', [RoleController::class, 'listRolesDatatable'])->name('roles.list');
		$router->get('/show/{id}', [RoleController::class, 'show'])->name('roles.show');
		$router->post('/save', [RoleController::class, 'save'])->middleware('xss')->middleware('permission.any:rbac-roles-create,rbac-roles-update')->name('roles.save');
		$router->delete('/delete/{id}', [RoleController::class, 'destroy'])->middleware('permission:rbac-roles-delete')->name('roles.delete');
		$router->post('/options', [RoleController::class, 'listSelectOptionRole'])->name('roles.options');
	});

	// Permissions
	$router->group(['prefix' => 'permissions'], function ($router) {
		$router->post('/list', [PermissionController::class, 'listPermissionDatatable'])->middleware('permission:rbac-abilities-view')->name('permissions.list');
		$router->post('/list-assignment', [PermissionController::class, 'listPermissionAssignDatatable'])->middleware('permission:rbac-roles-view')->name('permissions.list-assignment');
		$router->get('/show/{id}', [PermissionController::class, 'show'])->middleware('permission:rbac-abilities-view')->name('permissions.show');
		$router->post('/save', [PermissionController::class, 'saveAbilities'])->middleware('xss')->middleware('permission.any:rbac-abilities-create,rbac-abilities-update')->name('permissions.save');
		$router->post('/save-assignment', [PermissionController::class, 'saveAssignment'])->middleware('xss')->middleware('permission:rbac-roles-update')->name('permissions.save-assignment');
		$router->delete('/delete/{id}', [PermissionController::class, 'destroy'])->middleware('permission:rbac-abilities-delete')->name('permissions.delete');
	});

	// Email Templates
	$router->group(['prefix' => 'email-templates', 'middleware' => ['permission:rbac-email-view']], function ($router) {
		$router->post('/list', [MasterEmailTemplateController::class, 'listEmailTemplateDatatable'])->name('email-templates.list');
		$router->get('/show/{id}', [MasterEmailTemplateController::class, 'show'])->name('email-templates.show');
		$router->post('/save', [MasterEmailTemplateController::class, 'save'])->middleware('xss:email_body')->middleware('permission.any:rbac-email-create,rbac-email-update')->name('email-templates.save');
		$router->delete('/delete/{id}', [MasterEmailTemplateController::class, 'destroy'])->middleware('permission:rbac-email-delete')->name('email-templates.delete');
	});

	// Uploads
	$router->group(['prefix' => 'uploads', 'middleware' => ['permission:settings-upload-image']], function ($router) {
		$router->post('/image-cropper', [UploadController::class, 'uploadImageCropper'])->middleware('xss')->name('uploads.image-cropper');
		$router->post('/delete', [UploadController::class, 'removeUploadFiles'])->middleware('xss')->name('uploads.delete');
	});
});
