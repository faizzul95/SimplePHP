<?php

namespace App\Console\Commands;

use App\Console\Concerns\InteractsWithIpBlocklist;
use Core\Console\Command;
use Core\Console\Kernel;

class BlocklistPruneCommand extends Command
{
    use InteractsWithIpBlocklist;

    public function name(): string
    {
        return 'blocklist:prune';
    }

    public function description(): string
    {
        return 'Remove expired IP blocklist entries';
    }

    public function handle(array $args, array $options, Kernel $console): int
    {
        $count = $this->blocklist()->prune();
        $console->success('  Pruned expired blocklist entries: ' . $count);
        return 0;
    }
}