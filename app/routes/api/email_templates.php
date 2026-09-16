<?php

use App\Http\Controllers\MasterEmailTemplateController;

/** @var \Core\Routing\Router $router */

$router->group(['prefix' => 'email-templates', 'middleware' => ['permission:rbac-email-view', 'feature:email-template']], function ($router) {
    $router->post('/list', [MasterEmailTemplateController::class, 'listEmailTemplateDatatable'])->name('email-templates.list');
    $router->get('/show/{id}', [MasterEmailTemplateController::class, 'show'])->name('email-templates.show');
    $router->post('/save', [MasterEmailTemplateController::class, 'save'])->middleware('xss:email_body')->permissionAny(['rbac-email-create', 'rbac-email-update'])->name('email-templates.save');
    $router->delete('/delete/{id}', [MasterEmailTemplateController::class, 'destroy'])->permission('rbac-email-delete')->name('email-templates.delete');
});