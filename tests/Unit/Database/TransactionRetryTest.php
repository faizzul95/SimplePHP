<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use PDOException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use Tests\Fixtures\Database\FakeQueryBuilder;

/**
 * transaction() had no retry and no nesting. Deadlock (1213) and lock-wait timeout
 * (1205) are routine on bulk writes across related tables and surfaced as failed
 * jobs, and a nested call threw outright because beginTransaction() cannot nest.
 */
final class TransactionRetryTest extends TestCase
{
    private function builder(bool $alreadyInTransaction = false)
    {
        return new class ($alreadyInTransaction) extends FakeQueryBuilder {
            public int $begins = 0;
            public int $commits = 0;
            public int $rollbacks = 0;
            /** @var list<string> */
            /** @var list<string> */
            public array $savepoints = [];

            public function __construct(private bool $open)
            {
                parent::__construct('users');
            }

            public function inTransaction(): bool
            {
                return $this->open;
            }

            protected function executeSavepointStatement(string $sql): void
            {
                $this->savepoints[] = $sql;
            }

            public function pdoStatements(): array
            {
                return $this->savepoints;
            }

            public function beginTransaction()
            {
                $this->begins++;
            }

            public function commit()
            {
                $this->commits++;
            }

            public function rollback()
            {
                $this->rollbacks++;
            }
        };
    }

    private function deadlock(): PDOException
    {
        $e = new PDOException('Deadlock found when trying to get lock');
        $e->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock'];

        return $e;
    }

    private function lockWaitTimeout(): PDOException
    {
        $e = new PDOException('Lock wait timeout exceeded');
        $e->errorInfo = ['HY000', 1205, 'Lock wait timeout exceeded'];

        return $e;
    }

    public function testASuccessfulCallbackCommitsOnce(): void
    {
        $db = $this->builder();
        $result = $db->transaction(static fn() => 'done');

        self::assertSame('done', $result);
        self::assertSame(1, $db->begins);
        self::assertSame(1, $db->commits);
        self::assertSame(0, $db->rollbacks);
    }

    public function testADeadlockIsRetriedUpToTheAttemptLimit(): void
    {
        $db = $this->builder();
        $calls = 0;

        $result = $db->transaction(function () use (&$calls) {
            if (++$calls < 3) {
                throw $this->deadlock();
            }

            return 'eventually';
        }, 3);

        self::assertSame('eventually', $result);
        self::assertSame(3, $calls);
        self::assertSame(3, $db->begins);
        self::assertSame(2, $db->rollbacks);
        self::assertSame(1, $db->commits);
    }

    public function testALockWaitTimeoutIsAlsoRetried(): void
    {
        $db = $this->builder();
        $calls = 0;

        $db->transaction(function () use (&$calls) {
            if (++$calls < 2) {
                throw $this->lockWaitTimeout();
            }

            return true;
        }, 2);

        self::assertSame(2, $calls);
    }

    public function testTheErrorEscapesOnceAttemptsAreExhausted(): void
    {
        $db = $this->builder();

        $this->expectException(PDOException::class);

        try {
            $db->transaction(fn() => throw $this->deadlock(), 2);
        } finally {
            self::assertSame(2, $db->begins);
            self::assertSame(2, $db->rollbacks);
            self::assertSame(0, $db->commits);
        }
    }

    public function testANonRetryableErrorIsNotRetried(): void
    {
        $db = $this->builder();
        $calls = 0;

        try {
            $db->transaction(function () use (&$calls) {
                $calls++;
                throw new RuntimeException('business rule violated');
            }, 5);
            self::fail('Expected the exception to propagate.');
        } catch (RuntimeException) {
            self::assertSame(1, $calls, 'A business failure must not be replayed.');
            self::assertSame(1, $db->rollbacks);
        }
    }

    public function testDefaultBehaviourIsASingleAttempt(): void
    {
        $db = $this->builder();
        $calls = 0;

        try {
            $db->transaction(function () use (&$calls) {
                $calls++;
                throw $this->deadlock();
            });
        } catch (PDOException) {
            // expected
        }

        self::assertSame(1, $calls, 'Retrying by default would replay writes nobody asked to replay.');
    }

    public function testANestedCallUsesASavepointInsteadOfThrowing(): void
    {
        $db = $this->builder(alreadyInTransaction: true);

        $result = $db->transaction(static fn() => 'inner');

        self::assertSame('inner', $result);
        self::assertSame(0, $db->begins, 'A nested call must not open a second transaction.');
        self::assertSame(['SAVEPOINT sp_0', 'RELEASE SAVEPOINT sp_0'], $db->pdoStatements());
    }

    public function testANestedFailureRollsBackOnlyToItsSavepoint(): void
    {
        $db = $this->builder(alreadyInTransaction: true);

        try {
            $db->transaction(static fn() => throw new RuntimeException('inner failed'));
            self::fail('Expected the inner exception to propagate.');
        } catch (RuntimeException) {
            self::assertSame(
                ['SAVEPOINT sp_0', 'ROLLBACK TO SAVEPOINT sp_0'],
                $db->pdoStatements()
            );
            self::assertSame(0, $db->rollbacks, 'The outer transaction must survive.');
        }
    }

    public function testSavepointNamesDoNotCollideWhenNestedTwice(): void
    {
        $db = $this->builder(alreadyInTransaction: true);

        $db->transaction(static fn() => $db->transaction(static fn() => 'deep'));

        self::assertSame(
            ['SAVEPOINT sp_0', 'SAVEPOINT sp_1', 'RELEASE SAVEPOINT sp_1', 'RELEASE SAVEPOINT sp_0'],
            $db->pdoStatements()
        );
    }

    public function testOnlyDeadlockCodesCountAsRetryable(): void
    {
        $method = new ReflectionMethod(\Core\Database\BaseDatabase::class, 'isRetryableTransactionError');
        $db = $this->builder();

        self::assertTrue($method->invoke($db, $this->deadlock()));
        self::assertTrue($method->invoke($db, $this->lockWaitTimeout()));
        self::assertFalse($method->invoke($db, new RuntimeException('nope')));

        $other = new PDOException('duplicate entry');
        $other->errorInfo = ['23000', 1062, 'Duplicate entry'];
        self::assertFalse($method->invoke($db, $other), 'A duplicate key is not a transient failure.');
    }
}
