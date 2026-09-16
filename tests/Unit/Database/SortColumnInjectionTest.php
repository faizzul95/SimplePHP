<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\Database\FakeQueryBuilder;

/**
 * orderBy() ran columns through a character class that allowed parentheses, commas
 * and spaces, so a bounded subquery reached ORDER BY verbatim — a blind time-based
 * injection wherever the sort column came from request input.
 */
final class SortColumnInjectionTest extends TestCase
{
    private function builder()
    {
        return new class extends FakeQueryBuilder {
            /** @return list<string> */
            public function orderByClauses(): array
            {
                return (array) $this->orderBy;
            }

            /** @return list<string> */
            public function havingClauses(): array
            {
                return (array) $this->having;
            }
        };
    }

    /** @return list<array{0:string}> */
    public static function injectionProvider(): array
    {
        return [
            'time-based subquery' => ['(SELECT IF(SUBSTRING(pwd,1,1) LIKE 0x61, SLEEP(5), 1) FROM users LIMIT 1)'],
            'bare subquery'       => ['(SELECT 1)'],
            'conditional sleep'   => ['(CASE WHEN 1 THEN SLEEP(5) ELSE 1 END)'],
            'ELT function'        => ['ELT(1, id, name)'],
            'stacked statement'   => ['id; DROP TABLE users'],
            'quote break'         => ["id' OR 1=1"],
            'comma injection'     => ['id, (SELECT 1)'],
            'wildcard'            => ['*'],
            'comment'             => ['id -- x'],
        ];
    }

    #[DataProvider('injectionProvider')]
    public function testOrderByRejectsExpressions(string $payload): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->builder()->orderBy($payload, 'ASC');
    }

    /** @return list<array{0:string,1:string}> */
    public static function validColumnProvider(): array
    {
        return [
            ['name',              '`name` ASC'],
            ['created_at',        '`created_at` ASC'],
            ['users.name',        '`users`.`name` ASC'],
            ['_private',          '`_private` ASC'],
            ['  spaced  ',        '`spaced` ASC'],
            ['`quoted`',          '`quoted` ASC'],
            ['`users`.`name`',    '`users`.`name` ASC'],
        ];
    }

    #[DataProvider('validColumnProvider')]
    public function testOrderByQuotesLegitimateColumns(string $column, string $expected): void
    {
        $db = $this->builder();
        $db->orderBy($column, 'ASC');

        self::assertSame([$expected], $db->orderByClauses());
    }

    public function testTheArrayFormIsAlsoQuoted(): void
    {
        $db = $this->builder();
        $db->orderBy(['users.name' => 'ASC', 'created_at' => 'DESC']);

        self::assertSame(['`users`.`name` ASC', '`created_at` DESC'], $db->orderByClauses());
    }

    public function testTheArrayFormRejectsAnInjectedKey(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->builder()->orderBy(['(SELECT 1)' => 'ASC']);
    }

    public function testTheErrorPointsAtOrderByRaw(): void
    {
        try {
            $this->builder()->orderBy('COUNT(*)', 'ASC');
            self::fail('Expected an InvalidArgumentException for an expression.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('orderByRaw', $e->getMessage());
        }
    }

    public function testDirectionIsStillValidated(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->builder()->orderBy('name', 'DROP TABLE users');
    }

    /**
     * having() legitimately takes aggregate expressions, so it keeps the permissive
     * check. Tightening both with one function would have broken having('COUNT(*)').
     */
    public function testHavingStillAcceptsAggregateExpressions(): void
    {
        $db = $this->builder();
        $db->having('COUNT(*)', 1, '>');

        self::assertNotEmpty($db->havingClauses());
    }

    public function testInRandomOrderStillWorks(): void
    {
        $db = $this->builder();
        $db->inRandomOrder();

        self::assertSame(['RAND()'], $db->orderByClauses());
    }

    public function testTheStrictQuoterIsWhatOrderByUses(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 3) . '/systems/Core/Database/Concerns/HasAggregates.php'
        );

        self::assertSame(
            2,
            substr_count($source, '$this->quoteSortColumn('),
            'Both orderBy() branches must use the strict quoter.'
        );
    }
}
