<?php

namespace App\Console\Commands;

use App\Console\Concerns\InteractsWithIpBlocklist;
use Core\Console\Command;
use Core\Console\Kernel;

class BlocklistImportCommand extends Command
{
    use InteractsWithIpBlocklist;

    public function name(): string
    {
        return 'blocklist:import';
    }

    public function description(): string
    {
        return 'Import blocked IPs from a file [path] [--reason=Imported block] [--expires=24h]';
    }

    public function handle(array $args, array $options, Kernel $console): int
    {
        $path = trim((string) ($args[0] ?? ''));
        $reason = trim((string) ($options['reason'] ?? 'Imported block'));
        $expiresAt = $this->parseExpires($options['expires'] ?? null);

        if ($path === '' || $reason === '') {
            $console->error('Usage: php myth blocklist:import <path> [--reason="Imported block"] [--expires=24h]');
            return 1;
        }

        if (($options['expires'] ?? null) !== null && $expiresAt === null) {
            $console->error('Invalid --expires value. Use formats like 30m, 24h, 7d, or 2026-05-16 12:00:00.');
            return 1;
        }

        $count = $this->blocklist()->import($path, $reason, $expiresAt);
        if ($count === 0 && (!is_file($path) || !is_readable($path))) {
            $console->error('Import file not found or not readable.');
            return 1;
        }

        $console->success('  Imported blocked IPs: ' . $count);
        return 0;
    }
}