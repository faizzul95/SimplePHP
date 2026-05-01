<?php

use App\Http\Controllers\UserController;

$router->group(['prefix' => 'users'], function ($router) {
    $router->post('/list', [UserController::class, 'listUserDatatable'])->permission('user-view')->name('users.list');
    $router->get('/show/{id}', [UserController::class, 'show'])->permission('user-view')->name('users.show');
    $router->post('/save', [UserController::class, 'save'])->middleware('xss')->permissionAny(['user-create', 'user-update'])->name('users.save');
    $router->delete('/delete/{id}', [UserController::class, 'destroy'])->permission('user-delete')->name('users.delete');
});
