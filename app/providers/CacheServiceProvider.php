<?php

namespace App\Providers;

use Core\Database\QueryCache;

class CacheServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /*
        | Registered rather than held in a function static inside cache(), for the
        | same reason csrf() was moved: a static outlives the request under a
        | worker SAPI and the flush cannot reach it. The array store in particular
        | documents itself as request-scoped, and in a static it was not — one
        | request's entries were readable by the next.
        */
        register_framework_service(
            'cache',
            fn(): \Core\Cache\CacheManager => new \Core\Cache\CacheManager((array) ($this->config['cache'] ?? []))
        );

        register_framework_service(
            'queue.dispatcher',
            fn(): \Core\Queue\Dispatcher => new \Core\Queue\Dispatcher((array) ($this->config['queue'] ?? []))
        );
    }

    public function boot(): void
    {
        $cacheConfig = (array) ($this->config['db']['cache'] ?? []);
        $cachePath = trim((string) ($cacheConfig['path'] ?? ''));

        QueryCache::init($cachePath !== '' ? $cachePath : null);

        if (empty($cacheConfig['enabled'])) {
            QueryCache::disable();
            return;
        }

        QueryCache::enable();
    }
}