<?php

declare(strict_types=1);

namespace Core\Security;

final class CspViolationLogger
{
    private const LOG_FILE = ROOT_DIR . 'storage/logs/csp-violations.log';

    public static function logFilePath(): string
    {
        return self::LOG_FILE;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function record(string $rawBody, array $server = []): ?array
    {
        $report = self::parse($rawBody);
        if ($report === null) {
            return null;
        }

        $entry = [
            'ts' => date('c'),
            'ip' => $server['REMOTE_ADDR'] ?? '0.0.0.0',
            'user_agent' => substr((string) ($server['HTTP_USER_AGENT'] ?? ''), 0, 512),
            'document_uri' => $report['document_uri'],
            'violated_directive' => $report['violated_directive'],
            'effective_directive' => $report['effective_directive'],
            'blocked_uri' => $report['blocked_uri'],
            'source_file' => $report['source_file'],
            'line_number' => $report['line_number'],
            'column_number' => $report['column_number'],
            'status_code' => $report['status_code'],
            'sample' => $report['sample'],
            'original_policy' => $report['original_policy'],
        ];

        self::writeFile($entry);
        self::writeDatabase($entry);

        AuditLogger::cspViolation([
            'document_uri' => $entry['document_uri'],
            'violated_directive' => $entry['violated_directive'],
            'effective_directive' => $entry['effective_directive'],
            'blocked_uri' => $entry['blocked_uri'],
            'status_code' => $entry['status_code'],
        ]);

        return $entry;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function parse(string $rawBody): ?array
    {
        $decoded = json_decode($rawBody, true);
        if (!is_array($decoded)) {
            return null;
        }

        $report = $decoded['csp-report'] ?? $decoded['body'] ?? $decoded;
        if (!is_array($report)) {
            return null;
        }

        return [
            'document_uri' => self::limit((string) ($report['document-uri'] ?? $report['documentURI'] ?? ''), 2048),
            'violated_directive' => self::limit((string) ($report['violated-directive'] ?? $report['effective-directive'] ?? ''), 255),
            'effective_directive' => self::limit((string) ($report['effective-directive'] ?? ''), 255),
            'blocked_uri' => self::limit((string) ($report['blocked-uri'] ?? ''), 2048),
            'source_file' => self::limit((string) ($report['source-file'] ?? ''), 2048),
            'line_number' => max(0, (int) ($report['line-number'] ?? 0)),
            'column_number' => max(0, (int) ($report['column-number'] ?? 0)),
            'status_code' => max(0, (int) ($report['status-code'] ?? 0)),
            'sample' => self::limit((string) ($report['script-sample'] ?? ''), 1000),
            'original_policy' => self::limit((string) ($report['original-policy'] ?? ''), 4000),
        ];
    }

    private static function writeFile(array $entry): void
    {
        $dir = dirname(self::LOG_FILE);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        @file_put_contents(
            self::LOG_FILE,
            json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }

    private static function writeDatabase(array $entry): void
    {
        try {
            db()->table('csp_violations')->insert([
                'document_uri' => $entry['document_uri'],
                'violated_directive' => $entry['violated_directive'],
                'effective_directive' => $entry['effective_directive'],
                'blocked_uri' => $entry['blocked_uri'],
                'source_file' => $entry['source_file'],
                'line_number' => $entry['line_number'],
                'column_number' => $entry['column_number'],
                'status_code' => $entry['status_code'],
                'sample' => $entry['sample'],
                'original_policy' => $entry['original_policy'],
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable) {
            // Best-effort only; file log remains authoritative when the table is absent.
        }
    }

    private static function limit(string $value, int $max): string
    {
        return substr(trim($value), 0, $max);
    }
}