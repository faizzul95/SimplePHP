<?php

namespace App\Providers;

class SecurityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        register_framework_service('security', fn() => new \Components\Security());
        register_framework_service('csrf', fn() => new \Components\CSRF(
            (array) ($this->config['security']['csrf'] ?? [])
        ));
    }
}