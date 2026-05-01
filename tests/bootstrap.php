<?php

define('ROOT_DIR', dirname(__DIR__) . DIRECTORY_SEPARATOR);

require_once ROOT_DIR . 'vendor/autoload.php';
require_once ROOT_DIR . 'systems/hooks.php';

$GLOBALS['config'] = $GLOBALS['config'] ?? [];

if (!function_exists('config')) {
    function config($key, $default = null)
    {
        $config = $GLOBALS['config'] ?? [];
        if ($key === null || $key === '') {
            return $config;
        }

        $segments = explode('.', (string) $key);
        $value = $config;

        foreach ($segments as $segment) {
            if (is_array($value) && array_key_exists($segment, $value)) {
                $value = $value[$segment];
                continue;
            }

            return $default;
        }

        return $value;
    }
}

if (!function_exists('bootstrapTestFrameworkServices')) {
    function bootstrapTestFrameworkServices(array $config = [], array $runtimeState = ['runtime' => 'cli']): void
    {
        $defaultProviders = [
            \App\Providers\AppServiceProvider::class,
            \App\Providers\LogServiceProvider::class,
            \App\Providers\DatabaseServiceProvider::class,
            \App\Providers\CacheServiceProvider::class,
            \App\Providers\SecurityServiceProvider::class,
            \App\Providers\FilesystemServiceProvider::class,
            \App\Providers\ViewServiceProvider::class,
            \App\Providers\ResponseServiceProvider::class,
            \App\Providers\RoutingServiceProvider::class,
            \App\Providers\EventServiceProvider::class,
            \App\Providers\MaintenanceServiceProvider::class,
            \App\Providers\FeatureServiceProvider::class,
            \App\Providers\AuthServiceProvider::class,
        ];

        $framework = (array) ($config['framework'] ?? []);
        if (!isset($framework['providers'])) {
            $framework['providers'] = $defaultProviders;
        }

        $GLOBALS['config'] = array_replace_recursive($GLOBALS['config'] ?? [], $config, [
            'framework' => $framework,
        ]);

        reset_framework_service();
        reset_event_dispatcher();
        bootstrapRegisterServiceProviders($GLOBALS['config'], $runtimeState);
    }
}