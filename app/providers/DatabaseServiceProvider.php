<?php

namespace App\Providers;

use App\Support\DatabaseRuntime;

class DatabaseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        register_framework_service('database.runtime', fn() => new DatabaseRuntime($this->config));
    }
}