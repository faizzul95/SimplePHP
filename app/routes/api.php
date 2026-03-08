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
| All data/AJAX endpoints live here under /api/v1/.
| Web views call these via route('name') which resolves transparently.
|
| Two sections:
|   1. External API   — token-only auth (auth.api) for third-party consumers
|   2. Application API — session+token auth (auth) for the web front-end
|
*/

// ─── External Token API ──────────────────────────────────────────────────────

$router->post('/api/v1/auth/login', [AuthApiController::class, 'login'])
	->middleware('throttle:auth')
	->middleware('xss')
	->name('api.auth.login');

$router->group(['prefix' => '/api/v1', 'middleware' => ['auth.api', 'xss']], function ($router) {
	$router->get('/auth/me', [AuthApiController::class, 'me'])->name('api.auth.me');
	$router->post('/auth/logout', [AuthApiController::class, 'logout'])->name('api.auth.logout');
	$router->resource('/users', UserApiController::class);
});

// ─── Application API (Web Front-End) ────────────────────────────────────────

$router->group(['prefix' => '/api/v1', 'middleware' => ['auth']], function ($router) {

	// Auth
	$router->post('/auth/reset-password', [AuthController::class, 'resetPassword'])
		->middleware('xss')
		->name('auth.reset-password');

	// Dashboard
	$router->post('/dashboard/count-admin', [DashboardController::class, 'countAdminDashboard'])
		->name('dashboard.count-admin');

	// Users
	$router->group(['prefix' => 'users'], function ($router) {
		$router->post('/list', [UserController::class, 'listUserDatatable'])->name('users.list');
		$router->get('/show/{id}', [UserController::class, 'show'])->name('users.show');
		$router->post('/save', [UserController::class, 'save'])->middleware('xss')->name('users.save');
		$router->delete('/delete/{id}', [UserController::class, 'destroy'])->name('users.delete');
	});

	// Roles
	$router->group(['prefix' => 'roles'], function ($router) {
		$router->post('/list', [RoleController::class, 'listRolesDatatable'])->name('roles.list');
		$router->get('/show/{id}', [RoleController::class, 'show'])->name('roles.show');
		$router->post('/save', [RoleController::class, 'save'])->middleware('xss')->name('roles.save');
		$router->delete('/delete/{id}', [RoleController::class, 'destroy'])->name('roles.delete');
		$router->post('/options', [RoleController::class, 'listSelectOptionRole'])->name('roles.options');
	});

	// Permissions
	$router->group(['prefix' => 'permissions'], function ($router) {
		$router->post('/list', [PermissionController::class, 'listPermissionDatatable'])->name('permissions.list');
		$router->post('/list-assignment', [PermissionController::class, 'listPermissionAssignDatatable'])->name('permissions.list-assignment');
		$router->get('/show/{id}', [PermissionController::class, 'show'])->name('permissions.show');
		$router->post('/save', [PermissionController::class, 'saveAbilities'])->middleware('xss')->name('permissions.save');
		$router->post('/save-assignment', [PermissionController::class, 'saveAssignment'])->middleware('xss')->name('permissions.save-assignment');
		$router->delete('/delete/{id}', [PermissionController::class, 'destroy'])->name('permissions.delete');
	});

	// Email Templates
	$router->group(['prefix' => 'email-templates'], function ($router) {
		$router->post('/list', [MasterEmailTemplateController::class, 'listEmailTemplateDatatable'])->name('email-templates.list');
		$router->get('/show/{id}', [MasterEmailTemplateController::class, 'show'])->name('email-templates.show');
		$router->post('/save', [MasterEmailTemplateController::class, 'save'])->middleware('xss:email_body')->name('email-templates.save');
		$router->delete('/delete/{id}', [MasterEmailTemplateController::class, 'destroy'])->name('email-templates.delete');
	});

	// Uploads
	$router->group(['prefix' => 'uploads'], function ($router) {
		$router->post('/image-cropper', [UploadController::class, 'uploadImageCropper'])->middleware('xss')->name('uploads.image-cropper');
		$router->post('/delete', [UploadController::class, 'removeUploadFiles'])->middleware('xss')->name('uploads.delete');
	});
});
