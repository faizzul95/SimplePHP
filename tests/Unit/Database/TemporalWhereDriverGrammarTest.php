<?php

declare(strict_types=1);

use Core\Database\Drivers\MariaDBDriver;
use Core\Database\Drivers\MySQLDriver;
use PHPUnit\Framework\TestCase;

final class TemporalWhereMySQLProbe extends MySQLDriver
{
    public function inspectWhere(): string
    {
        return (string) $this->where;
    }

    public function inspectBinds(): array
    {
        return $this->_binds;
    }
}

final class TemporalWhereMariaDBProbe extends MariaDBDriver
{
    public function __construct()
    {
        $this->driver = 'mariadb';
    }

    public function inspectWhere(): string
    {
        return (string) $this->where;
    }

    public function inspectBinds(): array
    {
        return $this->_binds;
    }
}

final class TemporalWhereDriverGrammarTest extends TestCase
{
    public function testMySqlDriverBuildsDateClauseThroughQueryGrammar(): void
    {
        $driver = new TemporalWhereMySQLProbe();
        $driver->whereDate('created_at', '2026-04-19');

        self::assertSame('DATE(`created_at`) = ?', $driver->inspectWhere());
        self::assertSame(['2026-04-19'], $driver->inspectBinds());
    }

    public function testMariaDbDriverBuildsTimeClauseThroughQueryGrammar(): void
    {
        $driver = new TemporalWhereMariaDBProbe();
        $driver->whereTime('created_at', '12:30:45');

        self::assertSame("DATE_FORMAT(`created_at`, '%H:%i:%s') = ?", $driver->inspectWhere());
        self::assertSame(['12:30:45'], $driver->inspectBinds());
    }
}