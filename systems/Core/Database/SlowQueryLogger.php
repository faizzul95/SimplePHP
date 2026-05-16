<?php

declare(strict_types=1);

namespace Core\Database;

use Components\Logger;
use Core\Security\AuditLogger;

final class SlowQueryLogger
{
    private const LOG_PATH = ROOT_DIR . 'logs/database/slow.log';

    public static function record(array $entry): void
    {
        $normalized = self::normalize($entry);

        $dir = dirname(self::LOG_PATH);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        file_put_contents(
            self::LOG_PATH,
            json_encode($normalized, JSON_UNESCAPED_SLASHES) . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );

        if ((float) ($normalized['duration_ms'] ?? 0.0) >= (float) ($normalized['alert_ms'] ?? 2000.0)) {
            AuditLogger::log(
                AuditLogger::E_SLOW_QUERY,
                [
                    'connection' => $normalized['connection'],
                    'duration_ms' => $normalized['duration_ms'],
                    'threshold_ms' => $normalized['threshold_ms'],
                    'request_uri' => $normalized['request_uri'],
                    'query' => $normalized['query'],
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
    public static function readAll(?int $sinceTs = null, ?float $minMs = null): array
    {
        if (!is_file(self::LOG_PATH) || !is_readable(self::LOG_PATH)) {
            return [];
        }

        $lines = file(self::LOG_PATH, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $rows = [];
        foreach ($lines as $line) {
            $decoded = json_decode((string) $line, true);
            if (!is_array($decoded)) {
                continue;
            }

            $timestamp = strtotime((string) ($decoded['logged_at'] ?? ''));
            if ($sinceTs !== null && $timestamp !== false && $timestamp < $sinceTs) {
                continue;
            }

            if ($minMs !== null && (float) ($decoded['duration_ms'] ?? 0.0) < $minMs) {
                continue;
            }

            $rows[] = $decoded;
        }

        return $rows;
    }

    /** @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    public static function summarize(array $rows, int $limit = 20): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $query = trim((string) ($row['query'] ?? $row['full_query'] ?? ''));
            $key = md5($query);

            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'count' => 0,
                    'max_ms' => 0.0,
                    'avg_ms' => 0.0,
                    'total_ms' => 0.0,
                    'connection' => (string) ($row['connection'] ?? 'default'),
                    'query' => $query,
                    'last_seen' => (string) ($row['logged_at'] ?? ''),
                ];
            }

            $duration = (float) ($row['duration_ms'] ?? 0.0);
            $grouped[$key]['count']++;
            $grouped[$key]['total_ms'] += $duration;
            $grouped[$key]['max_ms'] = max($grouped[$key]['max_ms'], $duration);
            $grouped[$key]['avg_ms'] = $grouped[$key]['total_ms'] / max(1, $grouped[$key]['count']);

            if (($row['logged_at'] ?? '') > $grouped[$key]['last_seen']) {
                $grouped[$key]['last_seen'] = (string) ($row['logged_at'] ?? '');
            }
        }

        $items = array_values($grouped);
        usort($items, static fn(array $left, array $right): int => [$right['max_ms'], $right['count']] <=> [$left['max_ms'], $left['count']]);

        return array_slice($items, 0, $limit);
    }

    private static function normalize(array $entry): array
    {
        return [
            'event' => 'slow_query',
            'logged_at' => date('c'),
            'connection' => (string) ($entry['connection'] ?? 'default'),
            'table' => (string) ($entry['table'] ?? ''),
            'duration_ms' => round((float) ($entry['duration_ms'] ?? 0.0), 2),
            'threshold_ms' => (int) ($entry['threshold_ms'] ?? 750),
            'alert_ms' => (int) ($entry['alert_ms'] ?? 2000),
            'query' => (string) ($entry['query'] ?? ''),
            'binds' => self::redactBinds((array) ($entry['binds'] ?? [])),
            'bind_count' => count((array) ($entry['binds'] ?? [])),
            'full_query' => '',
            'request_uri' => self::sanitizeRequestUri((string) ($entry['request_uri'] ?? 'CLI')),
        ];
    }

    /** @param array<int|string, mixed> $binds
     * @return array<int|string, string>
     */
    private static function redactBinds(array $binds): array
    {
        $redacted = [];
        foreach ($binds as $key => $value) {
            $redacted[$key] = is_array($value) ? '[REDACTED_ARRAY]' : '[REDACTED]';
        }

        return $redacted;
    }

    private static function sanitizeRequestUri(string $requestUri): string
    {
        if ($requestUri === '') {
            return 'CLI';
        }

        $requestUri = preg_replace('/[\x00-\x1F\x7F]/', '', $requestUri) ?? '';
        $requestUri = trim($requestUri);

        if ($requestUri === '') {
            return 'CLI';
        }

        if (strlen($requestUri) > 512) {
            $requestUri = substr($requestUri, 0, 512);
        }

        return $requestUri;
    }
}