<?php

declare(strict_types=1);

use Core\Database\Drivers\MariaDBDriver;
use Core\Database\Drivers\MySQLDriver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class MySQLDriverProfilingProbe extends MySQLDriver
{
    public function seedPdo(\PDO $pdo): void
    {
        $this->pdo['default'] = $pdo;
        $this->connectionName = 'default';
        $this->table = 'users';
        $this->driver = 'mysql';
    }

    public function seedQuery(string $query): void
    {
        $this->_query = $query;
    }

    public function profilerData(): array
    {
        return $this->_profiler;
    }
}

final class MariaDBDriverProfilingProbe extends MariaDBDriver
{
    public function seedPdo(\PDO $pdo): void
    {
        $this->pdo['default'] = $pdo;
        $this->connectionName = 'default';
        $this->table = 'users';
        $this->driver = 'mariadb';
    }

    public function seedQuery(string $query): void
    {
        $this->_query = $query;
    }

    public function profilerData(): array
    {
        return $this->_profiler;
    }
}

final class CountExistsProfilingOptimizationTest extends TestCase
{
    /**
     * @return array<string, array{0: class-string}>
     */
    public static function driverProvider(): array
    {
        return [
            'mysql' => [MySQLDriverProfilingProbe::class],
            'mariadb' => [MariaDBDriverProfilingProbe::class],
        ];
    }

    /**
     * @dataProvider driverProvider
     */
    public function testCountDoesNotPopulateProfilerWhenProfilingIsDisabled(string $driverClass): void
    {
        $statement = $this->mockStatement(['count' => 7]);
        $pdo = $this->mockPdo($statement);

        /** @var MySQLDriverProfilingProbe|MariaDBDriverProfilingProbe $driver */
        $driver = new $driverClass();
        $driver->seedPdo($pdo);
        $driver->seedQuery('SELECT * FROM users WHERE active = 1 ORDER BY id DESC LIMIT 10 OFFSET 0');

        self::assertSame(7, $driver->count());
        self::assertSame([], $driver->profilerData());
    }

    /**
     * @dataProvider driverProvider
     */
    public function testExistsDoesNotPopulateProfilerWhenProfilingIsDisabled(string $driverClass): void
    {
        $statement = $this->mockStatement(['row_exists' => 1]);
        $pdo = $this->mockPdo($statement);

        /** @var MySQLDriverProfilingProbe|MariaDBDriverProfilingProbe $driver */
        $driver = new $driverClass();
        $driver->seedPdo($pdo);
        $driver->seedQuery('SELECT * FROM users WHERE active = 1 ORDER BY id DESC LIMIT 10 OFFSET 0');

        self::assertTrue($driver->exists());
        self::assertSame([], $driver->profilerData());
    }

    /**
     * @param array<string, int> $row
     * @return \PDOStatement&MockObject
     */
    private function mockStatement(array $row): \PDOStatement
    {
        $statement = $this->createMock(\PDOStatement::class);
        $statement->expects(self::once())
            ->method('execute')
            ->willReturn(true);
        $statement->expects(self::once())
            ->method('fetch')
            ->with(\PDO::FETCH_ASSOC)
            ->willReturn($row);

        return $statement;
    }

    /**
     * @return \PDO&MockObject
     */
    private function mockPdo(\PDOStatement $statement): \PDO
    {
        $pdo = $this->createMock(\PDO::class);
        $pdo->expects(self::once())
            ->method('prepare')
            ->with(self::isString())
            ->willReturn($statement);

        return $pdo;
    }
}