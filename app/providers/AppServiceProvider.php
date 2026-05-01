<?php

namespace App\Providers;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->initializeTimezone();
    }

    protected function initializeTimezone(): void
    {
        $timezone = trim((string) ($this->config['timezone'] ?? ''));
        if ($timezone === '') {
            return;
        }

        if (!@date_default_timezone_set($timezone) && function_exists('logger')) {
            logger()->log_error('Invalid application timezone configured: ' . $timezone);
        }
    }
}