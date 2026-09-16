<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use Core\Database\Dsn;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The pool built one DSN shape — `driver:host=…;dbname=…` — which is right for
 * MySQL and PostgreSQL and wrong for everything else. SQL Server separates its
 * port with a comma rather than a key; Oracle takes an Easy Connect descriptor
 * and does not use `dbname` for the host at all.
 *
 * It also validated every database name against `^[A-Za-z0-9_-]+$`, which
 * rejects the ordinary Oracle service name `orclpdb1.localdomain` and the SQL
 * Server instance `HOST\SQLEXPRESS`.
 */
final class DsnTest extends TestCase
{
    // ─── Driver mapping ──────────────────────────────────────────────

    /** MariaDB has no PDO driver of its own; it speaks the MySQL protocol. */
    public function testMariadbMapsToTheMysqlPdoDriver(): void
    {
        self::assertSame('mysql', Dsn::pdoDriver('mariadb'));
    }

    public function testFriendlyEngineNamesMapToPdoDriverNames(): void
    {
        self::assertSame('sqlsrv', Dsn::pdoDriver('mssql'));
        self::assertSame('oci', Dsn::pdoDriver('oracle'));
        self::assertSame('pgsql', Dsn::pdoDriver('pgsql'));
    }

    // ─── MySQL ───────────────────────────────────────────────────────

    public function testMysqlBuildsTheExpectedDsn(): void
    {
        $dsn = Dsn::build([
            'driver' => 'mysql',
            'host' => 'db.internal',
            'database' => 'shop',
            'charset' => 'utf8mb4',
            'port' => 3307,
        ]);

        self::assertSame('mysql:host=db.internal;dbname=shop;charset=utf8mb4;port=3307', $dsn);
    }

    public function testMariadbUsesTheMysqlDsn(): void
    {
        self::assertStringStartsWith('mysql:', Dsn::build(['driver' => 'mariadb', 'database' => 'shop']));
    }

    public function testAUnixSocketIsAppendedWhenGiven(): void
    {
        $dsn = Dsn::build(['driver' => 'mysql', 'database' => 'shop', 'socket' => '/var/run/mysqld/mysqld.sock']);

        self::assertStringContainsString('unix_socket=/var/run/mysqld/mysqld.sock', $dsn);
    }

    // ─── PostgreSQL ──────────────────────────────────────────────────

    public function testPostgresBuildsTheExpectedDsn(): void
    {
        $dsn = Dsn::build(['driver' => 'pgsql', 'host' => 'pg.internal', 'database' => 'shop', 'port' => 5432]);

        self::assertSame('pgsql:host=pg.internal;dbname=shop;port=5432', $dsn);
    }

    /** TLS is negotiated per connection on PostgreSQL, so the mode goes in the DSN. */
    public function testPostgresCarriesTheSslMode(): void
    {
        $dsn = Dsn::build(['driver' => 'pgsql', 'database' => 'shop', 'sslmode' => 'verify-full']);

        self::assertStringContainsString(';sslmode=verify-full', $dsn);
    }

    public function testAnUnknownSslModeIsDropped(): void
    {
        $dsn = Dsn::build(['driver' => 'pgsql', 'database' => 'shop', 'sslmode' => 'whatever']);

        self::assertStringNotContainsString('sslmode', $dsn);
    }

    // ─── SQL Server ──────────────────────────────────────────────────

    /** A comma, not a `port=` key — the MySQL shape simply does not connect. */
    public function testSqlServerSeparatesThePortWithAComma(): void
    {
        $dsn = Dsn::build(['driver' => 'sqlsrv', 'host' => 'sql.internal', 'database' => 'shop', 'port' => 1433]);

        self::assertSame('sqlsrv:Server=sql.internal,1433;Database=shop', $dsn);
    }

    public function testASqlServerNamedInstanceIsAccepted(): void
    {
        $dsn = Dsn::build(['driver' => 'mssql', 'host' => 'WINHOST\\SQLEXPRESS', 'database' => 'shop']);

        self::assertStringContainsString('Server=WINHOST\\SQLEXPRESS', $dsn);
    }

    public function testSqlServerEncryptionFlagsAreEmittedWhenSet(): void
    {
        $dsn = Dsn::build([
            'driver' => 'sqlsrv',
            'database' => 'shop',
            'encrypt' => true,
            'trust_server_certificate' => false,
        ]);

        self::assertStringContainsString(';Encrypt=1', $dsn);
        self::assertStringContainsString(';TrustServerCertificate=0', $dsn);
    }

    // ─── Oracle ──────────────────────────────────────────────────────

    public function testOracleBuildsAnEasyConnectDescriptor(): void
    {
        $dsn = Dsn::build(['driver' => 'oci', 'host' => 'ora.internal', 'database' => 'ORCLPDB1', 'port' => 1521]);

        self::assertSame('oci:dbname=//ora.internal:1521/ORCLPDB1', $dsn);
    }

    public function testOracleDefaultsToItsUsualPort(): void
    {
        $dsn = Dsn::build(['driver' => 'oracle', 'host' => 'ora.internal', 'database' => 'ORCLPDB1']);

        self::assertStringContainsString(':1521/', $dsn);
    }

    /**
     * The old identifier pattern rejected this outright, so an ordinary Oracle
     * service name could not be configured at all.
     */
    public function testAnOracleServiceNameMayContainDots(): void
    {
        $dsn = Dsn::build(['driver' => 'oci', 'host' => 'ora.internal', 'database' => 'orclpdb1.localdomain']);

        self::assertStringEndsWith('/orclpdb1.localdomain', $dsn);
    }

    /** Oracle does not transcode on its own; without this, UTF-8 comes back mangled. */
    public function testOracleCarriesTheCharset(): void
    {
        $dsn = Dsn::build(['driver' => 'oci', 'database' => 'ORCLPDB1', 'charset' => 'AL32UTF8']);

        self::assertStringContainsString(';charset=AL32UTF8', $dsn);
    }

    // ─── SQLite ──────────────────────────────────────────────────────

    public function testSqliteTakesAPath(): void
    {
        self::assertSame('sqlite:/var/data/app.sqlite', Dsn::build([
            'driver' => 'sqlite',
            'database' => '/var/data/app.sqlite',
        ]));
    }

    /** A path is not an identifier, so it must not be run through that pattern. */
    public function testSqliteAcceptsAnInMemoryDatabase(): void
    {
        self::assertSame('sqlite::memory:', Dsn::build(['driver' => 'sqlite', 'database' => ':memory:']));
        self::assertSame('sqlite::memory:', Dsn::build(['driver' => 'sqlite', 'database' => '']));
    }

    // ─── Injection into the DSN ──────────────────────────────────────

    /** @return array<string, array{0:array<string, mixed>}> */
    public static function unsafeConfigProvider(): array
    {
        return [
            'semicolon in host' => [['driver' => 'mysql', 'host' => 'db;Uid=root', 'database' => 'shop']],
            'semicolon in database' => [['driver' => 'mysql', 'host' => 'db', 'database' => 'shop;Uid=root']],
            'space in host' => [['driver' => 'mysql', 'host' => 'db internal', 'database' => 'shop']],
            'newline in database' => [['driver' => 'mysql', 'host' => 'db', 'database' => "shop\nUid=root"]],
            'equals in database' => [['driver' => 'pgsql', 'host' => 'db', 'database' => 'shop=x']],
        ];
    }

    /**
     * Every value is interpolated into the connection string, so a semicolon in
     * a host appends an attacker-chosen DSN parameter — `Uid=` among them.
     *
     * @param array<string, mixed> $config
     */
    #[DataProvider('unsafeConfigProvider')]
    public function testAValueThatCouldExtendTheDsnIsRejected(array $config): void
    {
        $this->expectException(InvalidArgumentException::class);

        Dsn::build($config);
    }

    public function testAMissingDatabaseNameIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing database name');

        Dsn::build(['driver' => 'mysql', 'host' => 'db']);
    }

    /** @return array<string, array{0:mixed}> */
    public static function badPortProvider(): array
    {
        return ['zero' => [0], 'negative' => [-1], 'too large' => [70000], 'not a number' => ['abc']];
    }

    #[DataProvider('badPortProvider')]
    public function testAnOutOfRangePortIsRejected(mixed $port): void
    {
        $this->expectException(InvalidArgumentException::class);

        Dsn::build(['driver' => 'mysql', 'host' => 'db', 'database' => 'shop', 'port' => $port]);
    }

    public function testAnUnknownDriverIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported database driver');

        Dsn::build(['driver' => 'firebird', 'database' => 'shop']);
    }

    /** Every engine the pool claims to support must actually build a DSN. */
    public function testEverySupportedDriverBuildsSomething(): void
    {
        foreach (Dsn::SUPPORTED as $driver) {
            $dsn = Dsn::build(['driver' => $driver, 'host' => 'db.internal', 'database' => 'shop']);

            self::assertNotSame('', $dsn, $driver . ' produced no DSN.');
            self::assertStringStartsWith(Dsn::pdoDriver($driver) . ':', $dsn);
        }
    }
}
