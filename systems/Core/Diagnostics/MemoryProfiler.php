<?php

declare(strict_types=1);

namespace Core\Diagnostics;

use Core\Http\Request;
use Core\Security\AuditLogger;

final class MemoryProfiler
{
    private const LOG_PATH = ROOT_DIR . 'storage/logs/request-memory.log';

    /** @return array<string, mixed> */
    public static function begin(?Request $request = null): array
    {
        return [
            'started_at' => microtime(true),
            'start_memory' => memory_get_usage(true),
            'request_path' => $request?->path() ?? ($_SERVER['REQUEST_URI'] ?? 'cli'),
            'method' => $request?->method() ?? (PHP_SAPI === 'cli' ? 'CLI' : ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
        ];
    }

    /** @param array<string, mixed> $handle */
    public static function end(array $handle, ?Request $request = null): void
    {
        $config = function_exists('config') ? (array) config('framework.profiling', []) : [];
        if (($config['memory_log_enabled'] ?? true) !== true) {
            return;
        }

        $elapsedMs = max(0.0, (microtime(true) - (float) ($handle['started_at'] ?? microtime(true))) * 1000);
        $delta = max(0, memory_get_usage(true) - (int) ($handle['start_memory'] ?? memory_get_usage(true)));
        $peak = memory_get_peak_usage(true);
        $entry = [
            'logged_at' => date('c'),
            'path' => $request?->path() ?? (string) ($handle['request_path'] ?? 'cli'),
            'method' => $request?->method() ?? (string) ($handle['method'] ?? 'CLI'),
            'elapsed_ms' => round($elapsedMs, 2),
            'memory_delta_bytes' => $delta,
            'memory_peak_bytes' => $peak,
        ];

        self::append($entry);

        $alertMb = max(1, (int) ($config['memory_alert_mb'] ?? 32));
        $slowMs = max(1, (int) ($config['slow_request_ms'] ?? 2000));
        if ($delta >= ($alertMb * 1048576) || $elapsedMs >= $slowMs) {
            AuditLogger::log(
                AuditLogger::E_ADMIN_ACTION,
                [
                    'action' => 'request.profile.alert',
                    'path' => $entry['path'],
                    'method' => $entry['method'],
                    'elapsed_ms' => $entry['elapsed_ms'],
                    'memory_delta_bytes' => $entry['memory_delta_bytes'],
                    'memory_peak_bytes' => $entry['memory_peak_bytes'],
                ],
                'warning'
            );
        }
    }

    public static function clear(): void
    {
        $dir = dirname(self::LOG_PATH);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        file_put_contents(self::LOG_PATH, '');
    }

    public static function path(): string
    {
        return self::LOG_PATH;
    }

    /** @return array<int, array<string, mixed>> */
    public static function readAll(): array
    {
        if (!is_file(self::LOG_PATH) || !is_readable(self::LOG_PATH)) {
            return [];
        }

        $lines = file(self::LOG_PATH, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $rows = [];
        foreach ($lines as $line) {
            $decoded = json_decode((string) $line, true);
            if (is_array($decoded)) {
                $rows[] = $decoded;
            }
        }

        return $rows;
    }

    /** @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    public static function summarize(array $rows, int $limit = 20): array
    {
        usort($rows, static fn(array $left, array $right): int => [$right['memory_peak_bytes'] ?? 0, $right['elapsed_ms'] ?? 0] <=> [$left['memory_peak_bytes'] ?? 0, $left['elapsed_ms'] ?? 0]);

        return array_slice($rows, 0, $limit);
    }

    private static function append(array $entry): void
    {
        $dir = dirname(self::LOG_PATH);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        @file_put_contents(self::LOG_PATH, json_encode($entry, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}