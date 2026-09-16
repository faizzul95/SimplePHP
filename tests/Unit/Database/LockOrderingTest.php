<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Tests\Fixtures\Database\FakeQueryBuilder;

/**
 * Deadlock prevention rather than recovery.
 *
 * Two transactions that touch the same rows in opposite orders deadlock every
 * time — T1 locks 1 then 2, T2 locks 2 then 1, and each holds what the other
 * wants. Retrying recovers from that; ordering the keys stops the cycle forming
 * at all, because every writer queues in the same sequence.
 *
 */
final class LockOrderingTest extends TestCase
{
    private function builder()
    {
        return new class extends FakeQueryBuilder {
            public function __construct()
            {
                parent::__construct('users');
            }

            /** @param list<mixed> $keys @return list<mixed> */
            public function orderKeys(array $keys): array
            {
                return $this->orderKeysForLocking($keys);
            }

            /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
            public function orderRows(array $rows, string $key = 'id'): array
            {
                return $this->orderRowsForLocking($rows, $key);
            }
        };
    }

    // ─── Keys ────────────────────────────────────────────────────────

    public function testIntegerKeysAreSortedNumerically(): void
    {
        // String sorting would put 10 before 9 and two writers using different
        // comparison rules would still deadlock.
        self::assertSame(
            [2, 9, 10, 100],
            $this->builder()->orderKeys([100, 9, 2, 10])
        );
    }

    public function testNumericStringKeysSortNumericallyToo(): void
    {
        self::assertSame(
            ['2', '9', '10'],
            $this->builder()->orderKeys(['10', '2', '9'])
        );
    }

    public function testUuidKeysGetATotalOrder(): void
    {
        $keys = ['c-3', 'a-1', 'b-2'];

        self::assertSame(['a-1', 'b-2', 'c-3'], $this->builder()->orderKeys($keys));
    }

    public function testMixedTypesStillProduceAStableTotalOrder(): void
    {
        $db = $this->builder();

        $first = $db->orderKeys([10, 'abc', 2, 'zzz']);
        $second = $db->orderKeys(['zzz', 2, 10, 'abc']);

        // The order chosen does not matter; that every writer picks the SAME one does.
        self::assertSame($first, $second);
    }

    public function testTwoWritersWithOppositeInputOrderProduceTheSameLockSequence(): void
    {
        $db = $this->builder();

        $writerA = $db->orderKeys([5, 1, 9, 3]);
        $writerB = $db->orderKeys([9, 3, 5, 1]);

        self::assertSame($writerA, $writerB, 'This equality is the whole deadlock guarantee.');
    }

    public function testTrivialInputsAreUntouched(): void
    {
        self::assertSame([], $this->builder()->orderKeys([]));
        self::assertSame([7], $this->builder()->orderKeys([7]));
    }

    // ─── Rows ────────────────────────────────────────────────────────

    public function testRowsAreOrderedByTheirKeyColumn(): void
    {
        $rows = [
            ['id' => 30, 'name' => 'c'],
            ['id' => 10, 'name' => 'a'],
            ['id' => 20, 'name' => 'b'],
        ];

        self::assertSame(
            [10, 20, 30],
            array_column($this->builder()->orderRows($rows), 'id')
        );
    }

    public function testACustomKeyColumnIsHonoured(): void
    {
        $rows = [
            ['uuid' => 'c', 'v' => 3],
            ['uuid' => 'a', 'v' => 1],
        ];

        self::assertSame(
            ['a', 'c'],
            array_column($this->builder()->orderRows($rows, 'uuid'), 'uuid')
        );
    }

    public function testRowsWithoutTheKeyColumnAreMovedToTheEndNotDropped(): void
    {
        $rows = [
            ['name' => 'no key'],
            ['id' => 5, 'name' => 'e'],
            ['id' => 1, 'name' => 'a'],
        ];

        $ordered = $this->builder()->orderRows($rows);

        self::assertCount(3, $ordered, 'Reordering must never lose a row.');
        self::assertSame(1, $ordered[0]['id']);
        self::assertSame(5, $ordered[1]['id']);
        self::assertArrayNotHasKey('id', $ordered[2]);
    }

    public function testANullKeyCountsAsUnkeyed(): void
    {
        $rows = [
            ['id' => null, 'name' => 'insert me'],
            ['id' => 2, 'name' => 'b'],
        ];

        $ordered = $this->builder()->orderRows($rows);

        self::assertSame(2, $ordered[0]['id']);
        self::assertNull($ordered[1]['id']);
    }

    public function testASingleRowIsUntouched(): void
    {
        $rows = [['id' => 9]];

        self::assertSame($rows, $this->builder()->orderRows($rows));
    }

    // ─── Wired into the write paths ──────────────────────────────────

    public function testBatchUpdateOrdersRowsBeforeLocking(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 3) . '/systems/Core/Database/Concerns/HasBatchWrites.php'
        );

        self::assertStringContainsString(
            'orderRowsForLocking',
            $source,
            'batchUpdate() issues one UPDATE per row inside one transaction — the textbook deadlock shape.'
        );
    }

    public function testDeleteInBatchesOrdersKeysBeforeLocking(): void
    {
        $method = new ReflectionMethod(\Core\Database\BaseDatabase::class, 'deleteInBatches');
        $source = implode('', array_slice(
            file((string) $method->getFileName()) ?: [],
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        self::assertStringContainsString('orderKeysForLocking', $source);
    }
}
