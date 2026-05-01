<?php

namespace App\Providers;

class FeatureServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        register_framework_service('feature', function () {
            $flags = $this->config['features']['flags'] ?? null;
            if (!is_array($flags)) {
                $flags = (array) ($this->config['features'] ?? []);
            }

            return new \Components\FeatureManager($flags);
        });
    }
}