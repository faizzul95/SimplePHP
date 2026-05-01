<?php

use App\Http\Controllers\PermissionController;
use App\Http\Controllers\RoleController;

// Roles
$router->group(['prefix' => 'roles', 'middleware' => ['permission:rbac-roles-view', 'feature:rbac.role']], function ($router) {
    $router->post('/list', [RoleController::class, 'listRolesDatatable'])->name('roles.list');
    $router->get('/show/{id}', [RoleController::class, 'show'])->name('roles.show');
    $router->post('/save', [RoleController::class, 'save'])->middleware('xss')->permissionAny(['rbac-roles-create', 'rbac-roles-update'])->name('roles.save');
    $router->delete('/delete/{id}', [RoleController::class, 'destroy'])->permission('rbac-roles-delete')->name('roles.delete');
    $router->post('/options', [RoleController::class, 'listSelectOptionRole'])->name('roles.options');
});

// Permissions
$router->group(['prefix' => 'permissions', 'middleware' => ['feature:rbac.permission']], function ($router) {
    $router->post('/list', [PermissionController::class, 'listPermissionDatatable'])->permission('rbac-abilities-view')->name('permissions.list');
    $router->post('/list-assignment', [PermissionController::class, 'listPermissionAssignDatatable'])->permission('rbac-roles-view')->name('permissions.list-assignment');
    $router->get('/show/{id}', [PermissionController::class, 'show'])->permission('rbac-abilities-view')->name('permissions.show');
    $router->post('/save', [PermissionController::class, 'saveAbilities'])->middleware('xss')->permissionAny(['rbac-abilities-create', 'rbac-abilities-update'])->name('permissions.save');
    $router->post('/save-assignment', [PermissionController::class, 'saveAssignment'])->middleware('xss')->permission('rbac-roles-update')->name('permissions.save-assignment');
    $router->delete('/delete/{id}', [PermissionController::class, 'destroy'])->permission('rbac-abilities-delete')->name('permissions.delete');
});
