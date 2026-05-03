<?php

use App\Http\Controllers\AuthController;

$router->post('/auth/reset-password', [AuthController::class, 'resetPassword'])
    ->middleware('throttle:5,1,auth-route')
    ->middleware('xss')
    ->permission('user-update')
    ->name('auth.reset-password');

$router->get('/auth/devices', [AuthController::class, 'devices'])->name('auth.devices');
$router->delete('/auth/devices/{sessionId}', [AuthController::class, 'revokeDevice'])->name('auth.devices.revoke');
$router->post('/auth/logout-other-devices', [AuthController::class, 'logoutOtherDevices'])->middleware('xss')->name('auth.logout-other-devices');
$router->get('/auth/tokens', [AuthController::class, 'tokens'])->name('auth.tokens');