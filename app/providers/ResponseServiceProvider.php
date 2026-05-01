<?php

namespace App\Providers;

class ResponseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        register_framework_service('response', fn() => new \Core\Http\ResponseFactory(blade_engine()));
    }
}