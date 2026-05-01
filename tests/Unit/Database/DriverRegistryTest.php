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

        self::assertSame(['mariadb', 'mysql'], array_values(array_unique(array_map('strval', DriverRegistry::all()))));
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
}