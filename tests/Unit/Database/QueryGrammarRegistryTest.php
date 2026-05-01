<?php

declare(strict_types=1);

use Core\Database\DriverRegistry;
use Core\Database\Query\Grammars\MariaDBGrammar;
use Core\Database\Query\Grammars\MySQLGrammar;
use PHPUnit\Framework\TestCase;

final class QueryGrammarRegistryTest extends TestCase
{
    public function testRegistryResolvesQueryGrammarForSupportedDrivers(): void
    {
        self::assertInstanceOf(MySQLGrammar::class, DriverRegistry::queryGrammar('mysql'));
        self::assertInstanceOf(MariaDBGrammar::class, DriverRegistry::queryGrammar('mariadb'));
    }
}