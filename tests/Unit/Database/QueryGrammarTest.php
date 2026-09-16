<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use Core\Database\Query\Grammars\MariaDBGrammar;
use Core\Database\Query\Grammars\MySQLGrammar;
use Core\Database\Query\Grammars\OracleGrammar;
use Core\Database\Query\Grammars\PostgresGrammar;
use Core\Database\Query\Grammars\QueryGrammar;
use Core\Database\Query\Grammars\SqlServerGrammar;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * QueryGrammar existed with one method on it — temporal expressions — while
 * identifier quoting, LIMIT/OFFSET, upserts, random ordering and column listing
 * stayed hard-coded to MySQL in the builder. The seam was there; almost nothing
 * went through it, so "support PostgreSQL later" still meant an audit of every
 * backtick.
 *
 * These pin what each engine actually needs, so a driver can be added by writing
 * a grammar rather than by editing the builder.
 */
final class QueryGrammarTest extends TestCase
{
    /** @return array<string, array{0:QueryGrammar}> */
    public static function everyGrammarProvider(): array
    {
        return [
            'MySQL' => [new MySQLGrammar()],
            'MariaDB' => [new MariaDBGrammar()],
            'PostgreSQL' => [new PostgresGrammar()],
            'SQL Server' => [new SqlServerGrammar()],
            'Oracle' => [new OracleGrammar()],
        ];
    }

    // ─── Identifier quoting ──────────────────────────────────────────

    public function testEachEngineQuotesWithItsOwnCharacters(): void
    {
        self::assertSame('`users`', (new MySQLGrammar())->wrap('users'));
        self::assertSame('"users"', (new PostgresGrammar())->wrap('users'));
        self::assertSame('[users]', (new SqlServerGrammar())->wrap('users'));
        self::assertSame('"users"', (new OracleGrammar())->wrap('users'));
    }

    #[DataProvider('everyGrammarProvider')]
    public function testQualifiedNamesQuoteEachSegment(QueryGrammar $grammar): void
    {
        $wrapped = $grammar->wrap('users.email');

        self::assertStringContainsString('.', $wrapped);
        self::assertSame(2, count(explode('.', $wrapped)));
    }

    /**
     * A column named `user"name` or `user]name` has to survive quoting, or the
     * identifier terminates early and the rest becomes SQL.
     */
    public function testAQuoteCharacterInsideAnIdentifierIsEscaped(): void
    {
        self::assertSame('`us``er`', (new MySQLGrammar())->wrapValue('us`er'));
        self::assertSame('"us""er"', (new PostgresGrammar())->wrapValue('us"er'));
        self::assertSame('[us]]er]', (new SqlServerGrammar())->wrapValue('us]er'));
    }

    /** Quoting the wildcard turns `SELECT *` into a lookup for a column named "*". */
    #[DataProvider('everyGrammarProvider')]
    public function testTheWildcardIsNotQuoted(QueryGrammar $grammar): void
    {
        self::assertSame('*', $grammar->wrap('*'));
        self::assertSame('*', $grammar->wrapValue('*'));
    }

    /** Callers pass pre-wrapped identifiers; wrapping twice produces invalid SQL. */
    #[DataProvider('everyGrammarProvider')]
    public function testWrappingIsIdempotent(QueryGrammar $grammar): void
    {
        $once = $grammar->wrap('users');

        self::assertSame($once, $grammar->wrap($once));
        self::assertSame($grammar->wrap('users.email'), $grammar->wrap($grammar->wrap('users.email')));
    }

    /**
     * Idempotency used to be a `str_contains($value, '`')` check, which treats
     * the column name "na`me" as finished and emits it raw — an injection point
     * inside the escaping function. Only a correctly quoted segment passes
     * through; anything else is escaped.
     */
    #[DataProvider('everyGrammarProvider')]
    public function testAnIdentifierContainingAQuoteIsEscapedNotTrusted(QueryGrammar $grammar): void
    {
        $wrapped = $grammar->wrap('na' . $grammar->wrap('x')[0] . 'me');

        self::assertNotSame('na' . $grammar->wrap('x')[0] . 'me', $wrapped);
    }

    public function testAHalfWrappedIdentifierIsNotMistakenForAWrappedOne(): void
    {
        $grammar = new MySQLGrammar();

        self::assertSame('`na``me`', $grammar->wrap('na`me'));
        self::assertSame('`users```', $grammar->wrap('users`'));
        self::assertSame('```users`', $grammar->wrap('`users'));
    }

    /** A dot inside a quoted segment separates nothing. */
    public function testDotsInsideAQuotedSegmentAreNotSeparators(): void
    {
        $grammar = new MySQLGrammar();

        self::assertSame('`a.b`.`c`', $grammar->wrap('`a.b`.`c`'));
    }

    #[DataProvider('everyGrammarProvider')]
    public function testAnEmptyIdentifierProducesNothing(QueryGrammar $grammar): void
    {
        self::assertSame('', $grammar->wrap(''));
        self::assertSame('', $grammar->wrap('   '));
    }

    public function testASchemaQualifiedTableIsFullyQuoted(): void
    {
        self::assertSame('`app`.`users`', (new MySQLGrammar())->wrapTable('users', 'app'));
        self::assertSame('"app"."users"', (new PostgresGrammar())->wrapTable('users', 'app'));
        self::assertSame('`users`', (new MySQLGrammar())->wrapTable('users'));
    }

    // ─── Row limiting ────────────────────────────────────────────────

    public function testLimitAndOffsetUseEachEnginesSyntax(): void
    {
        self::assertSame('LIMIT 10 OFFSET 20', (new MySQLGrammar())->compileLimitOffset(10, 20));
        self::assertSame('LIMIT 10 OFFSET 20', (new PostgresGrammar())->compileLimitOffset(10, 20));
        self::assertSame('OFFSET 20 ROWS FETCH NEXT 10 ROWS ONLY', (new SqlServerGrammar())->compileLimitOffset(10, 20));
        self::assertSame('OFFSET 20 ROWS FETCH NEXT 10 ROWS ONLY', (new OracleGrammar())->compileLimitOffset(10, 20));
    }

    /**
     * MySQL has no way to say OFFSET without LIMIT, which is why the documented
     * "all rows" sentinel exists. Omitting the clause would silently return the
     * first page instead of skipping to the requested one.
     */
    public function testMysqlNeedsASentinelLimitForABareOffset(): void
    {
        $compiled = (new MySQLGrammar())->compileLimitOffset(null, 20);

        self::assertStringContainsString('OFFSET 20', $compiled);
        self::assertStringContainsString('18446744073709551615', $compiled);
    }

    public function testPostgresCanOffsetWithoutALimit(): void
    {
        self::assertSame('OFFSET 20', (new PostgresGrammar())->compileLimitOffset(null, 20));
    }

    #[DataProvider('everyGrammarProvider')]
    public function testNoLimitAndNoOffsetCompilesToNothing(QueryGrammar $grammar): void
    {
        self::assertSame('', $grammar->compileLimitOffset(null, null));
        self::assertSame('', $grammar->compileLimitOffset(null, 0));
    }

    /**
     * SQL Server and Oracle reject OFFSET without ORDER BY, so the builder has to
     * know to supply a deterministic ordering before it paginates.
     */
    public function testTheEnginesThatDemandAnOrderBySaySo(): void
    {
        self::assertFalse((new MySQLGrammar())->offsetRequiresOrderBy());
        self::assertFalse((new PostgresGrammar())->offsetRequiresOrderBy());
        self::assertTrue((new SqlServerGrammar())->offsetRequiresOrderBy());
        self::assertTrue((new OracleGrammar())->offsetRequiresOrderBy());
    }

    // ─── Random ordering ─────────────────────────────────────────────

    public function testRandomOrderingIsSpeltDifferentlyEverywhere(): void
    {
        self::assertSame('RAND()', (new MySQLGrammar())->compileRandomOrder());
        self::assertSame('RANDOM()', (new PostgresGrammar())->compileRandomOrder());
        self::assertSame('NEWID()', (new SqlServerGrammar())->compileRandomOrder());
        self::assertSame('DBMS_RANDOM.VALUE', (new OracleGrammar())->compileRandomOrder());
    }

    /** A seed makes the shuffle reproducible, which a paginated random list needs. */
    public function testMysqlAcceptsASeed(): void
    {
        self::assertSame('RAND(42)', (new MySQLGrammar())->compileRandomOrder('42'));
    }

    // ─── Upserts ─────────────────────────────────────────────────────

    public function testMysqlUsesOnDuplicateKeyUpdate(): void
    {
        $clause = (new MySQLGrammar())->compileUpsertClause(['name', 'email'], ['id']);

        self::assertStringStartsWith('ON DUPLICATE KEY UPDATE', (string) $clause);
        self::assertStringContainsString('`name` = VALUES(`name`)', (string) $clause);
    }

    public function testPostgresUsesOnConflictDoUpdate(): void
    {
        $clause = (new PostgresGrammar())->compileUpsertClause(['name'], ['id']);

        self::assertStringContainsString('ON CONFLICT ("id")', (string) $clause);
        self::assertStringContainsString('"name" = EXCLUDED."name"', (string) $clause);
    }

    /**
     * SQL Server and Oracle need MERGE, which is a whole statement rather than a
     * clause to append. Returning null tells the builder to take the portable
     * select-then-write path instead of emitting SQL the engine cannot parse.
     */
    public function testTheMergeEnginesDeclineTheClauseRatherThanGuess(): void
    {
        self::assertNull((new SqlServerGrammar())->compileUpsertClause(['name'], ['id']));
        self::assertNull((new OracleGrammar())->compileUpsertClause(['name'], ['id']));
    }

    #[DataProvider('everyGrammarProvider')]
    public function testAnEmptyUpdateListHasNothingToCompile(QueryGrammar $grammar): void
    {
        self::assertNull($grammar->compileUpsertClause([], ['id']));
    }

    public function testInsertIgnoreIsAModifierOnMysqlAndAClauseOnPostgres(): void
    {
        // MySQL: INSERT IGNORE INTO … — before the table, not after the values.
        self::assertNull((new MySQLGrammar())->compileInsertIgnoreClause());
        self::assertSame('IGNORE', (new MySQLGrammar())->insertIgnoreModifier());

        self::assertSame('ON CONFLICT DO NOTHING', (new PostgresGrammar())->compileInsertIgnoreClause());
    }

    // ─── Returning ───────────────────────────────────────────────────

    public function testOnlyTheEnginesWithReturningClaimIt(): void
    {
        self::assertFalse((new MySQLGrammar())->supportsReturning(), 'MySQL has lastInsertId() and nothing else.');
        self::assertTrue((new MariaDBGrammar())->supportsReturning(), 'MariaDB 10.5+.');
        self::assertTrue((new PostgresGrammar())->supportsReturning());
    }

    // ─── Locking ─────────────────────────────────────────────────────

    public function testSkipLockedCompilesWhereItIsSupported(): void
    {
        self::assertSame('FOR UPDATE SKIP LOCKED', (new MySQLGrammar())->compileLock(true, skipLocked: true));
        self::assertSame('FOR UPDATE SKIP LOCKED', (new PostgresGrammar())->compileLock(true, skipLocked: true));
    }

    public function testASharedLockIsNotAnExclusiveOne(): void
    {
        self::assertSame('FOR SHARE', (new PostgresGrammar())->compileLock(false));
    }

    /**
     * SQL Server locks through table hints after FROM, not a trailing clause.
     * Emitting FOR UPDATE there is a syntax error, so it emits nothing.
     */
    public function testSqlServerEmitsNoTrailingLockClause(): void
    {
        self::assertSame('', (new SqlServerGrammar())->compileLock(true));
        self::assertSame('', (new SqlServerGrammar())->compileLock(true, skipLocked: true));
    }

    // ─── Case-insensitive matching ───────────────────────────────────

    public function testOnlyPostgresNeedsIlike(): void
    {
        self::assertSame('ILIKE', (new PostgresGrammar())->compileCaseInsensitiveLike());
        self::assertSame('NOT ILIKE', (new PostgresGrammar())->compileCaseInsensitiveLike(true));

        // MySQL and SQL Server collations are case-insensitive already.
        self::assertSame('LIKE', (new MySQLGrammar())->compileCaseInsensitiveLike());
        self::assertSame('LIKE', (new SqlServerGrammar())->compileCaseInsensitiveLike());
    }

    // ─── Full text ───────────────────────────────────────────────────

    public function testFullTextUsesEachEnginesOwnSyntax(): void
    {
        self::assertStringContainsString(
            'MATCH (`title`, `body`) AGAINST (?',
            (string) (new MySQLGrammar())->compileFullTextPredicate(['title', 'body'])
        );

        self::assertStringContainsString(
            'to_tsvector',
            (string) (new PostgresGrammar())->compileFullTextPredicate(['title'])
        );

        self::assertStringContainsString(
            'CONTAINS(',
            (string) (new SqlServerGrammar())->compileFullTextPredicate(['title'])
        );
    }

    public function testBooleanModeIsDistinctFromNaturalLanguage(): void
    {
        $natural = (string) (new MySQLGrammar())->compileFullTextPredicate(['title'], 'natural');
        $boolean = (string) (new MySQLGrammar())->compileFullTextPredicate(['title'], 'boolean');

        self::assertStringContainsString('IN NATURAL LANGUAGE MODE', $natural);
        self::assertStringContainsString('IN BOOLEAN MODE', $boolean);
    }

    /** Oracle Text has no multi-column CONTAINS; declining lets the builder fall back to LIKE. */
    public function testOracleDeclinesMultiColumnFullText(): void
    {
        self::assertNotNull((new OracleGrammar())->compileFullTextPredicate(['title']));
        self::assertNull((new OracleGrammar())->compileFullTextPredicate(['title', 'body']));
    }

    #[DataProvider('everyGrammarProvider')]
    public function testFullTextWithNoColumnsCompilesToNothing(QueryGrammar $grammar): void
    {
        self::assertNull($grammar->compileFullTextPredicate([]));
    }

    // ─── Column listing ──────────────────────────────────────────────

    /**
     * SHOW COLUMNS exists only on MySQL. Every engine can answer through
     * information_schema (or ALL_TAB_COLUMNS on Oracle), and the table name is a
     * binding rather than string interpolation.
     */
    #[DataProvider('everyGrammarProvider')]
    public function testColumnListingIsAPreparedStatement(QueryGrammar $grammar): void
    {
        $listing = $grammar->compileColumnListing('users');

        self::assertStringNotContainsString('SHOW COLUMNS', $listing['sql']);
        self::assertStringContainsString('?', $listing['sql']);
        self::assertNotEmpty($listing['bindings']);
        self::assertNotSame('', $listing['column']);
    }

    /** Without a schema filter, every same-named table on the server matches. */
    public function testMysqlScopesColumnListingToTheCurrentDatabase(): void
    {
        $listing = (new MySQLGrammar())->compileColumnListing('users');

        self::assertStringContainsString('TABLE_SCHEMA = DATABASE()', $listing['sql']);
    }

    /** Oracle folds unquoted identifiers to upper case, so the lookup must too. */
    public function testOracleUppercasesTheTableNameItLooksUp(): void
    {
        $listing = (new OracleGrammar())->compileColumnListing('users', 'app');

        self::assertSame(['USERS', 'APP'], $listing['bindings']);
    }

    // ─── Temporal expressions ────────────────────────────────────────

    #[DataProvider('everyGrammarProvider')]
    public function testEveryGrammarCompilesEveryTemporalType(QueryGrammar $grammar): void
    {
        foreach (['date', 'day', 'month', 'year', 'time'] as $type) {
            $compiled = $grammar->compileTemporalExpression($type, 'created_at');

            self::assertNotSame('', $compiled);
            self::assertStringContainsString('created_at', $compiled);
        }
    }

    #[DataProvider('everyGrammarProvider')]
    public function testAnUnknownTemporalTypeIsRejected(QueryGrammar $grammar): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $grammar->compileTemporalExpression('fortnight', 'created_at');
    }
}
