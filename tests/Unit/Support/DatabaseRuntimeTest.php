<?php

declare(strict_types=1);

use App\Support\DatabaseRuntime;
use PHPUnit\Framework\TestCase;

final class DatabaseRuntimeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        reset_framework_service();
    }

    public function testDatabaseRuntimeBuildsEnvironmentScopedRegistry(): void
    {
        $runtime = new DatabaseRuntime([
            'environment' => 'development',
            'db' => [
                'cache' => ['enabled' => false],
                'default' => [
                    'development' => [
                        'driver' => 'mysql',
                        'database' => 'resi_emas',
                    ],
                ],
                'analytics' => [
                    'development' => [
                        'driver' => 'mariadb',
                        'database' => 'analytics_db',
                    ],
                ],
            ],
        ]);

        self::assertSame('mysql', $runtime->connectionConfig('default')['driver']);
        self::assertSame('analytics_db', $runtime->connectionConfig('analytics')['config']['database']);
    }

    public function testDatabaseRuntimeRejectsUnknownEnvironment(): void
    {
        $runtime = new DatabaseRuntime([
            'environment' => 'sandbox',
            'db' => [
                'default' => [
                    'development' => [
                        'driver' => 'mysql',
                        'database' => 'resi_emas',
                    ],
                ],
            ],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Environment 'sandbox' is not recognized. Please check your configuration.");

        $runtime->connectionConfig('default');
    }

    public function testDbHelperDelegatesToManagedDatabaseRuntime(): void
    {
        register_framework_service('database.runtime', fn() => new class {
            public function connection(string $connectionName = 'default'): string
            {
                return 'connection:' . $connectionName;
            }
        });

        require_once ROOT_DIR . 'systems/app.php';

        self::assertSame('connection:analytics', db('analytics'));
        self::assertSame('connection:default', db(''));
    }
}