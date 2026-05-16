<?php

namespace App\Console\Commands;

use Core\Console\Command;
use Core\Console\Kernel;
use Core\Security\CspViolationLogger;

class CspReportCommand extends Command
{
    public function name(): string
    {
        return 'csp:report';
    }

    public function description(): string
    {
        return 'Summarize CSP violations from the database table or fallback log file';
    }

    public function handle(array $args, array $options, Kernel $console): int
    {
        $top = max(1, (int) ($options['top'] ?? 20));
        $format = strtolower(trim((string) ($options['format'] ?? 'table')));
        $sinceInput = trim((string) ($options['since'] ?? ''));
        $sinceTs = $this->parseSince($sinceInput);

        if ($sinceInput !== '' && $sinceTs === null) {
            $console->error('Invalid --since value. Use formats like 7d, 24h, 30m, or 2026-05-16 12:00:00.');
            return 1;
        }

        $records = $this->loadRecords($sinceTs);
        $summary = $this->summarize($records, $top);

        if ($format === 'json') {
            $console->line((string) json_encode([
                'since' => $sinceTs !== null ? date('c', $sinceTs) : null,
                'total' => count($records),
                'top' => $summary,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return 0;
        }

        $rows = [];
        foreach ($summary as $item) {
            $rows[] = [
                (string) $item['count'],
                (string) $item['directive'],
                (string) $item['document_uri'],
                (string) $item['last_seen'],
            ];
        }

        $console->newLine();
        $console->info('  CSP Violation Summary');
        $console->table(['Count', 'Directive', 'Document', 'Last Seen'], $rows === [] ? [['0', '—', '—', '—']] : $rows);
        $console->newLine();

        return 0;
    }

    /** @return array<int, array<string, mixed>> */
    private function loadRecords(?int $sinceTs = null): array
    {
        $records = $this->loadFromDatabase($sinceTs);
        if ($records !== []) {
            return $records;
        }

        $path = CspViolationLogger::logFilePath();
        if (!is_file($path) || !is_readable($path)) {
            return [];
        }

        $rows = [];
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $decoded = json_decode((string) $line, true);
            if (!is_array($decoded)) {
                continue;
            }

            $createdAt = isset($decoded['ts']) ? strtotime((string) $decoded['ts']) : false;
            if ($sinceTs !== null && $createdAt !== false && $createdAt < $sinceTs) {
                continue;
            }

            $rows[] = [
                'directive' => (string) ($decoded['effective_directive'] ?: $decoded['violated_directive'] ?? ''),
                'document_uri' => (string) ($decoded['document_uri'] ?? ''),
                'created_at' => $createdAt !== false ? date('Y-m-d H:i:s', $createdAt) : '',
            ];
        }

        return $rows;
    }

    /** @return array<int, array<string, mixed>> */
    private function loadFromDatabase(?int $sinceTs = null): array
    {
        try {
            $query = db()->table('csp_violations')
                ->select('effective_directive, violated_directive, document_uri, created_at')
                ->orderBy('created_at', 'DESC');

            if ($sinceTs !== null) {
                $query->where('created_at', '>=', date('Y-m-d H:i:s', $sinceTs));
            }

            $rows = $query->get();
            if (!is_array($rows)) {
                return [];
            }

            return array_map(static function (array $row): array {
                return [
                    'directive' => (string) (($row['effective_directive'] ?? '') !== '' ? $row['effective_directive'] : ($row['violated_directive'] ?? '')),
                    'document_uri' => (string) ($row['document_uri'] ?? ''),
                    'created_at' => (string) ($row['created_at'] ?? ''),
                ];
            }, $rows);
        } catch (\Throwable) {
            return [];
        }
    }

    /** @param array<int, array<string, mixed>> $records
     *  @return array<int, array{count:int,directive:string,document_uri:string,last_seen:string}>
     */
    private function summarize(array $records, int $top): array
    {
        $bucket = [];
        foreach ($records as $record) {
            $directive = trim((string) ($record['directive'] ?? '')) ?: 'unknown';
            $document = trim((string) ($record['document_uri'] ?? '')) ?: 'unknown';
            $lastSeen = trim((string) ($record['created_at'] ?? ''));
            $key = $directive . '|' . $document;

            if (!isset($bucket[$key])) {
                $bucket[$key] = [
                    'count' => 0,
                    'directive' => $directive,
                    'document_uri' => $document,
                    'last_seen' => $lastSeen,
                ];
            }

            $bucket[$key]['count']++;
            if ($lastSeen !== '' && $lastSeen > $bucket[$key]['last_seen']) {
                $bucket[$key]['last_seen'] = $lastSeen;
            }
        }

        usort($bucket, static function (array $left, array $right): int {
            return [$right['count'], $right['last_seen']] <=> [$left['count'], $left['last_seen']];
        });

        return array_slice(array_values($bucket), 0, $top);
    }

    private function parseSince(string $value): ?int
    {
        if ($value === '') {
            return null;
        }

        if (preg_match('/^(\d+)([mhd])$/i', $value, $matches) === 1) {
            $amount = (int) $matches[1];
            $unit = strtolower($matches[2]);
            $seconds = match ($unit) {
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