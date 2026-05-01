<?php

use App\Http\Controllers\DashboardController;

$router->post('/dashboard/count-admin', [DashboardController::class, 'countAdminDashboard'])
    ->middleware('throttle:30,1,auth-route')
    ->permission('management-view')
    ->name('dashboard.count-admin');