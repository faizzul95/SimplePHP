<?php

namespace App\Providers;

class EventServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        register_framework_service('events', fn() => framework_event_dispatcher());
    }
}