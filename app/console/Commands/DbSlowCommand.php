<?php

namespace App\Console\Commands;

use Core\Console\Command;
use Core\Console\Kernel;
use Core\Database\SlowQueryLogger;

class DbSlowCommand extends Command
{
    public function name(): string
    {
        return 'db:slow';
    }

    public function description(): string
    {
        return 'Show or clear aggregated slow-query log entries';
    }

    public function handle(array $args, array $options, Kernel $console): int
    {
        if (isset($options['flush']) && ($options['flush'] === true || $options['flush'] === '1')) {
            SlowQueryLogger::clear();
            $console->success('Slow query log cleared.');
            return 0;
        }

        $top = max(1, (int) ($options['top'] ?? 20));
        $format = strtolower(trim((string) ($options['format'] ?? 'table')));
        $min = isset($options['min']) ? max(0.0, (float) $options['min']) : null;
        $sinceInput = trim((string) ($options['since'] ?? ''));
        $since = $this->parseSince($sinceInput);
        if ($sinceInput !== '' && $since === null) {
            $console->error('Invalid --since value. Use 1h, 7d, 30m, or a strtotime-compatible timestamp.');
            return 1;
        }

        $rows = SlowQueryLogger::readAll($since, $min);
        $summary = SlowQueryLogger::summarize($rows, $top);

        if ($format === 'json') {
            $console->line((string) json_encode([
                'total' => count($rows),
                'top' => $summary,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return 0;
        }

        $tableRows = [];
        foreach ($summary as $item) {
            $tableRows[] = [
                (string) $item['count'],
                number_format((float) $item['max_ms'], 2),
                number_format((float) $item['avg_ms'], 2),
                (string) $item['connection'],
                mb_strimwidth((string) $item['query'], 0, 80, '...'),
            ];
        }

        $console->newLine();
        $console->info('  Slow Query Summary');
        $console->table(['Count', 'Max ms', 'Avg ms', 'Connection', 'Query'], $tableRows === [] ? [['0', '0.00', '0.00', '—', '—']] : $tableRows);
        $console->newLine();

        return 0;
    }

    private function parseSince(string $value): ?int
    {
        if ($value === '') {
            return null;
        }

        if (preg_match('/^(\d+)([mhd])$/i', $value, $matches) === 1) {
            $amount = (int) $matches[1];
            $seconds = match (strtolower($matches[2])) {
                'm' => $amount * 60,
                'h' => $amount * 3600,
                'd' => $amount * 86400,
                default => 0,
            };

            return time() - $seconds;
        }

        $timestamp = strtotime($value);
        return $timestamp === false ? null : $timestamp;
    }
}