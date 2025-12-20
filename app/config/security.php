<?php

/*
|--------------------------------------------------------------------------
| SECURITY
|--------------------------------------------------------------------------
*/

$config['security'] = [
    'throttle_request'   => false,
    'xss_request'        => true,
    'permission_request' => false,

    'csrf' => [
        'csrf_protection'    => false,
        'csrf_token_name'    => 'csrf_token',
        'csrf_cookie_name'   => 'csrf_cookie',
        'csrf_expire'        => 7200,
        'csrf_regenerate'    => true,
        'csrf_include_uris'  => [
            'UserController\save',
            // 'RoleController\save',
            // 'MasterEmailTemplateController\save',
            // 'UploadController\uploadImageCropper',
        ],
        'csrf_secure_cookie' => true,
        'csrf_httponly'      => false,
        'csrf_samesite'      => 'Strict',
    ],
];