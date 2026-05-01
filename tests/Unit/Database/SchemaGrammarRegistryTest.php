<?php

declare(strict_types=1);

use Core\Database\DriverRegistry;
use Core\Database\Schema\Grammars\MySQLGrammar;
use PHPUnit\Framework\TestCase;

final class SchemaGrammarRegistryTest extends TestCase
{
    public function testRegistryResolvesSchemaGrammarForSupportedDrivers(): void
    {
        self::assertInstanceOf(MySQLGrammar::class, DriverRegistry::schemaGrammar('mysql'));
        self::assertInstanceOf(MySQLGrammar::class, DriverRegistry::schemaGrammar('mariadb'));
    }
}