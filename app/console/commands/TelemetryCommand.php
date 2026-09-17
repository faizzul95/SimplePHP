<?php

namespace App\Console\Commands;

use Core\Console\Command;
use Core\Console\Kernel;
use Core\Telemetry\Entry;
use Core\Telemetry\FileStore;
use Core\Telemetry\Recorder;

/**
 * Read the telemetry store from the terminal.
 *
 * The bar answers "what did this page just do". This answers "what has been
 * slow lately", which is the question you have when nobody is watching a page
 * — after a cron run, or when a user reports something from an hour ago.
 */
class TelemetryCommand extends Command
{
    public function name(): string
    {
        return 'telemetry:show';
    }

    public function description(): string
    {
        return 'Summarise recorded telemetry: slow queries, duplicates, errors, requests';
    }

    public function handle(array $args, array $options, Kernel $console): int
    {
        $store = new FileStore((array) (config('telemetry.storage') ?? []));

        if ($this->flag($options, 'clear')) {
            $console->success('Removed ' . $store->purge() . ' telemetry file(s).');

            return 0;
        }

        if ($this->flag($options, 'prune')) {
            $console->success('Pruned ' . $store->prune() . ' expired telemetry file(s).');

            return 0;
        }

        $limit = max(1, (int) ($options['limit'] ?? 1000));
        $top = max(1, (int) ($options['top'] ?? 15));
        $entries = $store->read($limit);

        if ($entries === []) {
            $console->newLine();
            $console->warn('  No telemetry recorded.');
            $console->line('  Set TELEMETRY_ENABLED=true and TELEMETRY_MODE=all, then load a page.');
            $console->line('  Storage: ' . $store->directory());
            $console->newLine();

            return 0;
        }

        $view = strtolower(trim((string) ($args[0] ?? $options['view'] ?? 'summary')));

        return match ($view) {
            'slow' => $this->slowQueries($entries, $top, $console),
            'duplicates', 'dupes', 'n1' => $this->duplicates($entries, $top, $console),
            'errors' => $this->errors($entries, $top, $console),
            'requests' => $this->requests($entries, $top, $console),
            default => $this->summary($entries, $store, $console),
        };
    }

    /** @param list<Entry> $entries */
    private function summary(array $entries, FileStore $store, Kernel $console): int
    {
        $byType = [];
        foreach ($entries as $entry) {
            $byType[$entry->type] = ($byType[$entry->type] ?? 0) + 1;
        }
        arsort($byType);

        $queries = $this->ofType($entries, Entry::TYPE_QUERY);
        $queryMs = array_sum(array_map(static fn(Entry $e): float => (float) $e->durationMs, $queries));
        $requests = $this->ofType($entries, Entry::TYPE_REQUEST);
        $errors = $this->ofType($entries, Entry::TYPE_EXCEPTION);

        $rows = [];
        foreach ($byType as $type => $count) {
            $rows[] = [$type, (string) $count];
        }

        $console->newLine();
        $console->info('  Telemetry summary');
        $console->line('  ' . $store->directory());
        $console->newLine();
        $console->table(['Type', 'Count'], $rows);
        $console->newLine();

        if ($requests !== []) {
            $times = array_map(static fn(Entry $e): float => (float) $e->durationMs, $requests);
            $console->line(sprintf(
                '  %d requests · avg %.1fms · slowest %.1fms',
                count($requests),
                array_sum($times) / count($times),
                max($times)
            ));
        }

        $console->line(sprintf('  %d queries · %.1fms total', count($queries), $queryMs));

        if ($errors !== []) {
            $console->error('  ' . count($errors) . ' exception(s) recorded — run: telemetry:show errors');
        }

        $worst = $this->shapes($queries);
        if ($worst !== [] && $worst[0]['count'] > 1) {
            $console->warn(sprintf(
                '  Most repeated query ran %d times — run: telemetry:show duplicates',
                $worst[0]['count']
            ));
        }

        $console->newLine();
        $console->comment('  Views: summary · slow · duplicates · errors · requests');
        $console->newLine();

        return 0;
    }

    /** @param list<Entry> $entries */
    private function slowQueries(array $entries, int $top, Kernel $console): int
    {
        $queries = $this->ofType($entries, Entry::TYPE_QUERY);
        usort($queries, static fn(Entry $a, Entry $b): int => ($b->durationMs ?? 0) <=> ($a->durationMs ?? 0));

        $rows = [];
        foreach (array_slice($queries, 0, $top) as $entry) {
            $rows[] = [
                number_format((float) $entry->durationMs, 2),
                mb_strimwidth((string) ($entry->payload['origin'] ?? '—'), 0, 34, '…'),
                mb_strimwidth((string) ($entry->payload['sql'] ?? ''), 0, 74, '…'),
            ];
        }

        $console->newLine();
        $console->info('  Slowest queries');
        $console->table(['ms', 'Origin', 'Query'], $rows === [] ? [['—', '—', 'no queries recorded']] : $rows);
        $console->newLine();

        return 0;
    }

    /** @param list<Entry> $entries */
    private function duplicates(array $entries, int $top, Kernel $console): int
    {
        $shapes = $this->shapes($this->ofType($entries, Entry::TYPE_QUERY));
        $repeated = array_values(array_filter($shapes, static fn(array $s): bool => $s['count'] > 1));

        $rows = [];
        foreach (array_slice($repeated, 0, $top) as $shape) {
            $rows[] = [
                (string) $shape['count'],
                number_format($shape['total_ms'], 2),
                mb_strimwidth($shape['sql'], 0, 88, '…'),
            ];
        }

        $console->newLine();
        $console->info('  Repeated query shapes');
        $console->line('  A shape running many times in one request is what an N+1 looks like.');
        $console->newLine();
        $console->table(['Runs', 'Total ms', 'Query'], $rows === [] ? [['—', '—', 'nothing ran more than once']] : $rows);
        $console->newLine();

        return 0;
    }

    /** @param list<Entry> $entries */
    private function errors(array $entries, int $top, Kernel $console): int
    {
        $rows = [];
        foreach (array_slice($this->ofType($entries, Entry::TYPE_EXCEPTION), 0, $top) as $entry) {
            $rows[] = [
                date('H:i:s', (int) $entry->recordedAt),
                mb_strimwidth((string) ($entry->payload['class'] ?? ''), 0, 28, '…'),
                mb_strimwidth((string) ($entry->payload['message'] ?? ''), 0, 46, '…'),
                mb_strimwidth(
                    basename((string) ($entry->payload['file'] ?? '')) . ':' . ($entry->payload['line'] ?? ''),
                    0,
                    26,
                    '…'
                ),
            ];
        }

        $console->newLine();
        $console->info('  Recorded exceptions');
        $console->table(['Time', 'Class', 'Message', 'At'], $rows === [] ? [['—', '—', 'none recorded', '—']] : $rows);
        $console->newLine();

        return 0;
    }

    /** @param list<Entry> $entries */
    private function requests(array $entries, int $top, Kernel $console): int
    {
        $requests = $this->ofType($entries, Entry::TYPE_REQUEST);
        usort($requests, static fn(Entry $a, Entry $b): int => ($b->durationMs ?? 0) <=> ($a->durationMs ?? 0));

        $rows = [];
        foreach (array_slice($requests, 0, $top) as $entry) {
            $payload = $entry->payload;
            $rows[] = [
                number_format((float) $entry->durationMs, 1),
                (string) ($payload['status'] ?? '—'),
                (string) ($payload['query_count'] ?? 0),
                (string) ($payload['memory_mb'] ?? 0) . 'MB',
                mb_strimwidth(($payload['method'] ?? '') . ' ' . ($payload['path'] ?? ''), 0, 52, '…'),
            ];
        }

        $console->newLine();
        $console->info('  Slowest requests');
        $console->table(
            ['ms', 'Status', 'Queries', 'Memory', 'Route'],
            $rows === [] ? [['—', '—', '—', '—', 'none recorded']] : $rows
        );
        $console->newLine();

        return 0;
    }

    // ─── helpers ─────────────────────────────────────────────────────

    /**
     * @param list<Entry> $entries
     * @return list<Entry>
     */
    private function ofType(array $entries, string $type): array
    {
        return array_values(array_filter($entries, static fn(Entry $e): bool => $e->type === $type));
    }

    /**
     * @param list<Entry> $queries
     * @return list<array{sql: string, count: int, total_ms: float}>
     */
    private function shapes(array $queries): array
    {
        $shapes = [];

        foreach ($queries as $entry) {
            $key = Recorder::fingerprint((string) ($entry->payload['sql'] ?? ''));
            if (!isset($shapes[$key])) {
                $shapes[$key] = ['sql' => $key, 'count' => 0, 'total_ms' => 0.0];
            }
            $shapes[$key]['count']++;
            $shapes[$key]['total_ms'] += (float) ($entry->durationMs ?? 0);
        }

        usort($shapes, static fn(array $a, array $b): int => $b['count'] <=> $a['count']);

        return array_values($shapes);
    }

    /** @param array<string, mixed> $options */
    private function flag(array $options, string $name): bool
    {
        return isset($options[$name]) && ($options[$name] === true || $options[$name] === '1' || $options[$name] === '');
    }
}
