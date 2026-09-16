<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Fixtures\Database\FakeQueryBuilder;

/**
 * Deadlocks are not faults in InnoDB — the engine breaks a wait cycle by rolling
 * one side back and expects a replay. The retry path only recognised two driver
 * codes, never looked past the outermost exception, used a fixed backoff that
 * re-synchronised the very workers it was meant to separate, and let a failing
 * rollback replace the original error.
 *
 */
final class DeadlockResilienceTest extends TestCase
{
    private function builder()
    {
        return new class extends FakeQueryBuilder {
            public int $begins = 0;
            public int $commits = 0;
            public int $rollbacks = 0;
            public bool $rollbackThrows = false;

            /** @var list<int> microsecond delays requested */
            public array $sleeps = [];

            /** @var list<string> */
            public array $warnings = [];

            public function __construct()
            {
                parent::__construct('users');
            }

            public function inTransaction(): bool
            {
                return false;
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

                if ($this->rollbackThrows) {
                    throw new RuntimeException('connection already gone');
                }
            }

            protected function usleepFor(int $microseconds): void
            {
                $this->sleeps[] = $microseconds;
            }

            protected function logTransactionWarning(string $message): void
            {
                $this->warnings[] = $message;
            }

            protected function configuredTransactionAttempts(): int
            {
                return 3;
            }
        };
    }

    private function pdoError(string $sqlState, int $driverCode, string $message = 'db error'): PDOException
    {
        $e = new PDOException($message);
        $e->errorInfo = [$sqlState, $driverCode, $message];

        return $e;
    }

    // ─── Which errors are retryable ──────────────────────────────────

    /** @return array<string, array{0:string,1:int}> */
    public static function retryableProvider(): array
    {
        return [
            'deadlock'                  => ['40001', 1213],
            'lock wait timeout'         => ['HY000', 1205],
            'xa branch rollback'        => ['HY000', 1479],
            'xa deadlock'               => ['HY000', 1614],
            'galera certification'      => ['HY000', 3058],
            'lock nowait'               => ['HY000', 3572],
            'server gone away'          => ['HY000', 2006],
            'server lost'               => ['HY000', 2013],
            'serialization sqlstate'    => ['40001', 0],
        ];
    }

    #[DataProvider('retryableProvider')]
    public function testRetryableErrorsAreReplayed(string $sqlState, int $driverCode): void
    {
        $db = $this->builder();
        $calls = 0;

        $result = $db->retryOnDeadlock(function () use (&$calls, $sqlState, $driverCode) {
            $calls++;

            if ($calls === 1) {
                throw $this->pdoError($sqlState, $driverCode);
            }

            return 'ok';
        });

        self::assertSame('ok', $result);
        self::assertSame(2, $calls);
        self::assertSame(1, $db->rollbacks);
        self::assertSame(1, $db->commits);
    }

    /** @return array<string, array{0:string,1:int}> */
    public static function nonRetryableProvider(): array
    {
        return [
            'duplicate key'      => ['23000', 1062],
            'unknown column'     => ['42S22', 1054],
            'syntax error'       => ['42000', 1064],
            'foreign key'        => ['23000', 1452],
            'access denied'      => ['28000', 1045],
        ];
    }

    #[DataProvider('nonRetryableProvider')]
    public function testNonRetryableErrorsPropagateImmediately(string $sqlState, int $driverCode): void
    {
        $db = $this->builder();
        $calls = 0;

        try {
            $db->retryOnDeadlock(function () use (&$calls, $sqlState, $driverCode) {
                $calls++;
                throw $this->pdoError($sqlState, $driverCode);
            });
            self::fail('Expected the error to propagate.');
        } catch (PDOException) {
            self::assertSame(1, $calls, 'A duplicate key will still be a duplicate key on the next attempt.');
        }
    }

    public function testABusinessExceptionIsNeverReplayed(): void
    {
        $db = $this->builder();
        $calls = 0;

        try {
            $db->retryOnDeadlock(function () use (&$calls) {
                $calls++;
                throw new RuntimeException('insufficient balance');
            });
            self::fail('Expected the error to propagate.');
        } catch (RuntimeException) {
            self::assertSame(1, $calls);
        }
    }

    /**
     * The builder wraps PDO failures in its own exceptions in several code paths,
     * so a check that only inspected the outermost exception never saw the
     * deadlock at all.
     */
    public function testADeadlockWrappedInAnotherExceptionIsStillRecognised(): void
    {
        $db = $this->builder();
        $calls = 0;

        $result = $db->retryOnDeadlock(function () use (&$calls) {
            $calls++;

            if ($calls === 1) {
                throw new RuntimeException(
                    'Query failed',
                    0,
                    $this->pdoError('40001', 1213)
                );
            }

            return 'recovered';
        });

        self::assertSame('recovered', $result);
        self::assertSame(2, $calls);
    }

    public function testADeadlockNestedTwoLevelsDeepIsRecognised(): void
    {
        $db = $this->builder();
        $calls = 0;

        $db->retryOnDeadlock(function () use (&$calls) {
            $calls++;

            if ($calls === 1) {
                throw new RuntimeException(
                    'outer',
                    0,
                    new RuntimeException('middle', 0, $this->pdoError('HY000', 1213))
                );
            }

            return null;
        });

        self::assertSame(2, $calls);
    }

    // ─── Attempt budget ──────────────────────────────────────────────

    public function testTheAttemptBudgetIsRespectedAndThenTheErrorSurfaces(): void
    {
        $db = $this->builder();
        $calls = 0;

        try {
            $db->retryOnDeadlock(function () use (&$calls) {
                $calls++;
                throw $this->pdoError('40001', 1213);
            }, 4);
            self::fail('Expected the deadlock to surface after the budget is spent.');
        } catch (PDOException) {
            self::assertSame(4, $calls);
            self::assertSame(4, $db->rollbacks);
            self::assertSame(0, $db->commits);
        }
    }

    public function testRetryOnDeadlockUsesTheConfiguredDefault(): void
    {
        $db = $this->builder(); // configuredTransactionAttempts() = 3
        $calls = 0;

        try {
            $db->retryOnDeadlock(function () use (&$calls) {
                $calls++;
                throw $this->pdoError('40001', 1213);
            });
        } catch (PDOException) {
            // expected
        }

        self::assertSame(3, $calls);
    }

    public function testPlainTransactionStillDefaultsToOneAttempt(): void
    {
        // Retrying by default would replay whatever the callback did outside the
        // database. Opting in stays the caller's decision.
        $db = $this->builder();
        $calls = 0;

        try {
            $db->transaction(function () use (&$calls) {
                $calls++;
                throw $this->pdoError('40001', 1213);
            });
        } catch (PDOException) {
            // expected
        }

        self::assertSame(1, $calls);
    }

    // ─── Backoff ─────────────────────────────────────────────────────

    public function testBackoffIsJitteredAndBounded(): void
    {
        $db = $this->builder();

        try {
            $db->retryOnDeadlock(fn() => throw $this->pdoError('40001', 1213), 5);
        } catch (PDOException) {
            // expected
        }

        // Four sleeps for five attempts, and none after the last failure.
        self::assertCount(4, $db->sleeps);

        foreach ($db->sleeps as $index => $delay) {
            $capMs = min(50 * (2 ** $index), 2000);

            self::assertGreaterThanOrEqual(0, $delay);
            self::assertLessThanOrEqual(
                $capMs * 1000,
                $delay,
                'Full jitter draws from [0, cap]; a fixed delay would re-synchronise the workers it is meant to separate.'
            );
        }
    }

    public function testTheBackoffCapIsHonoured(): void
    {
        $db = $this->builder();

        try {
            $db->retryOnDeadlock(fn() => throw $this->pdoError('40001', 1213), 12);
        } catch (PDOException) {
            // expected
        }

        foreach ($db->sleeps as $delay) {
            self::assertLessThanOrEqual(2_000_000, $delay, 'A retry storm must not stall a worker for minutes.');
        }
    }

    // ─── Rollback robustness ─────────────────────────────────────────

    public function testAFailingRollbackDoesNotMaskTheOriginalDeadlock(): void
    {
        $db = $this->builder();
        $db->rollbackThrows = true;
        $calls = 0;

        try {
            $db->retryOnDeadlock(function () use (&$calls) {
                $calls++;
                throw $this->pdoError('40001', 1213, 'Deadlock found');
            }, 2);
            self::fail('Expected the deadlock to surface.');
        } catch (PDOException $e) {
            self::assertStringContainsString(
                'Deadlock found',
                $e->getMessage(),
                'The rollback failure replaced the error the caller actually needs to see.'
            );
            self::assertSame(2, $calls, 'A failing rollback must not abort the retry loop.');
        }
    }

    public function testRetriesAreLogged(): void
    {
        $db = $this->builder();

        try {
            $db->retryOnDeadlock(fn() => throw $this->pdoError('40001', 1213), 3);
        } catch (PDOException) {
            // expected
        }

        // Two retries logged; the final failure is the caller's to report.
        self::assertCount(2, $db->warnings);
        self::assertStringContainsString('will be replayed', $db->warnings[0]);
    }

    public function testASuccessfulFirstAttemptSleepsAndLogsNothing(): void
    {
        $db = $this->builder();

        self::assertSame('fine', $db->retryOnDeadlock(static fn() => 'fine'));
        self::assertSame([], $db->sleeps);
        self::assertSame([], $db->warnings);
    }
}
