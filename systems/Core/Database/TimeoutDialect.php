<?php

declare(strict_types=1);

namespace Core\Database;

/**
 * How each database engine spells "give up on this statement".
 *
 * Every engine has the concept and no two agree on the variable name, the unit,
 * or the error code it raises. Hard-coding MySQL's spelling is what left MariaDB
 * with no statement timeout at all while its config said fifteen seconds.
 *
 * Keeping the differences as data means adding PostgreSQL, SQL Server or Oracle
 * later is a new entry here rather than another branch in the connection code.
 */
final class TimeoutDialect
{
    public const UNIT_MILLISECONDS = 'ms';
    public const UNIT_SECONDS = 's';

    /**
     * @param string|null $statementVariable  Session variable bounding a statement, null if unsupported
     * @param string      $statementUnit      UNIT_MILLISECONDS or UNIT_SECONDS
     * @param string|null $lockWaitVariable   Session variable bounding a row-lock wait
     * @param list<int>   $timeoutCodes       Driver codes meaning "statement cancelled on time"
     * @param list<int>   $retryableCodes     Driver codes worth replaying (deadlock, lock wait)
     * @param bool        $statementIsFloat   Whether the value may carry decimals
     */
    public function __construct(
        public readonly ?string $statementVariable,
        public readonly string $statementUnit = self::UNIT_MILLISECONDS,
        public readonly ?string $lockWaitVariable = null,
        public readonly string $lockWaitUnit = self::UNIT_SECONDS,
        public readonly array $timeoutCodes = [],
        public readonly array $retryableCodes = [],
        public readonly bool $statementIsFloat = false,
    ) {
    }

    /**
     * Format a millisecond budget as the value this engine expects.
     *
     * Returns null when the engine has no statement timeout, so the caller can
     * skip the SET rather than send something meaningless.
     */
    public function statementValue(int $milliseconds): ?string
    {
        if ($this->statementVariable === null || $milliseconds <= 0) {
            return null;
        }

        if ($this->statementUnit === self::UNIT_MILLISECONDS) {
            return (string) $milliseconds;
        }

        // Seconds. MariaDB accepts decimals and treats 0 as "no limit", which is
        // the opposite of a configured timeout — so never round down to zero.
        $seconds = max($this->statementIsFloat ? 0.001 : 1, $milliseconds / 1000);

        return $this->statementIsFloat
            ? rtrim(rtrim(number_format($seconds, 3, '.', ''), '0'), '.')
            : (string) (int) ceil($seconds);
    }

    public function lockWaitValue(int $seconds): ?string
    {
        if ($this->lockWaitVariable === null || $seconds <= 0) {
            return null;
        }

        return $this->lockWaitUnit === self::UNIT_MILLISECONDS
            ? (string) ($seconds * 1000)
            : (string) $seconds;
    }

    public function isTimeoutCode(int $driverCode): bool
    {
        return in_array($driverCode, $this->timeoutCodes, true);
    }

    public function isRetryableCode(int $driverCode): bool
    {
        return in_array($driverCode, $this->retryableCodes, true);
    }

    /**
     * Resolve the dialect for a PDO driver name plus its server banner.
     *
     * MariaDB reports itself as the `mysql` PDO driver, so the banner is the only
     * way to tell the two apart — and they need different variables.
     */
    public static function for(string $pdoDriver, string $serverVersion = ''): self
    {
        $pdoDriver = strtolower(trim($pdoDriver));

        if ($pdoDriver === 'mysql' && stripos($serverVersion, 'mariadb') !== false) {
            return self::mariadb();
        }

        return match ($pdoDriver) {
            'mysql' => self::mysql(),
            'mariadb' => self::mariadb(),
            'pgsql' => self::postgres(),
            'sqlsrv', 'dblib', 'mssql' => self::sqlServer(),
            'oci', 'oracle' => self::oracle(),
            'sqlite' => self::sqlite(),
            default => self::unsupported(),
        };
    }

    /** MySQL 5.7.8+: milliseconds, and only bounds read-only SELECTs. */
    public static function mysql(): self
    {
        return new self(
            statementVariable: 'max_execution_time',
            statementUnit: self::UNIT_MILLISECONDS,
            lockWaitVariable: 'innodb_lock_wait_timeout',
            lockWaitUnit: self::UNIT_SECONDS,
            timeoutCodes: [3024],
            retryableCodes: [1213, 1205, 1479, 1614, 3058, 3572, 2006, 2013],
        );
    }

    /** MariaDB 10.1+: seconds as a float, under a different name entirely. */
    public static function mariadb(): self
    {
        return new self(
            statementVariable: 'max_statement_time',
            statementUnit: self::UNIT_SECONDS,
            lockWaitVariable: 'innodb_lock_wait_timeout',
            lockWaitUnit: self::UNIT_SECONDS,
            timeoutCodes: [1969],
            retryableCodes: [1213, 1205, 1479, 1614, 3058, 2006, 2013],
            statementIsFloat: true,
        );
    }

    /** PostgreSQL: milliseconds, and unlike MySQL it bounds writes too. */
    public static function postgres(): self
    {
        return new self(
            statementVariable: 'statement_timeout',
            statementUnit: self::UNIT_MILLISECONDS,
            lockWaitVariable: 'lock_timeout',
            lockWaitUnit: self::UNIT_MILLISECONDS,
            // PostgreSQL reports through SQLSTATE (57014 query_canceled,
            // 40P01 deadlock_detected); driver codes are not stable, so the
            // SQLSTATE check in BaseDatabase carries this engine.
            timeoutCodes: [],
            retryableCodes: [],
        );
    }

    /**
     * SQL Server has no session statement timeout — LOCK_TIMEOUT bounds lock waits
     * only, and query duration is capped client-side via PDO::ATTR_TIMEOUT.
     */
    public static function sqlServer(): self
    {
        return new self(
            statementVariable: null,
            lockWaitVariable: 'LOCK_TIMEOUT',
            lockWaitUnit: self::UNIT_MILLISECONDS,
            timeoutCodes: [-2],
            retryableCodes: [1205],
        );
    }

    /**
     * Oracle enforces limits through Resource Manager rather than a session
     * variable, so neither is set here.
     */
    public static function oracle(): self
    {
        return new self(
            statementVariable: null,
            lockWaitVariable: null,
            timeoutCodes: [1013],
            retryableCodes: [60, 104, 4020],
        );
    }

    /** SQLite has no server to time out; busy_timeout is a connection attribute. */
    public static function sqlite(): self
    {
        return new self(statementVariable: null, lockWaitVariable: null, retryableCodes: [5, 6]);
    }

    public static function unsupported(): self
    {
        return new self(statementVariable: null, lockWaitVariable: null);
    }
}
