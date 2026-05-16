<?php

declare(strict_types=1);

namespace Core\Session;

use SessionHandlerInterface;

final class SessionBootstrapper
{
    /**
     * Normalize session runtime configuration.
     *
     * @return array<string, mixed>
     */
    public static function normalizeConfig(array $config): array
    {
        $session = (array) ($config['session'] ?? []);
        $driver = strtolower(trim((string) ($session['driver'] ?? 'file')));
        if (!in_array($driver, ['file', 'redis'], true)) {
            $driver = 'file';
        }

        $lifetimeMinutes = max(1, (int) ($session['lifetime'] ?? 120));
        $filePath = trim((string) ($session['file_path'] ?? ''));
        if ($filePath !== '' && !self::isAbsolutePath($filePath) && defined('ROOT_DIR')) {
            $filePath = rtrim(ROOT_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $filePath), DIRECTORY_SEPARATOR);
        }

        $redis = (array) ($session['redis'] ?? []);

        return [
            'driver' => $driver,
            'lifetime' => $lifetimeMinutes,
            'fail_open' => ($session['fail_open'] ?? true) === true,
            'file_path' => $filePath,
            'redis' => [
                'host' => (string) ($redis['host'] ?? '127.0.0.1'),
                'port' => (int) ($redis['port'] ?? 6379),
                'password' => $redis['password'] ?? null,
                'database' => (int) ($redis['database'] ?? 2),
                'timeout' => (float) ($redis['timeout'] ?? 2.0),
                'prefix' => (string) ($redis['prefix'] ?? 'myth_session:'),
                'lock_ttl' => max(1, (int) ($redis['lock_ttl'] ?? 10)),
                'lock_wait_ms' => max(0, (int) ($redis['lock_wait_ms'] ?? 150)),
                'lock_retry_us' => max(1000, (int) ($redis['lock_retry_us'] ?? 15000)),
            ],
        ];
    }

    /**
     * Configure PHP session storage before session_start().
     */
    public static function configure(array $config, ?callable $handlerFactory = null): void
    {
        $session = self::normalizeConfig($config);
        ini_set('session.gc_maxlifetime', (string) ($session['lifetime'] * 60));

        if ($session['file_path'] !== '') {
            ini_set('session.save_path', (string) $session['file_path']);
        }

        if ($session['driver'] !== 'redis') {
            return;
        }

        $factory = $handlerFactory ?? static fn(array $runtimeConfig): SessionHandlerInterface => new RedisSessionHandler($runtimeConfig);

        try {
            session_set_save_handler($factory($session), true);
        } catch (\Throwable $e) {
            if (function_exists('logger')) {
                logger()->log_error('Redis session bootstrap failed: ' . $e->getMessage());
            }

            if (($session['fail_open'] ?? true) !== true) {
                throw $e;
            }
        }
    }

    private static function isAbsolutePath(string $path): bool
    {
        return preg_match('~^(?:[A-Za-z]:[\\/]|\\\\|/)~', $path) === 1;
    }
}