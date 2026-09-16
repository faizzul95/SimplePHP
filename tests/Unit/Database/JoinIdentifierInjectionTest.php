<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\Database\FakeQueryBuilder;

/**
 * Every JOIN builder interpolated $foreignKey raw between backticks:
 *
 *     " LEFT JOIN $safeTable ON $safeTable.`$foreignKey` = $safeLocalKey"
 *
 * $table was escaped, $foreignKey was not, and validateColumn() does not catch it
 * because it only asserts the value is a non-empty string. A backtick in the
 * foreign key therefore closed the identifier quoting and the remainder reached
 * the server as SQL.
 *
 * _escapeJoinColumn() had the mirror-image hole: it returned the input untouched
 * whenever it already contained a backtick, on the assumption that meant
 * "already escaped".
 */
final class JoinIdentifierInjectionTest extends TestCase
{
    private function builder(string $table = 'users')
    {
        return new class ($table) extends FakeQueryBuilder {
            public function joinClause(): string
            {
                return (string) $this->joins;
            }
        };
    }

    /** @return list<array{0:string}> */
    public static function injectionProvider(): array
    {
        return [
            'backtick break'    => ['id` = `x` OR `1'],
            'quote break'       => ["id' OR '1'='1"],
            'stacked statement' => ['id; DROP TABLE users'],
            'comment'           => ['id -- x'],
            'subquery'          => ['(SELECT 1)'],
            'function call'     => ['IF(1,1,0)'],
            'comma injection'   => ['id, other'],
            'whitespace'        => ['id OR 1=1'],
            'wildcard'          => ['*'],
            'empty'             => [''],
        ];
    }

    #[DataProvider('injectionProvider')]
    public function testForeignKeyRejectsNonIdentifiers(string $payload): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->builder()->leftJoin('profiles', $payload, 'id');
    }

    #[DataProvider('injectionProvider')]
    public function testLocalKeyRejectsNonIdentifiers(string $payload): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->builder()->leftJoin('profiles', 'user_id', $payload);
    }

    #[DataProvider('injectionProvider')]
    public function testCrossJoinRejectsNonIdentifiers(string $payload): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->builder()->crossJoin($payload);
    }

    /** @return list<array{0:string}> */
    public static function joinMethodProvider(): array
    {
        return [['join'], ['leftJoin'], ['rightJoin'], ['innerJoin'], ['outerJoin']];
    }

    #[DataProvider('joinMethodProvider')]
    public function testEveryJoinBuilderRejectsABacktickInTheForeignKey(string $method): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->builder()->{$method}('profiles', 'id` = `x` OR `1', 'id');
    }

    public function testValidJoinStillProducesQualifiedIdentifiers(): void
    {
        $builder = $this->builder();
        $builder->leftJoin('profiles', 'user_id', 'id');

        self::assertSame(
            ' LEFT JOIN `profiles` ON `profiles`.`user_id` = `users`.`id`',
            $builder->joinClause()
        );
    }

    public function testDottedLocalKeyKeepsItsOwnQualifier(): void
    {
        $builder = $this->builder();
        $builder->leftJoin('master_roles', 'id', 'user_profile.role_id');

        self::assertSame(
            ' LEFT JOIN `master_roles` ON `master_roles`.`id` = `user_profile`.`role_id`',
            $builder->joinClause()
        );
    }

    public function testAlreadyBacktickedIdentifiersAreReQuotedNotTrusted(): void
    {
        $builder = $this->builder();
        $builder->leftJoin('profiles', '`user_id`', '`users`.`id`');

        self::assertSame(
            ' LEFT JOIN `profiles` ON `profiles`.`user_id` = `users`.`id`',
            $builder->joinClause()
        );
    }

    public function testCrossJoinQuotesTheTable(): void
    {
        $builder = $this->builder();
        $builder->crossJoin('profiles');

        self::assertSame(' CROSS JOIN `profiles`', $builder->joinClause());
    }
}
