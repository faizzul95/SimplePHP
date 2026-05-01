<?php

namespace App\Providers;

class LogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        register_framework_service('logger', function () {
            $rootDir = defined('ROOT_DIR') ? ROOT_DIR : dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
            $relativePath = isset($this->config['error_log_path']) && is_string($this->config['error_log_path']) && trim($this->config['error_log_path']) !== ''
                ? trim($this->config['error_log_path'])
                : 'logs/error.log';

            return new \Components\Logger($rootDir . $relativePath);
        });
    }
}