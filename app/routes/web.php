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

$router->get('/', [DashboardController::class, 'index'])
    ->middleware('web')
    ->middleware('auth.web')
    ->middleware('permission:management-view')
    ->name('home');

$router->get('/login', [AuthController::class, 'showLogin'])
    ->middleware('web')
    ->middleware('guest')
    ->name('login');

$router->post('/auth/login', [AuthController::class, 'authorize'])
    ->middleware('web')
    ->middleware('guest')
    ->middleware('xss')
    ->name('auth.login');

$router->post('/auth/logout', [AuthController::class, 'logout'])
    ->middleware('web')
    ->middleware('auth.web')
    ->name('auth.logout');

$router->group(['middleware' => ['web', 'auth.web']], function ($router) {

    $router->get('/dashboard', [DashboardController::class, 'index'])
        ->middleware('permission:management-view')
        ->name('dashboard');
    $router->get('/directory', [UserController::class, 'index'])
        ->middleware('permission:user-view')
        ->name('directory');
    $router->get('/rbac/roles', [RoleController::class, 'index'])
        ->middleware('permission:rbac-roles-view')
        ->name('rbac.roles');
    $router->get('/rbac/email', [MasterEmailTemplateController::class, 'index'])
        ->middleware('permission:rbac-email-view')
        ->name('rbac.email');
});

$router->post('/modal/content', function (Request $request): void {
    if (strtolower((string) $request->header('x-requested-with', '')) !== 'xmlhttprequest') {
        http_response_code(403);
        echo '<div class="alert alert-danger" role="alert">Invalid modal request.</div>';
        return;
    }

    $filePath = (string) $request->input('fileName', '');
    $dataArray = $request->input('dataArray', []);

    $normalizedPath = str_replace('\\', '/', trim($filePath));
    $partialName = pathinfo($normalizedPath, PATHINFO_FILENAME);

    if ($normalizedPath === '' || str_contains($normalizedPath, '..') || !str_starts_with($normalizedPath, 'views/')) {
        http_response_code(422);
        echo '<div class="alert alert-danger" role="alert">Invalid modal file path.</div>';
        return;
    }

    if (!str_starts_with((string) $partialName, '_')) {
        http_response_code(422);
        echo '<div class="alert alert-danger" role="alert">Invalid modal partial.</div>';
        return;
    }

    // Enforce .php extension only
    if (!str_ends_with($normalizedPath, '.php')) {
        http_response_code(422);
        echo '<div class="alert alert-danger" role="alert">Invalid file type.</div>';
        return;
    }

    $viewPath = 'app/' . ltrim($normalizedPath, '/');
    $absolute = realpath(ROOT_DIR . $viewPath);

    // Verify the resolved path is within the expected views directory
    $allowedDir = realpath(ROOT_DIR . 'app/views');
    if ($absolute === false || $allowedDir === false || !str_starts_with($absolute, $allowedDir)) {
        http_response_code(404);
        echo '<div class="alert alert-danger" role="alert">File not found.</div>';
        return;
    }

    if (!is_readable($absolute)) {
        http_response_code(404);
        echo '<div class="alert alert-danger" role="alert">File <b><i>' . htmlspecialchars($viewPath, ENT_QUOTES, 'UTF-8') . '</i></b> does not exist.</div>';
        return;
    }

    // Pass only sanitized, scalar values to the view — no extract()
    $__modalData = is_array($dataArray) ? array_map(function ($v) {
        return is_scalar($v) ? $v : null;
    }, $dataArray) : [];

    include $absolute;
})->middleware('web')->middleware('auth.web')->name('modal.content');