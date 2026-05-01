<?php

return [
    'flags' => [
        'rbac.role' => ['enabled' => true, 'environments' => ['development', 'staging', 'production']],
        'rbac.permission' => ['enabled' => true, 'environments' => ['development', 'staging', 'production']],
        'email-template' => ['enabled' => true, 'environments' => ['development', 'staging']],
        // 'exports.async' => true,
    ],
];