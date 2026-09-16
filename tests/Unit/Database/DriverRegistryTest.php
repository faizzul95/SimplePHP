<?php

declare(strict_types=1);

use Core\Database\Database;
use Core\Database\DriverRegistry;
use Core\Database\Query\Grammars\MySQLGrammar as MySQLQueryGrammar;
use Core\Database\Schema\Grammars\MySQLGrammar as MySQLSchemaGrammar;
use PHPUnit\Framework\TestCase;

final class DriverRegistryTest extends TestCase
{
    public function testDatabaseUsesRegistryForSupportedDrivers(): void
    {
        $database = new Database('mysql');

        self::assertSame(
            ['mariadb', 'mysql', 'oci', 'pgsql', 'sqlsrv'],
            array_values(array_unique(array_map('strval', DriverRegistry::all())))
        );
        self::assertTrue($database->capabilities()->supports('upsert'));
        self::assertSame('mysql', $database->capabilities()->driver());
        self::assertInstanceOf(MySQLSchemaGrammar::class, $database->schemaGrammar());
        self::assertInstanceOf(MySQLQueryGrammar::class, $database->queryGrammar());
    }

    public function testDatabaseRejectsUnsupportedDriversThroughRegistry(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported database driver: sqlite');

        new Database('sqlite');
    }

    /**
     * PostgreSQL, SQL Server and Oracle have grammars but no connection driver
     * yet. Connecting must fail, and say which half is missing — silently
     * behaving like MySQL against a PostgreSQL DSN is the worse outcome.
     */
    public function testAGrammarOnlyDriverRefusesToConnectAndSaysWhy(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('has a query grammar but no connection driver yet');

        DriverRegistry::resolveClass('pgsql');
    }

    public function testAGrammarOnlyDriverStillExposesItsGrammar(): void
    {
        self::assertInstanceOf(
            \Core\Database\Query\Grammars\PostgresGrammar::class,
            DriverRegistry::queryGrammar('pgsql')
        );
        self::assertInstanceOf(
            \Core\Database\Query\Grammars\SqlServerGrammar::class,
            DriverRegistry::queryGrammar('sqlsrv')
        );
        self::assertInstanceOf(
            \Core\Database\Query\Grammars\OracleGrammar::class,
            DriverRegistry::queryGrammar('oci')
        );
    }
}