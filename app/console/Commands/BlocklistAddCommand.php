<?php

namespace App\Console\Commands;

use App\Console\Concerns\InteractsWithIpBlocklist;
use Core\Console\Command;
use Core\Console\Kernel;

class BlocklistAddCommand extends Command
{
    use InteractsWithIpBlocklist;

    public function name(): string
    {
        return 'blocklist:add';
    }

    public function description(): string
    {
        return 'Add an IP address to the blocklist [ip] [--reason=Manual block] [--expires=24h]';
    }

    public function handle(array $args, array $options, Kernel $console): int
    {
        $ip = trim((string) ($args[0] ?? ''));
        $reason = trim((string) ($options['reason'] ?? 'Manual block'));
        $expiresAt = $this->parseExpires($options['expires'] ?? null);

        if ($ip === '' || $reason === '') {
            $console->error('Usage: php myth blocklist:add <ip> [--reason="Manual block"] [--expires=24h]');
            return 1;
        }

        if (($options['expires'] ?? null) !== null && $expiresAt === null) {
            $console->error('Invalid --expires value. Use formats like 30m, 24h, 7d, or 2026-05-16 12:00:00.');
            return 1;
        }

        if (!$this->blocklist()->add($ip, $reason, $expiresAt, false)) {
            $console->error('Failed to add IP to blocklist.');
            return 1;
        }

        $console->success('  IP added to blocklist: ' . $ip);
        return 0;
    }
}