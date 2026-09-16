<?php

namespace App\Console\Commands;

use App\Console\Concerns\InteractsWithIpBlocklist;
use Core\Console\Command;
use Core\Console\Kernel;

class BlocklistListCommand extends Command
{
    use InteractsWithIpBlocklist;

    public function name(): string
    {
        return 'blocklist:list';
    }

    public function description(): string
    {
        return 'List all blocked IP addresses [--format=table|json]';
    }

    public function handle(array $args, array $options, Kernel $console): int
    {
        $rows = $this->blocklist()->all();
        $format = strtolower(trim((string) ($options['format'] ?? 'table')));

        if ($format === 'json') {
            $console->line((string) json_encode(['total' => count($rows), 'items' => array_values($rows)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return 0;
        }

        $table = [];
        foreach ($rows as $row) {
            $table[] = [
                (string) ($row['ip_address'] ?? ''),
                (string) ($row['reason'] ?? ''),
                ((int) ($row['auto_added'] ?? 0) === 1) ? 'yes' : 'no',
                (string) ($row['expires_at'] ?? 'permanent'),
                (string) ($row['blocked_at'] ?? ''),
            ];
        }

        $console->newLine();
        $console->info('  IP Blocklist');
        $console->table(['IP', 'Reason', 'Auto', 'Expires', 'Blocked At'], $table === [] ? [['—', '—', '—', '—', '—']] : $table);
        $console->newLine();

        return 0;
    }
}