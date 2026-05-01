<?php

namespace App\Providers;

class SecurityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        register_framework_service('security', fn() => new \Components\Security());
    }
}