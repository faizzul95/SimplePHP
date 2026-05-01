<?php

namespace App\Providers;

class ViewServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        register_framework_service('blade_engine', function () {
            $viewPath = ROOT_DIR . ($this->config['framework']['view_path'] ?? 'app/views');
            $cachePath = ROOT_DIR . ($this->config['framework']['view_cache_path'] ?? 'storage/cache/views');

            return new \Core\View\BladeEngine($viewPath, $cachePath);
        });
    }
}