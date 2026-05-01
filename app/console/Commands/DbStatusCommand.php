<?php

namespace App\Console\Commands;

use Core\Console\Command;
use Core\Console\Kernel;

class DbStatusCommand extends Command
{
    public function name(): string
    {
        return 'db:status';
    }

    public function description(): string
    {
        return 'Display the configured database connection profile';
    }

    public function handle(array $args, array $options, Kernel $console): int
    {
        $connection = (array) (config('db.default.' . ENVIRONMENT) ?? []);
        $rows = [
            ['Driver', (string) ($connection['driver'] ?? 'mysql')],
            ['Host', (string) ($connection['host'] ?? '127.0.0.1')],
            ['Port', (string) ($connection['port'] ?? '')],
            ['Database', (string) ($connection['database'] ?? '')],
            ['Username', (string) ($connection['username'] ?? '')],
            ['Charset', (string) ($connection['charset'] ?? 'utf8mb4')],
        ];

        $console->newLine();
        $console->info('  Database Configuration');
        $console->table(['Setting', 'Value'], $rows);
        $console->newLine();

        return 0;
    }
}