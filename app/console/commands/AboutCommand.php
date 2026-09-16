<?php

namespace App\Console\Commands;

use Core\Console\Command;
use Core\Console\Kernel;

class AboutCommand extends Command
{
    public function name(): string
    {
        return 'about';
    }

    public function description(): string
    {
        return 'Display runtime, provider, and database configuration summary';
    }

    public function handle(array $args, array $options, Kernel $console): int
    {
        $framework = (array) (config('framework') ?? []);
        $providers = (array) ($framework['providers'] ?? []);
        $database = (array) (config('db.default.' . ENVIRONMENT) ?? []);

        $rows = [
            ['App', APP_NAME],
            ['Environment', defined('ENVIRONMENT') ? ENVIRONMENT : 'production'],
            ['Runtime', defined('BOOTSTRAP_RUNTIME') ? BOOTSTRAP_RUNTIME : (PHP_SAPI === 'cli' ? 'cli' : 'web')],
            ['Session Bootstrap', defined('BOOTSTRAP_SESSION_ENABLED') && BOOTSTRAP_SESSION_ENABLED ? 'enabled' : 'disabled'],
            ['Providers', (string) count($providers)],
            ['Database Driver', (string) ($database['driver'] ?? 'mysql')],
            ['Database Name', (string) ($database['database'] ?? 'n/a')],
        ];

        $console->newLine();
        $console->info('  MythPHP Runtime Summary');
        $console->table(['Key', 'Value'], $rows);
        $console->newLine();

        return 0;
    }
}