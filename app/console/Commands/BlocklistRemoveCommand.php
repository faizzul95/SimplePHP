<?php

namespace App\Console\Commands;

use App\Console\Concerns\InteractsWithIpBlocklist;
use Core\Console\Command;
use Core\Console\Kernel;

class BlocklistRemoveCommand extends Command
{
    use InteractsWithIpBlocklist;

    public function name(): string
    {
        return 'blocklist:remove';
    }

    public function description(): string
    {
        return 'Remove an IP address from the blocklist [ip]';
    }

    public function handle(array $args, array $options, Kernel $console): int
    {
        $ip = trim((string) ($args[0] ?? ''));
        if ($ip === '') {
            $console->error('Usage: php myth blocklist:remove <ip>');
            return 1;
        }

        if (!$this->blocklist()->remove($ip)) {
            $console->error('Failed to remove IP from blocklist.');
            return 1;
        }

        $console->success('  IP removed from blocklist: ' . $ip);
        return 0;
    }
}