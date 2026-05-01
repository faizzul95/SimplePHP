<?php

return [
    'default' => 'local',
    'drivers' => [
        'local' => [
            'adapter' => 'Core\\Filesystem\\LocalFilesystemAdapter',
        ],
        's3' => [
            'adapter' => App\Support\Filesystem\S3FilesystemAdapter::class,
        ],
        'gdrive' => [
            'adapter' => App\Support\Filesystem\GoogleDriveFilesystemAdapter::class,
        ],
    ],
    'disks' => [
        'local' => [
            'driver' => 'local',
            'root' => 'storage/app',
        ],
        'public' => [
            'driver' => 'local',
            'root' => 'storage/public',
            'url' => '/storage',
        ],
        'private' => [
            'driver' => 'local',
            'root' => 'storage/private',
        ],
        // 's3' => [
        //     'driver' => 's3',
        //     'bucket' => env('FILESYSTEM_S3_BUCKET', ''),
        //     'region' => env('FILESYSTEM_S3_REGION', ''),
        //     'base_url' => env('FILESYSTEM_S3_URL', null),
        // ],
        // 'gdrive' => [
        //     'driver' => 'gdrive',
        //     'root_id' => env('FILESYSTEM_GDRIVE_ROOT_ID', ''),
        //     'credentials_path' => env('FILESYSTEM_GDRIVE_CREDENTIALS_PATH', ''),
        //     'credentials_json' => env('FILESYSTEM_GDRIVE_CREDENTIALS_JSON', ''),
        //     'subject' => env('FILESYSTEM_GDRIVE_SUBJECT', ''),
        //     'shared_drive_id' => env('FILESYSTEM_GDRIVE_SHARED_DRIVE_ID', ''),
        //     'chunk_size' => (int) env('FILESYSTEM_GDRIVE_CHUNK_SIZE', 8388608),
        //     'base_url' => env('FILESYSTEM_GDRIVE_URL', null),
        // ],
    ],
];