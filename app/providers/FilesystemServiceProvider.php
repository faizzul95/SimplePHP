<?php

namespace App\Providers;

class FilesystemServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        register_framework_service('files', fn() => new \Components\Files());
        register_framework_service('storage', fn() => new \Core\Filesystem\StorageManager((array) ($this->config['filesystems'] ?? [])));
    }
}