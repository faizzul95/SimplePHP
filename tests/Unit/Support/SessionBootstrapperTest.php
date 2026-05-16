<?php

declare(strict_types=1);

use Core\Session\SessionBootstrapper;
use PHPUnit\Framework\TestCase;

final class SessionBootstrapperTest extends TestCase
{
    public function testNormalizeConfigResolvesRelativeFilePathAndRedisDefaults(): void
    {
        $config = SessionBootstrapper::normalizeConfig([
            'session' => [
                'driver' => 'redis',
                'lifetime' => 45,
                'file_path' => 'storage/framework/sessions',
            ],
        ]);

        self::assertSame('redis', $config['driver']);
        self::assertSame(45, $config['lifetime']);
        self::assertStringEndsWith('storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'sessions', $config['file_path']);
        self::assertSame('127.0.0.1', $config['redis']['host']);
        self::assertSame(2, $config['redis']['database']);
    }

    public function testNormalizeConfigFallsBackToFileForUnknownDrivers(): void
    {
        $config = SessionBootstrapper::normalizeConfig([
            'session' => [
                'driver' => 'database',
                'lifetime' => 0,
                'fail_open' => false,
            ],
        ]);

        self::assertSame('file', $config['driver']);
        self::assertSame(1, $config['lifetime']);
        self::assertFalse($config['fail_open']);
    }
}