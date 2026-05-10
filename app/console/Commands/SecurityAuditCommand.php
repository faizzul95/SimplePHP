<?php

namespace App\Console\Commands;

use App\Support\SecurityAuditRunner;
use Core\Console\Command;
use Core\Console\Kernel;

class SecurityAuditCommand extends Command
{
    public function name(): string
    {
        return 'security:audit';
    }

    public function description(): string
    {
        return 'Run a passive web-app security audit against local config and an optional target URL, including session-authenticated probes';
    }

    public function handle(array $args, array $options, Kernel $console): int
    {
        $environment = defined('ENVIRONMENT') ? (string) ENVIRONMENT : 'production';
        $url = isset($options['url']) ? trim((string) $options['url']) : null;
        $timeout = max(1, (int) ($options['timeout'] ?? 5));
        $format = strtolower(trim((string) ($options['format'] ?? 'table')));
        $authMode = strtolower(trim((string) ($options['auth'] ?? '')));

        if ($authMode === '' && isset($options['username'], $options['password'])) {
            $authMode = 'session';
        }

        $probe = [
            'auth_mode' => $authMode,
            'username' => (string) ($options['username'] ?? ''),
            'password' => (string) ($options['password'] ?? ''),
            'login_url' => (string) ($options['login-url'] ?? ''),
            'login_submit_url' => (string) ($options['login-submit-url'] ?? ''),
        ];

        $runner = new SecurityAuditRunner();
        $report = $runner->run(
            $environment,
            (array) (config('security') ?? []),
            (array) (config('api') ?? []),
            $url,
            $timeout,
            $probe
        );

        if ($format === 'json') {
            $console->line((string) json_encode([
                'environment' => $environment,
                'target' => $url,
                'auth' => $authMode !== '' ? $authMode : null,
                'summary' => $report['summary'],
                'checks' => $report['checks'],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return ((int) ($report['summary']['fail'] ?? 0)) > 0 ? 1 : 0;
        }

        $rows = [];
        foreach ($report['checks'] as $check) {
            $rows[] = [
                strtoupper((string) $check['status']),
                strtoupper((string) $check['severity']),
                (string) $check['id'],
                (string) $check['message'],
            ];
        }

        $console->newLine();
        $console->info('  Security Audit Summary');
        $console->table(
            ['Pass', 'Warn', 'Fail', 'Environment', 'Target', 'Auth'],
            [[
                (string) ($report['summary']['pass'] ?? 0),
                (string) ($report['summary']['warn'] ?? 0),
                (string) ($report['summary']['fail'] ?? 0),
                $environment,
                $url ?? 'config-only',
                $authMode !== '' ? $authMode : 'none',
            ]]
        );
        $console->newLine();
        $console->table(['Status', 'Severity', 'Check', 'Detail'], $rows);
        $console->newLine();

        if (($report['summary']['fail'] ?? 0) > 0) {
            $console->error('Security audit found failing checks.');
            return 1;
        }

        if (($report['summary']['warn'] ?? 0) > 0) {
            $console->warn('Security audit completed with warnings.');
            return 0;
        }

        $console->success('Security audit passed without warnings.');
        return 0;
    }
}