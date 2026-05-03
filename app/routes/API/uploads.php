<?php

use App\Http\Controllers\UploadController;

$router->group(['prefix' => 'uploads'], function ($router) {
    $router->post('/image-cropper', [UploadController::class, 'uploadImageCropper'])
        ->permission('user-upload-profile')
    ->middleware('api.upload.image')
    ->middleware('xss:image')
    ->featureFlag('uploads.image-cropper')
    ->name('uploads.image-cropper');
    
    $router->post('/delete', [UploadController::class, 'removeUploadFiles'])->permission('user-upload-profile')->middleware('api.upload.action')->middleware('xss')->name('uploads.delete');
});