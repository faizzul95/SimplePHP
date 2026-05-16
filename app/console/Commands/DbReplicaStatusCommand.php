<?php

namespace App\Console\Commands;

use Core\Console\Command;
use Core\Console\Kernel;

class DbReplicaStatusCommand extends Command
{
    public function name(): string
    {
        return 'db:replica:status';
    }

    public function description(): string
    {
        return 'Display read/write routing configuration for the default database connection [--format=table|json]';
    }

    public function handle(array $args, array $options, Kernel $console): int
    {
        $connection = $this->defaultConnectionConfig();
        $write = array_replace($connection, (array) ($connection['write'] ?? []));
        $reads = $this->normalizeReadPool((array) ($connection['read'] ?? []));
        $payload = [
            'driver' => (string) ($write['driver'] ?? $connection['driver'] ?? 'mysql'),
            'sticky' => (bool) ($connection['sticky'] ?? true),
            'write' => [
                'host' => (string) ($write['host'] ?? ''),
                'port' => (string) ($write['port'] ?? ''),
                'database' => (string) ($write['database'] ?? ''),
                'charset' => (string) ($write['charset'] ?? 'utf8mb4'),
            ],
            'read' => array_map(static function (array $read): array {
                return [
                    'host' => (string) ($read['host'] ?? ''),
                    'port' => (string) ($read['port'] ?? ''),
                    'database' => (string) ($read['database'] ?? ''),
                    'charset' => (string) ($read['charset'] ?? 'utf8mb4'),
                ];
            }, $reads),
            'legacy_named_connections' => [
                'slave' => (array) (config('db.slave.' . $this->environmentKey(), []) ?: []),
            ],
        ];

        $format = strtolower(trim((string) ($options['format'] ?? 'table')));
        if ($format === 'json') {
            $console->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return 0;
        }

        $rows = [
            ['Driver', $payload['driver']],
            ['Sticky reads', $payload['sticky'] ? 'enabled' : 'disabled'],
            ['Write host', (string) $payload['write']['host']],
            ['Write port', (string) $payload['write']['port']],
            ['Write database', (string) $payload['write']['database']],
            ['Read replicas', (string) count($payload['read'])],
        ];

        foreach ($payload['read'] as $index => $read) {
            $rows[] = ['Read #' . ($index + 1), (string) $read['host'] . ':' . (string) $read['port']];
        }

        if ($payload['legacy_named_connections']['slave'] !== []) {
            $rows[] = ['Named slave host', (string) ($payload['legacy_named_connections']['slave']['host'] ?? '')];
            $rows[] = ['Named slave database', (string) ($payload['legacy_named_connections']['slave']['database'] ?? '')];
        }

        $console->newLine();
        $console->info('  Database Read/Write Routing');
        $console->table(['Setting', 'Value'], $rows);
        $console->newLine();

        return 0;
    }

    protected function defaultConnectionConfig(): array
    {
        $config = (array) config('db.default.' . $this->environmentKey(), []);
        if ($config === []) {
            $config = (array) config('db.default.development', []);
        }

        return $config;
    }

    protected function environmentKey(): string
    {
        return defined('ENVIRONMENT') ? (string) ENVIRONMENT : 'development';
    }

    /**
     * @param array<int|string, mixed> $reads
     * @return array<int, array<string, mixed>>
     */
    protected function normalizeReadPool(array $reads): array
    {
        $pool = [];
        foreach ($reads as $key => $value) {
            if (is_array($value)) {
                $pool[] = $value;
                continue;
            }

            if (is_string($key)) {
                return [$reads];
            }
        }

        return $pool;
    }
}