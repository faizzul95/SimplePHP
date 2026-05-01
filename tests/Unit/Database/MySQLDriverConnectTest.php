<?php

declare(strict_types=1);

use Core\Database\Drivers\MySQLDriver;
use PHPUnit\Framework\TestCase;

final class MySQLDriverConnectTest extends TestCase
{
    public function testConnectThrowsExceptionInsteadOfExitingWhenConfigurationIsMissing(): void
    {
        $driver = new MySQLDriver();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Configuration for default not found.');

        $driver->connect();
    }
}