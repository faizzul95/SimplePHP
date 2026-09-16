<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CspReportController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MasterEmailTemplateController;
use App\Http\Controllers\ModalController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\UserController;

/** @var \Core\Routing\Router $router */

/*
|--------------------------------------------------------------------------
| Web Routes — Page Rendering Only
|--------------------------------------------------------------------------
|
| These routes serve HTML views. All data operations (AJAX/JSON) are
| handled by API routes in api.php under the /api/v1/ prefix.
|
*/

$router->group(['middleware' => ['web']], function ($router) {
    $router->post('/_myth/csp-report', [CspReportController::class, 'store'])
        ->name('csp.report');

    $router->get('/', [DashboardController::class, 'index'])
        ->webAuth()
        ->permission('management-view')
        ->name('home');

    $router->get('/login', [AuthController::class, 'showLogin'])
        ->guestOnly()
        ->name('login');

    $router->post('/auth/login', [AuthController::class, 'authorize'])
        ->guestOnly()
        ->middleware('timing.normalize:200')
        ->middleware('xss')
        ->name('auth.login');

    $router->post('/auth/logout', [AuthController::class, 'logout'])
        ->webAuth()
        ->name('auth.logout');

    $router->group(['middleware' => ['auth.web']], function ($router) {
        $router->get('/dashboard', [DashboardController::class, 'index'])
            ->permission('management-view')
            ->name('dashboard');
        $router->get('/directory', [UserController::class, 'index'])
            ->permission('user-view')
            ->name('directory');
        $router->get('/rbac/roles', [RoleController::class, 'index'])
            ->featureFlag('rbac.role')
            ->permission('rbac-roles-view')
            ->name('rbac.roles');
        $router->get('/rbac/email', [MasterEmailTemplateController::class, 'index'])
            ->featureFlag('email-template')
            ->permission('rbac-email-view')
            ->name('rbac.email');
    });

    // A controller method, not a closure: route:cache cannot serialise closures,
    // so a closure route disappears from the cached index after `myth deploy`.
    $router->post('/modal/content', [ModalController::class, 'content'])
        ->webAuth()
        ->name('modal.content');
});