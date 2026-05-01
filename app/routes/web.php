<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MasterEmailTemplateController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\UserController;
use Core\Http\Request;

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
    $router->get('/', [DashboardController::class, 'index'])
        ->webAuth()
        ->permission('management-view')
        ->name('home');

    $router->get('/login', [AuthController::class, 'showLogin'])
        ->guestOnly()
        ->name('login');

    $router->post('/auth/login', [AuthController::class, 'authorize'])
        ->guestOnly()
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
            ->middleware('feature:rbac.role')
            ->permission('rbac-roles-view')
            ->name('rbac.roles');
        $router->get('/rbac/email', [MasterEmailTemplateController::class, 'index'])
            ->middleware('feature:email-template')
            ->permission('rbac-email-view')
            ->name('rbac.email');
    });

    $router->post('/modal/content', function (Request $request): void {
        if (strtolower((string) $request->header('x-requested-with', '')) !== 'xmlhttprequest') {
            http_response_code(403);
            echo modalPartialAlert('Invalid modal request.');
            return;
        }

        $response = renderModalPartial(
            (string) $request->input('fileName', ''),
            $request->input('dataArray', [])
        );

        http_response_code((int) ($response['status'] ?? 500));
        echo (string) ($response['content'] ?? modalPartialAlert('Unable to load modal content.'));
    })->webAuth()->name('modal.content');
});