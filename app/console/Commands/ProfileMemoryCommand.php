<?php

namespace App\Console\Commands;

use Core\Console\Command;
use Core\Console\Kernel;
use Core\Diagnostics\MemoryProfiler;

class ProfileMemoryCommand extends Command
{
    public function name(): string
    {
        return 'profile:memory';
    }

    public function description(): string
    {
        return 'Show or clear recent request memory profile entries';
    }

    public function handle(array $args, array $options, Kernel $console): int
    {
        if (isset($options['flush']) && ($options['flush'] === true || $options['flush'] === '1')) {
            MemoryProfiler::clear();
            $console->success('Request memory profile log cleared.');
            return 0;
        }

        $top = max(1, (int) ($options['top'] ?? 20));
        $format = strtolower(trim((string) ($options['format'] ?? 'table')));
        $rows = MemoryProfiler::summarize(MemoryProfiler::readAll(), $top);

        if ($format === 'json') {
            $console->line((string) json_encode([
                'total' => count($rows),
                'top' => $rows,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return 0;
        }

        $tableRows = [];
        foreach ($rows as $row) {
            $tableRows[] = [
                (string) ($row['method'] ?? 'GET'),
                (string) ($row['path'] ?? '/'),
                number_format(((int) ($row['memory_delta_bytes'] ?? 0)) / 1048576, 2),
                number_format(((int) ($row['memory_peak_bytes'] ?? 0)) / 1048576, 2),
                number_format((float) ($row['elapsed_ms'] ?? 0), 2),
            ];
        }

        $console->newLine();
        $console->info('  Request Memory Summary');
        $console->table(['Method', 'Path', 'Delta MB', 'Peak MB', 'Elapsed ms'], $tableRows === [] ? [['—', '—', '0.00', '0.00', '0.00']] : $tableRows);
        $console->newLine();

        return 0;
    }
}