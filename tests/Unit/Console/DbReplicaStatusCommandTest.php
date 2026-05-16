<?php

declare(strict_types=1);

use Core\Console\Kernel;
use PHPUnit\Framework\TestCase;

final class DbReplicaStatusCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('ENVIRONMENT')) {
            define('ENVIRONMENT', 'testing');
        }

        bootstrapTestFrameworkServices([
            'db' => [
                'default' => [
                    'development' => [
                        'driver' => 'mysql',
                        'host' => 'write-db',
                        'port' => '3306',
                        'database' => 'app_db',
                        'charset' => 'utf8mb4',
                        'write' => [
                            'host' => 'write-db',
                            'port' => '3306',
                            'database' => 'app_db',
                            'charset' => 'utf8mb4',
                        ],
                        'read' => [
                            [
                                'host' => 'read-a',
                                'port' => '3306',
                                'database' => 'app_db',
                                'charset' => 'utf8mb4',
                            ],
                            [
                                'host' => 'read-b',
                                'port' => '3307',
                                'database' => 'app_db',
                                'charset' => 'utf8mb4',
                            ],
                        ],
                        'sticky' => true,
                    ],
                ],
                'slave' => [
                    'testing' => [
                        'host' => 'analytics-db',
                        'database' => 'analytics_db',
                    ],
                ],
            ],
        ]);
    }

    public function testReplicaStatusCommandOutputsJsonSummary(): void
    {
        $kernel = new Kernel();
        $exitCode = $kernel->callSilently('db:replica:status', [
            'format' => 'json',
        ]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('"sticky": true', $kernel->output());
        self::assertStringContainsString('"host": "write-db"', $kernel->output());
        self::assertStringContainsString('"host": "read-a"', $kernel->output());
        self::assertStringContainsString('"host": "analytics-db"', $kernel->output());
    }
}