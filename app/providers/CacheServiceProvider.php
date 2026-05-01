<?php

namespace App\Providers;

use Core\Database\QueryCache;

class CacheServiceProvider extends ServiceProvider
{
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