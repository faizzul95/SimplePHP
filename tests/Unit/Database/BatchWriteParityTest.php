<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use Core\Database\Concerns\HasBatchWrites;
use Core\Database\Drivers\MariaDBDriver;
use Core\Database\Drivers\MySQLDriver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Fixtures\Database\FakeQueryBuilder;

/**
 * MariaDBDriver declared batchInsert() and batchUpdate() as stubs returning $this.
 * iterableWriteBatchSucceeded() treats any object without a `code >= 400` property
 * as success, so on a MariaDB connection insertInBatches(), updateInBatches(),
 * Model::bulkInsert(), Model::bulkUpdate() and Model::importInBatches() all
 * reported success and wrote nothing. Silent data loss.
 *
 */
final class BatchWriteParityTest extends TestCase
{
    /** @return list<array{0:class-string}> */
    public static function driverProvider(): array
    {
        return [
            [MySQLDriver::class],
            [MariaDBDriver::class],
        ];
    }

    #[DataProvider('driverProvider')]
    public function testEveryDriverSharesTheRealBatchWriteImplementation(string $driver): void
    {
        self::assertContains(
            HasBatchWrites::class,
            class_uses($driver),
            $driver . ' must not carry its own batch-write implementation — that is how the two drifted apart.'
        );
    }

    /** @return list<array{0:class-string,1:string}> */
    public static function driverMethodProvider(): array
    {
        $methods = ['batchInsert', 'batchUpdate'];
        $cases = [];

        foreach ([MySQLDriver::class, MariaDBDriver::class] as $driver) {
            foreach ($methods as $method) {
                $cases[] = [$driver, $method];
            }
        }

        return $cases;
    }

    #[DataProvider('driverMethodProvider')]
    public function testBatchWritesAreNotStubs(string $driver, string $method): void
    {
        $reflection = new ReflectionClass($driver);
        $target = $reflection->getMethod($method);

        $lineCount = $target->getEndLine() - $target->getStartLine();

        // The stub was three lines: signature, `return $this;`, brace. A real
        // implementation builds SQL, binds and executes.
        self::assertGreaterThan(
            20,
            $lineCount,
            $driver . '::' . $method . '() looks like a stub; a stub here loses data silently.'
        );
    }

    #[DataProvider('driverMethodProvider')]
    public function testBatchWritesDoNotReturnTheBuilder(string $driver, string $method): void
    {
        $reflection = new ReflectionClass($driver);
        $target = $reflection->getMethod($method);

        $source = implode('', array_slice(
            file((string) $target->getFileName()) ?: [],
            $target->getStartLine() - 1,
            $target->getEndLine() - $target->getStartLine() + 1
        ));

        // `return $this` is indistinguishable from success to
        // iterableWriteBatchSucceeded(), which is what made the bug silent.
        self::assertStringNotContainsString(
            'return $this;',
            $source,
            $driver . '::' . $method . '() returns the builder, which the batch runner reads as success.'
        );
    }

    /**
     * The success check is deliberately permissive, so a driver that returns the
     * builder is reported as a successful write. Pinning the behaviour here
     * documents why returning $this is never acceptable from a batch method.
     */
    public function testTheSuccessCheckCannotDistinguishABuilderFromASuccessfulWrite(): void
    {
        $db = new class extends FakeQueryBuilder {
            public function __construct()
            {
                parent::__construct('users');
            }

            public function probeSuccess(mixed $result): bool
            {
                return $this->iterableWriteBatchSucceeded($result);
            }
        };

        self::assertTrue($db->probeSuccess($db), 'Confirms the hazard: a builder reads as success.');
        self::assertFalse($db->probeSuccess(false));
        self::assertFalse($db->probeSuccess(['code' => 500]));
        self::assertTrue($db->probeSuccess(['code' => 201, 'affected_rows' => 3]));
    }
}
