<?php

namespace App\Providers;

class MaintenanceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        register_framework_service('maintenance', fn() => new \Components\Maintenance((array) ($this->config['framework']['maintenance'] ?? [])));
    }
}