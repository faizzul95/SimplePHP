<?php

namespace App\Providers;

class RoutingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        register_framework_service('route.provider', fn() => new \Core\Routing\RouteServiceProvider((array) ($this->config['framework'] ?? [])));
    }

    public function boot(): void
    {
        if (!function_exists('loadMiddlewaresFiles')) {
            return;
        }

        loadMiddlewaresFiles((array) ($this->config['middleware'] ?? []));
    }
}