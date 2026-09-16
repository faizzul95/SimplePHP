<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use Core\Database\TimeoutDialect;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every engine has a statement timeout and no two agree on the variable name, the
 * unit, or the error code. Hard-coding MySQL's spelling left MariaDB with no
 * statement timeout at all while its config claimed fifteen seconds.
 *
 * Keeping the differences as data means PostgreSQL, SQL Server and Oracle are new
 * entries rather than new branches in the connection code.
 */
final class TimeoutDialectTest extends TestCase
{
    // ─── Resolution ──────────────────────────────────────────────────

    /** @return array<string, array{0:string,1:string,2:?string}> */
    public static function resolutionProvider(): array
    {
        return [
            'MySQL'                => ['mysql', '8.0.36', 'max_execution_time'],
            'MariaDB via mysql pdo' => ['mysql', '10.11.6-MariaDB', 'max_statement_time'],
            'MariaDB banner'       => ['mysql', '5.5.5-10.6.16-MariaDB-log', 'max_statement_time'],
            'PostgreSQL'           => ['pgsql', '16.2', 'statement_timeout'],
            'SQL Server'           => ['sqlsrv', '15.0', null],
            'Oracle'               => ['oci', '19c', null],
            'SQLite'               => ['sqlite', '3.45', null],
            'unknown'              => ['firebird', '', null],
        ];
    }

    #[DataProvider('resolutionProvider')]
    public function testTheRightVariableIsChosenPerEngine(string $driver, string $version, ?string $expected): void
    {
        self::assertSame($expected, TimeoutDialect::for($driver, $version)->statementVariable);
    }

    /**
     * MariaDB reports itself as the `mysql` PDO driver, so the server banner is
     * the only thing that distinguishes them — and they need different variables.
     */
    public function testMariadbIsNotMistakenForMysql(): void
    {
        $mysql = TimeoutDialect::for('mysql', '8.0.36');
        $mariadb = TimeoutDialect::for('mysql', '10.11.6-MariaDB');

        self::assertNotSame($mysql->statementVariable, $mariadb->statementVariable);
    }

    // ─── Unit conversion ─────────────────────────────────────────────

    public function testMysqlTakesMilliseconds(): void
    {
        self::assertSame('15000', TimeoutDialect::mysql()->statementValue(15000));
    }

    public function testMariadbTakesSecondsAsAFloat(): void
    {
        self::assertSame('15', TimeoutDialect::mariadb()->statementValue(15000));
        self::assertSame('1.5', TimeoutDialect::mariadb()->statementValue(1500));
        self::assertSame('0.25', TimeoutDialect::mariadb()->statementValue(250));
    }

    /**
     * MariaDB reads 0 as "no limit", which is the opposite of a configured
     * timeout — so a sub-millisecond budget must never round down to it.
     */
    public function testATinyBudgetNeverBecomesUnlimitedOnMariadb(): void
    {
        self::assertSame('0.001', TimeoutDialect::mariadb()->statementValue(1));
    }

    public function testPostgresTakesMilliseconds(): void
    {
        self::assertSame('15000', TimeoutDialect::postgres()->statementValue(15000));
    }

    public function testEnginesWithoutAStatementTimeoutReturnNull(): void
    {
        self::assertNull(TimeoutDialect::sqlServer()->statementValue(15000));
        self::assertNull(TimeoutDialect::oracle()->statementValue(15000));
        self::assertNull(TimeoutDialect::sqlite()->statementValue(15000));
    }

    public function testAZeroBudgetMeansNoTimeoutIsSet(): void
    {
        self::assertNull(TimeoutDialect::mysql()->statementValue(0));
        self::assertNull(TimeoutDialect::mariadb()->statementValue(0));
    }

    // ─── Lock wait ───────────────────────────────────────────────────

    public function testLockWaitUnitsDifferPerEngine(): void
    {
        // InnoDB counts seconds; SQL Server counts milliseconds.
        self::assertSame('15', TimeoutDialect::mysql()->lockWaitValue(15));
        self::assertSame('15000', TimeoutDialect::sqlServer()->lockWaitValue(15));
    }

    public function testEnginesWithoutALockWaitVariableReturnNull(): void
    {
        self::assertNull(TimeoutDialect::oracle()->lockWaitValue(15));
        self::assertNull(TimeoutDialect::sqlite()->lockWaitValue(15));
    }

    // ─── Error classification ────────────────────────────────────────

    public function testTimeoutCodesAreRecognisedPerEngine(): void
    {
        self::assertTrue(TimeoutDialect::mysql()->isTimeoutCode(3024));
        self::assertTrue(TimeoutDialect::mariadb()->isTimeoutCode(1969));
        self::assertTrue(TimeoutDialect::oracle()->isTimeoutCode(1013));

        // MySQL's code is not MariaDB's.
        self::assertFalse(TimeoutDialect::mariadb()->isTimeoutCode(3024));
    }

    public function testDeadlockCodesAreRecognisedPerEngine(): void
    {
        self::assertTrue(TimeoutDialect::mysql()->isRetryableCode(1213));
        self::assertTrue(TimeoutDialect::sqlServer()->isRetryableCode(1205));
        self::assertTrue(TimeoutDialect::oracle()->isRetryableCode(60));
    }

    public function testAnUnrelatedCodeIsNotRetryable(): void
    {
        self::assertFalse(TimeoutDialect::mysql()->isRetryableCode(1062)); // duplicate key
        self::assertFalse(TimeoutDialect::mysql()->isTimeoutCode(1062));
    }

    /**
     * PostgreSQL reports through SQLSTATE rather than stable driver codes, so its
     * numeric tables are deliberately empty — BaseDatabase's 57014 / 40001 / 40P01
     * checks carry that engine.
     */
    public function testPostgresLeavesClassificationToSqlstate(): void
    {
        self::assertSame([], TimeoutDialect::postgres()->timeoutCodes);
        self::assertSame([], TimeoutDialect::postgres()->retryableCodes);
    }

    public function testAnUnsupportedEngineSetsNothing(): void
    {
        $dialect = TimeoutDialect::unsupported();

        self::assertNull($dialect->statementVariable);
        self::assertNull($dialect->lockWaitVariable);
        self::assertNull($dialect->statementValue(15000));
    }
}
