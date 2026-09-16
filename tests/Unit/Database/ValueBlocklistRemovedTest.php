<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\Database\FakeQueryBuilder;

/**
 * where() ran the raw-SQL blocklist over the bound *value*. Values go through ?
 * placeholders with emulation off, so it never protected anything — it only threw
 * InvalidArgumentException on ordinary text containing "--", "#", "0x" or a bare
 * SQL word, turning a user's comment into a 500.
 */
final class ValueBlocklistRemovedTest extends TestCase
{
    private function builder()
    {
        return new class extends FakeQueryBuilder {
            /** @return array<string,mixed> */
            public function boundValues(): array
            {
                return (array) $this->_binds;
            }

            public function whereClause(): string
            {
                return (string) $this->where;
            }
        };
    }

    /** @return list<array{0:string}> */
    public static function legitimateValueProvider(): array
    {
        return [
            'double dash'   => ['Great product -- highly recommend'],
            'percent dash'  => ['Discount 50% -- limited'],
            'hex looking'   => ['model 0xFF sensor'],
            'sql word'      => ['SELECT'],
            'block comment' => ['note /* internal */ ok'],
            'hash'          => ['Lot #5 Jalan Ampang'],
            'union'         => ['UNION of concerned residents'],
            'drop'          => ['DROP OFF POINT'],
            'quote'         => ["O'Brien"],
            'semicolon'     => ['one; two; three'],
        ];
    }

    #[DataProvider('legitimateValueProvider')]
    public function testOrdinaryTextIsAcceptedAsAWhereValue(string $value): void
    {
        $db = $this->builder();
        $db->where('notes', $value);

        self::assertContains($value, $db->boundValues(), 'The value was not bound.');
    }

    #[DataProvider('legitimateValueProvider')]
    public function testOrdinaryTextIsAcceptedByOrWhere(string $value): void
    {
        $db = $this->builder();
        $db->where('id', 1)->orWhere('notes', $value);

        self::assertContains($value, $db->boundValues());
    }

    public function testTheValueIsBoundRatherThanInlined(): void
    {
        // This is why the blocklist was pointless: the value never reaches the parser.
        $db = $this->builder();
        $db->where('notes', "'; DROP TABLE users; --");

        self::assertStringContainsString('?', $db->whereClause());
        self::assertStringNotContainsString('DROP TABLE', $db->whereClause());
    }

    public function testColumnNamesAreStillChecked(): void
    {
        // Identifiers are interpolated, so their guard has to stay.
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/Stacked queries are not allowed/');

        $this->builder()->where('id; DROP TABLE users', 1);
    }

    public function testNoValueBlocklistCallsRemainInTheWhereBuilder(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 3) . '/systems/Core/Database/Concerns/HasWhereConditions.php'
        );

        self::assertSame(
            0,
            substr_count($source, '_forbidRawQuery($value'),
            'The blocklist is back on bound values, so legitimate text will 500 again.'
        );
    }
}
