<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use Core\Database\BaseDatabase;
use PHPUnit\Framework\TestCase;

/**
 * A builder probe that compiles SQL without a connection.
 */
final class SubQueryProbe extends BaseDatabase
{
    public function __construct(string $table = 'users')
    {
        $this->table = $table;
        $this->column = '*';
        $this->driver = 'mysql';
    }

    public function connect($connectionID = null) { return $this; }

    // Abstract on BaseDatabase because their SQL is driver-specific; none of
    // them is exercised here.
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

    public function currentWhere(): ?string
    {
        return $this->where;
    }

    public function currentJoins(): ?string
    {
        return $this->joins;
    }

    /** @return array<int, mixed> */
    public function currentBinds(): array
    {
        return $this->_binds;
    }

    public function currentLock(): ?string
    {
        return $this->_lock;
    }

    public function buildInsertSql(array $data): string
    {
        $this->_buildInsertQuery($data);

        return $this->_query;
    }

    public function markInsertIgnore(): void
    {
        $this->_insertIgnore = true;
    }
}

/**
 * whereExists() and joinSub() were the two shapes with no builder form at all,
 * so any query needing them dropped to query() — which gives up parameter
 * binding, profiling and the read/write router for the whole statement, not
 * just for the part that needed raw SQL.
 *
 * whereHas() covered only the "rows in B pointing at this row" case, with the
 * foreign-key correlation forced.
 */
final class BuilderSubQueryTest extends TestCase
{
    // ─── Qualified identifiers ───────────────────────────────────────

    /**
     * whereColumn wrapped the whole dotted name as one identifier, so
     * `whereColumn('orders.user_id', 'users.id')` — the only shape the method is
     * for — asked the server for a column literally called "orders.user_id" and
     * failed with "unknown column". Quoting now goes through the grammar, which
     * splits on the dot.
     */
    public function testWhereColumnQualifiesEachSegmentSeparately(): void
    {
        $db = new SubQueryProbe('users');

        $db->whereColumn('orders.user_id', 'users.id');

        self::assertStringContainsString('`orders`.`user_id` = `users`.`id`', (string) $db->currentWhere());
    }

    public function testOrWhereColumnQualifiesEachSegmentSeparately(): void
    {
        $db = new SubQueryProbe('users');
        $db->where('status', '=', 'active');
        $db->orWhereColumn('orders.user_id', 'users.id');

        self::assertStringContainsString('`orders`.`user_id` = `users`.`id`', (string) $db->currentWhere());
    }

    public function testWhereBetweenColumnsQualifiesEachSegment(): void
    {
        $db = new SubQueryProbe('bookings');

        $db->whereBetweenColumns('bookings.created_at', ['seasons.starts_at', 'seasons.ends_at']);

        $where = (string) $db->currentWhere();
        self::assertStringContainsString('`bookings`.`created_at` BETWEEN', $where);
        self::assertStringContainsString('`seasons`.`starts_at` AND `seasons`.`ends_at`', $where);
    }

    /** An unqualified name is still scoped to the current table. */
    public function testAnUnqualifiedColumnIsScopedToTheTable(): void
    {
        $db = new SubQueryProbe('users');

        $db->where('email', '=', 'a@example.com');

        self::assertStringContainsString('`users`.`email`', (string) $db->currentWhere());
    }

    /** A backtick in a column name must be escaped, not trusted as pre-quoted. */
    public function testABacktickInAColumnNameIsEscaped(): void
    {
        $db = new SubQueryProbe('users');

        $db->whereColumn('na`me', 'users.id');

        self::assertStringContainsString('`na``me`', (string) $db->currentWhere());
    }

    // ─── whereExists ─────────────────────────────────────────────────

    public function testWhereExistsCompilesACorrelatedSubQuery(): void
    {
        $db = new SubQueryProbe('users');

        $db->whereExists(function ($q): void {
            $q->table('orders')->whereColumn('orders.user_id', 'users.id');
        });

        $where = (string) $db->currentWhere();

        self::assertStringContainsString('EXISTS (', $where);
        self::assertStringContainsString('`orders`', $where);
        self::assertStringContainsString('orders', $where);
    }

    public function testWhereNotExistsNegatesTheSamePredicate(): void
    {
        $db = new SubQueryProbe('users');

        $db->whereNotExists(function ($q): void {
            $q->table('orders')->whereColumn('orders.user_id', 'users.id');
        });

        self::assertStringContainsString('NOT EXISTS (', (string) $db->currentWhere());
    }

    public function testOrWhereExistsJoinsWithOr(): void
    {
        $db = new SubQueryProbe('users');

        $db->where('status', '=', 'active');
        $db->orWhereExists(function ($q): void {
            $q->table('orders')->whereColumn('orders.user_id', 'users.id');
        });

        self::assertStringContainsString(' OR ', (string) $db->currentWhere());
    }

    /**
     * The point of using the builder rather than query(): a value inside the
     * sub-query is still a bound parameter, not interpolated text.
     */
    public function testSubQueryValuesStayBound(): void
    {
        $db = new SubQueryProbe('users');

        $db->whereExists(function ($q): void {
            $q->table('orders')
              ->whereColumn('orders.user_id', 'users.id')
              ->where('total', '>', 100);
        });

        self::assertContains(100, $db->currentBinds());
        self::assertStringNotContainsString('100', (string) $db->currentWhere());
    }

    public function testBindingsFromTheSubQueryAndTheOuterQueryStayInOrder(): void
    {
        $db = new SubQueryProbe('users');

        $db->where('status', '=', 'active');
        $db->whereExists(function ($q): void {
            $q->table('orders')->where('total', '>', 100);
        });
        $db->where('country', '=', 'MY');

        self::assertSame(['active', 100, 'MY'], $db->currentBinds());
    }

    /** A closure that forgets the table would otherwise compile to `EXISTS (SELECT * FROM )`. */
    public function testASubQueryWithNoTableIsRejected(): void
    {
        $db = new SubQueryProbe('users');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('has no table');

        $db->whereExists(static function ($q): void {
            $q->where('total', '>', 100);
        });
    }

    /** The closure gets its own builder; touching it must not disturb the outer query. */
    public function testTheOuterQueryIsNotAlteredByTheSubQuery(): void
    {
        $db = new SubQueryProbe('users');

        $db->whereExists(function ($q): void {
            $q->table('orders')->limit(5)->orderBy('id', 'desc');
        });

        self::assertStringContainsString('EXISTS (', (string) $db->currentWhere());
        self::assertSame('users', (new \ReflectionProperty(BaseDatabase::class, 'table'))
            ->getValue($db));
    }

    // ─── joinSub ─────────────────────────────────────────────────────

    public function testJoinSubBuildsADerivedTable(): void
    {
        $db = new SubQueryProbe('users');

        $db->joinSub(
            static function ($q): void {
                $q->table('orders')->select('user_id')->groupBy('user_id');
            },
            'totals',
            'totals.user_id',
            'users.id'
        );

        $joins = (string) $db->currentJoins();

        self::assertStringContainsString('INNER JOIN (', $joins);
        self::assertStringContainsString('AS `totals`', $joins);
        self::assertStringContainsString('`totals`.`user_id` = `users`.`id`', $joins);
    }

    public function testLeftJoinSubUsesALeftJoin(): void
    {
        $db = new SubQueryProbe('users');

        $db->leftJoinSub(
            static fn($q) => $q->table('orders')->select('user_id'),
            'totals',
            'totals.user_id',
            'users.id'
        );

        self::assertStringContainsString('LEFT JOIN (', (string) $db->currentJoins());
    }

    public function testTheAliasIsQuotedRatherThanInterpolated(): void
    {
        $db = new SubQueryProbe('users');

        $this->expectException(\InvalidArgumentException::class);

        $db->joinSub(
            static fn($q) => $q->table('orders')->select('user_id'),
            'totals` ON 1=1 -- ',
            'totals.user_id',
            'users.id'
        );
    }

    public function testAnUnknownJoinTypeIsRejected(): void
    {
        $db = new SubQueryProbe('users');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid join type');

        $db->joinSub(
            static fn($q) => $q->table('orders')->select('user_id'),
            'totals',
            'totals.user_id',
            'users.id',
            'CROSS APPLY'
        );
    }

    /**
     * A derived table's placeholders sit ahead of the WHERE clause. Appending its
     * bindings after an existing where()'s would fill the placeholders with each
     * other's values, so the combination is refused rather than mis-bound.
     */
    public function testJoinSubAfterAWhereWithBindingsIsRefused(): void
    {
        $db = new SubQueryProbe('users');
        $db->where('status', '=', 'active');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('must be called before where()');

        $db->joinSub(
            static fn($q) => $q->table('orders')->where('total', '>', 100),
            'totals',
            'totals.user_id',
            'users.id'
        );
    }

    public function testABindinglessJoinSubAfterAWhereIsFine(): void
    {
        $db = new SubQueryProbe('users');
        $db->where('status', '=', 'active');

        $db->joinSub(
            static fn($q) => $q->table('orders')->select('user_id'),
            'totals',
            'totals.user_id',
            'users.id'
        );

        self::assertStringContainsString('INNER JOIN (', (string) $db->currentJoins());
        self::assertSame(['active'], $db->currentBinds());
    }

    public function testDerivedTableBindingsComeFirst(): void
    {
        $db = new SubQueryProbe('users');

        $db->joinSub(
            static fn($q) => $q->table('orders')->where('total', '>', 100),
            'totals',
            'totals.user_id',
            'users.id'
        );
        $db->where('status', '=', 'active');

        self::assertSame([100, 'active'], $db->currentBinds());
    }

    // ─── Lock modifiers ──────────────────────────────────────────────

    /**
     * Without SKIP LOCKED a second worker blocks on the first worker's rows, so
     * adding workers adds no throughput — the table cannot act as a queue.
     */
    public function testSkipLockedExtendsTheExclusiveLock(): void
    {
        $db = new SubQueryProbe('jobs');

        $db->lockForUpdate()->skipLocked();

        self::assertSame('FOR UPDATE SKIP LOCKED', $db->currentLock());
    }

    public function testNoWaitExtendsTheExclusiveLock(): void
    {
        $db = new SubQueryProbe('jobs');

        $db->lockForUpdate()->noWait();

        self::assertSame('FOR UPDATE NOWAIT', $db->currentLock());
    }

    /** A modifier on no lock at all is a silent no-op that reads as protection. */
    public function testSkipLockedWithoutALockIsRejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('lockForUpdate()');

        (new SubQueryProbe('jobs'))->skipLocked();
    }

    public function testSkipLockedOnASharedLockIsRejected(): void
    {
        $this->expectException(\LogicException::class);

        (new SubQueryProbe('jobs'))->sharedLock()->skipLocked();
    }

    // ─── insertOrIgnore ──────────────────────────────────────────────

    /** On MySQL it is a statement modifier, not a trailing clause. */
    public function testInsertIgnoreIsAModifierOnMysql(): void
    {
        $db = new SubQueryProbe('users');
        $db->markInsertIgnore();

        $sql = $db->buildInsertSql(['email' => 'a@example.com']);

        self::assertStringStartsWith('INSERT IGNORE INTO', $sql);
        self::assertStringNotContainsString('ON CONFLICT', $sql);
    }

    public function testAPlainInsertCarriesNoModifier(): void
    {
        $sql = (new SubQueryProbe('users'))->buildInsertSql(['email' => 'a@example.com']);

        self::assertStringStartsWith('INSERT INTO', $sql);
    }
}
