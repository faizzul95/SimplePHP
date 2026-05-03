<?php

return [
    'flags' => [
        // Module : User
        'user.profile' => ['enabled' => true, 'environments' => ['development', 'staging', 'production']],
        'uploads.image-cropper' => ['enabled' => true, 'environments' => ['development', 'staging', 'production']],
        
        // Module : RBAC
        'rbac.role' => ['enabled' => true, 'environments' => ['development', 'staging', 'production']],
        'rbac.permission' => ['enabled' => true, 'environments' => ['development', 'staging', 'production']],
        'email-template' => ['enabled' => true, 'environments' => ['development', 'staging']],
        // 'exports.async' => true,
    ],
];