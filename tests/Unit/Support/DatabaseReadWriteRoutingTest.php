<?php

declare(strict_types=1);

use App\Support\DatabaseRuntime;
use Core\Database\Drivers\MySQLDriver;
use PHPUnit\Framework\TestCase;

final class DatabaseReadWriteRoutingTest extends TestCase
{
    public function testDatabaseRuntimeBuildsReadWriteAliasesInsideDefaultConnection(): void
    {
        $runtime = new DatabaseRuntime([
            'environment' => 'development',
            'db' => [
                'default' => [
                    'development' => [
                        'driver' => 'mysql',
                        'host' => 'write-db',
                        'database' => 'app_db',
                        'write' => [
                            'host' => 'write-db',
                        ],
                        'read' => [
                            ['host' => 'read-a'],
                            ['host' => 'read-b'],
                        ],
                        'sticky' => true,
                    ],
                ],
                'slave' => [
                    'development' => [
                        'driver' => 'mysql',
                        'host' => 'analytics-db',
                        'database' => 'analytics_db',
                    ],
                ],
            ],
        ]);

        $manager = $runtime->manager('default');
        $ref = new ReflectionClass($manager);
        $configProp = $ref->getProperty('config');
        $configProp->setAccessible(true);
        $configs = $configProp->getValue($manager);

        self::assertArrayHasKey('default', $configs);
        self::assertArrayHasKey('default::write', $configs);
        self::assertArrayHasKey('default::read:1', $configs);
        self::assertArrayHasKey('default::read:2', $configs);
        self::assertSame('write-db', $configs['default::write']['host']);
        self::assertSame('read-a', $configs['default::read:1']['host']);
        self::assertSame('read-b', $configs['default::read:2']['host']);
        self::assertSame('analytics-db', $runtime->connectionConfig('slave')['config']['host']);
    }

    public function testReadRoutingUsesReplicaUntilStickyWriteIsSet(): void
    {
        $driver = new class extends MySQLDriver {
            public function choose(string $operation): string
            {
                return $this->resolveConnectionName($operation);
            }

            public function sticky(string $connectionName = 'default'): void
            {
                $this->markStickyWrite($connectionName);
            }
        };

        $driver->addConnection('default', ['driver' => 'mysql', 'database' => 'app']);
        $driver->addConnection('default::write', ['driver' => 'mysql', 'database' => 'app']);
        $driver->addConnection('default::read:1', ['driver' => 'mysql', 'database' => 'app']);
        $driver->configureReadWriteRouting('default', ['default::read:1'], 'default::write', true);
        $driver->setConnection('default');

        self::assertSame('default::read:1', $driver->choose('read'));
        self::assertSame('default::write', $driver->choose('write'));

        $driver->sticky();

        self::assertSame('default::write', $driver->choose('read'));
    }

    public function testReadOnlyStatementDetectionCoversSelectLikeQueries(): void
    {
        $driver = new class extends MySQLDriver {
            public function detect(?string $statement): bool
            {
                return $this->isReadOnlyStatement($statement);
            }
        };

        self::assertTrue($driver->detect('SELECT * FROM users'));
        self::assertTrue($driver->detect(' show tables'));
        self::assertTrue($driver->detect('EXPLAIN SELECT * FROM users'));
        self::assertFalse($driver->detect('UPDATE users SET active = 1'));
        self::assertFalse($driver->detect(null));
    }
}