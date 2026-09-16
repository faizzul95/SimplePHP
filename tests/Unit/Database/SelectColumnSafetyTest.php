<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/BuilderSubQueryTest.php';

/**
 * select() returned an entry untouched whenever it contained a dot, an " as ",
 * or a pair of parentheses — the three shapes hardest to validate and the three
 * that matter most. A `fields=` parameter reaching select() could therefore
 * carry `(SELECT password FROM users LIMIT 1) AS x` straight into the query:
 * arbitrary reads through a select list, and a SLEEP() away from a blind timing
 * oracle.
 *
 * The last identifier path in the builder that trusted its input.
 */
final class SelectColumnSafetyTest extends TestCase
{
    private function probe(string $table = 'users'): SubQueryProbe
    {
        return new SubQueryProbe($table);
    }

    private function selected(string $columns, string $table = 'users'): string
    {
        $db = $this->probe($table);
        $db->select($columns);

        $property = new \ReflectionProperty(\Core\Database\BaseDatabase::class, 'column');
        $property->setAccessible(true);

        return (string) $property->getValue($db);
    }

    // ─── Ordinary column lists ───────────────────────────────────────

    public function testABareColumnIsQualifiedAndQuoted(): void
    {
        self::assertSame('`users`.`email`', $this->selected('email'));
    }

    public function testAListIsSplitAndEachEntryQuoted(): void
    {
        self::assertSame('`users`.`id`, `users`.`email`', $this->selected('id, email'));
    }

    public function testAnArrayOfColumnsWorksTheSameWay(): void
    {
        $db = $this->probe();
        $db->select(['id', 'email']);

        $property = new \ReflectionProperty(\Core\Database\BaseDatabase::class, 'column');
        $property->setAccessible(true);

        self::assertSame('`users`.`id`, `users`.`email`', (string) $property->getValue($db));
    }

    public function testAQualifiedColumnQuotesEachSegment(): void
    {
        self::assertSame('`orders`.`total`', $this->selected('orders.total'));
    }

    public function testTheWildcardSurvives(): void
    {
        self::assertSame('*', $this->selected('*'));
        self::assertSame('`orders`.*', $this->selected('orders.*'));
    }

    public function testNoColumnsMeansEverything(): void
    {
        self::assertSame('*', $this->selected(''));
    }

    // ─── Aliases ─────────────────────────────────────────────────────

    public function testAnAliasIsQuotedRatherThanPassedThrough(): void
    {
        self::assertSame('`users`.`email` AS `contact`', $this->selected('email AS contact'));
    }

    public function testTheAliasKeywordIsCaseInsensitive(): void
    {
        self::assertSame('`users`.`email` AS `contact`', $this->selected('email as contact'));
    }

    public function testAnAlreadyQuotedAliasIsNotDoubleQuoted(): void
    {
        self::assertSame('`users`.`email` AS `contact`', $this->selected('email AS `contact`'));
    }

    // ─── Functions ───────────────────────────────────────────────────

    public function testAnAllowedAggregateIsRebuiltFromValidatedParts(): void
    {
        self::assertSame('SUM(`orders`.`total`)', $this->selected('SUM(total)', 'orders'));
    }

    public function testAnAggregateOverTheWildcardWorks(): void
    {
        self::assertSame('COUNT(*)', $this->selected('COUNT(*)'));
    }

    public function testDistinctInsideAnAggregateIsPreserved(): void
    {
        self::assertSame('COUNT(DISTINCT `users`.`email`)', $this->selected('COUNT(DISTINCT email)'));
    }

    public function testAnAggregateWithAnAliasCombinesBothRules(): void
    {
        self::assertSame(
            'SUM(`orders`.`total`) AS `lifetime`',
            $this->selected('SUM(total) AS lifetime', 'orders')
        );
    }

    public function testTheFunctionNameIsNormalisedToUpperCase(): void
    {
        self::assertSame('MAX(`users`.`id`)', $this->selected('max(id)'));
    }

    // ─── What must be refused ────────────────────────────────────────

    /** @return array<string, array{0:string}> */
    public static function refusedProvider(): array
    {
        return [
            'subquery behind an alias' => ['(SELECT password FROM users LIMIT 1) AS x'],
            'bare subquery' => ['(SELECT password FROM users)'],
            'timing oracle' => ['IF(SUBSTRING(password,1,1)=0x61, SLEEP(5), 1)'],
            'sleep' => ['SLEEP(5)'],
            'union' => ['id UNION SELECT password FROM users'],
            'comment terminator' => ['id -- '],
            'stacked statement' => ['id; DROP TABLE users'],
            'arithmetic' => ['price * quantity'],
            'string literal' => ["'constant'"],
            'load_file' => ['LOAD_FILE("/etc/passwd")'],
            'multi-argument function' => ['ROUND(total, 2)'],
            'nested disallowed call' => ['SUM(SLEEP(1))'],
            'backtick escape' => ['`users`.`id`, (SELECT 1)'],
            'implicit alias' => ['id x'],
        ];
    }

    /**
     * Refused rather than guessed at. Each of these was previously returned
     * verbatim because it contained a dot, an " as ", or parentheses.
     */
    #[DataProvider('refusedProvider')]
    public function testAnExpressionIsRefused(string $columns): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->probe()->select($columns);
    }

    /** The message has to say where deliberately raw SQL belongs. */
    public function testTheRefusalNamesSelectRaw(): void
    {
        try {
            $this->probe()->select('price * quantity');
            self::fail('Expected the expression to be refused.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('selectRaw()', $e->getMessage());
        }
    }

    public function testADisallowedFunctionIsNamedInTheMessage(): void
    {
        try {
            $this->probe()->select('SLEEP(5)');
            self::fail('Expected SLEEP to be refused.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('SLEEP', $e->getMessage());
        }
    }

    /**
     * A multi-argument call has to reach the parser whole. Splitting the list on
     * every comma would tear ROUND(total, 2) into two halves and produce a
     * message about neither of them.
     */
    public function testAMultiArgumentCallIsNotTornApartByTheListSplitter(): void
    {
        try {
            $this->probe()->select('id, ROUND(total, 2)');
            self::fail('Expected the multi-argument call to be refused.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('ROUND', $e->getMessage());
        }
    }

    // ─── selectRaw is still the escape hatch ─────────────────────────

    /** Refusing in select() is only reasonable because selectRaw() exists. */
    public function testSelectRawStillAcceptsADeliberateExpression(): void
    {
        $db = $this->probe();
        $db->selectRaw('SUM(price * quantity) AS revenue');

        $property = new \ReflectionProperty(\Core\Database\BaseDatabase::class, 'column');
        $property->setAccessible(true);

        self::assertStringContainsString('SUM(price * quantity)', (string) $property->getValue($db));
    }
}
