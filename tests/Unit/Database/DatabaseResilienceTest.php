<?php

declare(strict_types=1);

use Core\Database\BaseDatabase;
use PHPUnit\Framework\TestCase;

final class DatabaseResilienceProbe extends BaseDatabase
{
    public function __construct()
    {
    }

    public function exposeSlowQueryConfiguration(): array
    {
        return $this->slowQueryConfiguration();
    }

    public function exposeRetryConfiguration(): array
    {
        return $this->retryConfiguration();
    }

    public function exposeStatementTimeoutConfiguration(): array
    {
        return $this->statementTimeoutConfiguration();
    }

    public function exposeShouldRetryThrowable(\Throwable $throwable): bool
    {
        return $this->shouldRetryThrowable($throwable);
    }

    public function exposeExecuteWithRetry(callable $operation)
    {
        return $this->executeWithRetry($operation);
    }

    public function connect($connectionID = null) { return $this; }
    public function whereDate($column, $operator = null, $value = null) { return $this; }
    public function orWhereDate($column, $operator = null, $value = null) { return $this; }
    public function whereDay($column, $operator = null, $value = null) { return $this; }
    public function orWhereDay($column, $operator = null, $value = null) { return $this; }
    public function whereMonth($column, $operator = null, $value = null) { return $this; }
    public function orWhereMonth($column, $operator = null, $value = null) { return $this; }
    public function whereYear($column, $operator = null, $value = null) { return $this; }
    public function orWhereYear($column, $operator = null, $value = null) { return $this; }
    public function whereTime($column, $operator = null, $value = null) { return $this; }
    public function orWhereTime($column, $operator = null, $value = null) { return $this; }
    public function whereJsonContains($columnName, $jsonPath, $value) { return $this; }
    public function limit($limit) { return $this; }
    public function offset($offset) { return $this; }
    public function count($table = null) { return 0; }
    public function exists($table = null) { return false; }
    public function _getLimitOffsetPaginate($query, $limit, $offset) { return $query; }
    public function batchInsert($data) { return []; }
    public function batchUpdate($data) { return []; }
    public function upsert($values, $uniqueBy = 'id', $updateColumns = null) { return []; }
    protected function sanitizeColumn($data): array { return is_array($data) ? $data : []; }
}

final class DatabaseResilienceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['config']['db']['performance']['slow_query'] = [
            'enabled' => true,
            'threshold_ms' => 750,
        ];
        $GLOBALS['config']['db']['performance']['timeouts'] = [
            'enabled' => true,
            'statement_timeout_ms' => 15000,
            'lock_wait_timeout_seconds' => 15,
        ];
        $GLOBALS['config']['db']['retry'] = [
            'enabled' => true,
            'attempts' => 3,
            'delay_ms' => 0,
        ];
    }

    public function testSlowQueryConfigurationUsesConfiguredThreshold(): void
    {
        $probe = new DatabaseResilienceProbe();

        self::assertSame(['enabled' => true, 'threshold_ms' => 750], $probe->exposeSlowQueryConfiguration());
    }

    public function testShouldRetryThrowableDetectsDeadlockAndLockTimeoutErrors(): void
    {
        $probe = new DatabaseResilienceProbe();

        $deadlock = new PDOException('Deadlock found when trying to get lock');
        $deadlock->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock'];

        $timeout = new PDOException('Lock wait timeout exceeded; try restarting transaction');
        $timeout->errorInfo = ['HY000', 1205, 'Lock wait timeout exceeded'];

        self::assertTrue($probe->exposeShouldRetryThrowable($deadlock));
        self::assertTrue($probe->exposeShouldRetryThrowable($timeout));
        self::assertFalse($probe->exposeShouldRetryThrowable(new RuntimeException('plain failure')));
    }

    public function testStatementTimeoutConfigurationUsesConfiguredValues(): void
    {
        $probe = new DatabaseResilienceProbe();

        self::assertSame(
            [
                'enabled' => true,
                'statement_timeout_ms' => 15000,
                'lock_wait_timeout_seconds' => 15,
            ],
            $probe->exposeStatementTimeoutConfiguration()
        );
    }

    public function testExecuteWithRetryRetriesTransientFailuresUntilSuccess(): void
    {
        $probe = new DatabaseResilienceProbe();
        $attempts = 0;

        $result = $probe->exposeExecuteWithRetry(function () use (&$attempts) {
            $attempts++;
            if ($attempts < 3) {
                $exception = new PDOException('Deadlock found when trying to get lock');
                $exception->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock'];
                throw $exception;
            }

            return 'ok';
        });

        self::assertSame('ok', $result);
        self::assertSame(3, $attempts);
    }
}