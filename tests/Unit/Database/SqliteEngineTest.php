<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use Core\Database\Drivers\SqliteDriver;
use Core\Database\QueryCache;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The query builder against a real database.
 *
 * Every other database test in this suite asserts on generated SQL strings,
 * which proves the builder emits what the author expected and nothing about
 * whether an engine accepts it. These run the statements.
 *
 * Skipped when pdo_sqlite is unavailable. Enable it with
 * `extension=pdo_sqlite` in php.ini — the DLL ships with the bundled PHP.
 */
final class SqliteEngineTest extends TestCase
{
    private string $file = '';

    private string $connection = '';

    public static function setUpBeforeClass(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is not loaded; enable extension=pdo_sqlite to run the engine tests.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->file = sys_get_temp_dir() . '/myth-engine-' . bin2hex(random_bytes(6)) . '.sqlite';
        $this->connection = 'sqlite_test_' . bin2hex(random_bytes(4));

        $pdo = $this->raw($this->db());

        $pdo->exec('CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT UNIQUE,
            age INTEGER,
            meta TEXT,
            created_at TEXT
        )');

        $pdo->exec('CREATE TABLE orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL REFERENCES users(id),
            total INTEGER NOT NULL
        )');

        $pdo->exec("INSERT INTO users (name, email, age, meta, created_at) VALUES
            ('ada', 'ada@example.test', 36, '{\"role\":\"admin\"}', '2026-03-09 14:25:00'),
            ('bob', 'bob@example.test', 28, '{\"role\":\"user\"}',  '2025-11-02 08:05:00'),
            ('cy',  'cy@example.test',  45, '{\"role\":\"user\"}',  '2026-03-09 21:40:00'),
            ('dee', 'dee@example.test', 22, NULL,                 '2026-01-15 10:00:00')");

        $pdo->exec('INSERT INTO orders (user_id, total) VALUES (1, 100), (1, 250), (3, 75)');
    }

    protected function tearDown(): void
    {
        /*
        | Hand the pooled connection back. Each test opens its own database
        | file under its own connection name, and the pool caps out at ten —
        | without this, the eleventh test fails on pool exhaustion rather than
        | on anything it was testing.
        */
        \Core\Database\ConnectionPool::removeConnection($this->connection);

        // The query cache outlives the process, so a stale entry from one test
        // would otherwise be served to the next.
        QueryCache::invalidateTable(['users', 'orders']);

        if ($this->file !== '') {
            @unlink($this->file);
        }

        parent::tearDown();
    }

    private function db(): SqliteDriver
    {
        $db = new SqliteDriver();
        $db->addConnection($this->connection, ['driver' => 'sqlite', 'database' => $this->file]);
        $db->connect($this->connection);

        return $db;
    }

    private function raw(SqliteDriver $db): \PDO
    {
        return (new \ReflectionProperty($db, 'pdo'))->getValue($db)[$this->connection];
    }

    // ─── Connection ──────────────────────────────────────────────────

    /** Off by default in SQLite, which makes a declared schema look enforced when it is not. */
    public function testForeignKeysAreEnforced(): void
    {
        $pdo = $this->raw($this->db());

        self::assertSame(1, (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn());

        $this->expectException(\PDOException::class);
        $pdo->exec('INSERT INTO orders (user_id, total) VALUES (999, 1)');
    }

    // ─── Reads ───────────────────────────────────────────────────────

    public function testWhere(): void
    {
        $rows = $this->db()->table('users')->where('name', 'ada')->get();

        self::assertCount(1, $rows);
        self::assertSame('ada@example.test', $rows[0]['email']);
    }

    public function testWhereWithAnOperator(): void
    {
        self::assertCount(2, $this->db()->table('users')->where('age', '>', 30)->get());
    }

    public function testWhereIn(): void
    {
        self::assertCount(2, $this->db()->table('users')->whereIn('name', ['ada', 'cy'])->get());
    }

    public function testWhereNullAndNotNull(): void
    {
        self::assertCount(1, $this->db()->table('users')->whereNull('meta')->get());
        self::assertCount(3, $this->db()->table('users')->whereNotNull('meta')->get());
    }

    public function testWhereBetween(): void
    {
        self::assertCount(2, $this->db()->table('users')->whereBetween('age', 25, 40)->get());
    }

    public function testOrderByWithLimit(): void
    {
        $rows = $this->db()->table('users')->orderBy('age', 'DESC')->limit(2)->get();

        self::assertCount(2, $rows);
        self::assertSame('cy', $rows[0]['name']);
    }

    public function testLimitWithOffset(): void
    {
        $rows = $this->db()->table('users')->orderBy('id', 'ASC')->limit(2)->offset(1)->get();

        self::assertSame(['bob', 'cy'], array_column($rows, 'name'));
    }

    /**
     * `SELECT … OFFSET 2` is a syntax error in SQLite — OFFSET exists only as a
     * suffix to LIMIT — so an offset with no limit carries the documented
     * LIMIT -1 sentinel.
     */
    public function testOffsetWithoutALimit(): void
    {
        $rows = $this->db()->table('users')->orderBy('id', 'ASC')->offset(2)->get();

        self::assertSame(['cy', 'dee'], array_column($rows, 'name'));
    }

    public function testInnerJoin(): void
    {
        $rows = $this->db()->table('users')
            ->select('users.name, orders.total')
            ->join('orders', 'orders.user_id', 'users.id', 'INNER')
            ->get();

        self::assertCount(3, $rows);
    }

    // ─── Aggregates ──────────────────────────────────────────────────

    public function testCount(): void
    {
        self::assertSame(4, $this->db()->table('users')->count());
    }

    public function testCountRespectsWhere(): void
    {
        self::assertSame(2, $this->db()->table('users')->where('age', '>', 30)->count());
    }

    public function testCountAcrossAJoin(): void
    {
        $count = $this->db()->table('users')
            ->join('orders', 'orders.user_id', 'users.id', 'INNER')
            ->count();

        self::assertSame(3, $count);
    }

    /** Wrapping the query, rather than rewriting it, is what makes this right. */
    public function testCountWithGroupByCountsGroupsNotRows(): void
    {
        self::assertSame(2, $this->db()->table('orders')->groupBy('user_id')->count());
    }

    public function testExists(): void
    {
        self::assertTrue($this->db()->table('users')->where('name', 'ada')->exists());
        self::assertFalse($this->db()->table('users')->where('name', 'nobody')->exists());
    }

    public function testExistsHonoursAJoin(): void
    {
        $withOrders = fn(string $name): bool => $this->db()->table('users')
            ->join('orders', 'orders.user_id', 'users.id', 'INNER')
            ->where('users.name', $name)
            ->exists();

        self::assertTrue($withOrders('ada'));
        self::assertFalse($withOrders('bob'), 'bob has no orders; a join-dropping exists() would say true');
    }

    // ─── Temporal ────────────────────────────────────────────────────

    /**
     * SQLite has no EXTRACT or YEAR(); these go through strftime(). Before the
     * driver declared its engine, the grammar seam silently resolved to MySQL
     * and emitted YEAR(), which is not a function here.
     */
    #[DataProvider('temporalCases')]
    public function testTemporalPredicates(string $method, mixed $value, int $expected): void
    {
        self::assertCount($expected, $this->db()->table('users')->{$method}('created_at', $value)->get());
    }

    /** @return array<string, array{0: string, 1: mixed, 2: int}> */
    public static function temporalCases(): array
    {
        return [
            'year' => ['whereYear', 2026, 3],
            'month' => ['whereMonth', 3, 2],
            'day' => ['whereDay', 9, 2],
            'date' => ['whereDate', '2026-03-09', 2],
        ];
    }

    public function testWhereJsonContains(): void
    {
        $rows = $this->db()->table('users')->whereJsonContains('meta', 'role', 'admin')->get();

        self::assertCount(1, $rows);
        self::assertSame('ada', $rows[0]['name']);
    }

    // ─── Writes ──────────────────────────────────────────────────────

    public function testInsert(): void
    {
        $this->db()->table('users')->insert(['name' => 'eve', 'email' => 'eve@example.test', 'age' => 31]);

        self::assertTrue($this->db()->table('users')->where('name', 'eve')->exists());
    }

    public function testUpdate(): void
    {
        $this->db()->table('users')->where('name', 'ada')->update(['age' => 37]);

        self::assertSame(37, (int) $this->db()->table('users')->where('name', 'ada')->fetch()['age']);
    }

    public function testDelete(): void
    {
        $this->db()->table('users')->where('name', 'dee')->delete();

        self::assertSame(3, $this->db()->table('users')->count());
    }

    public function testUpsertInsertsThenUpdates(): void
    {
        $this->db()->table('users')->upsert(
            ['name' => 'frank', 'email' => 'frank@example.test', 'age' => 50],
            'email'
        );

        self::assertSame(50, (int) $this->db()->table('users')->where('email', 'frank@example.test')->fetch()['age']);

        $this->db()->table('users')->upsert(
            ['name' => 'frank', 'email' => 'frank@example.test', 'age' => 51],
            'email'
        );

        self::assertSame(51, (int) $this->db()->table('users')->where('email', 'frank@example.test')->fetch()['age']);
        self::assertSame(1, $this->db()->table('users')->where('email', 'frank@example.test')->count());
    }

    // ─── Read after write ────────────────────────────────────────────

    /**
     * QueryCache is on by default and caches SELECT results. Three things had
     * to be true for a write to invalidate them, and none were: nothing called
     * invalidateTable(), generateKey() was never told which tables a query
     * touched, and without APCu the version stamp was a constant. So an update
     * followed by the same read returned the pre-update row.
     */
    public function testAReadAfterAnUpdateSeesTheNewValue(): void
    {
        self::assertSame(36, (int) $this->db()->table('users')->where('name', 'ada')->fetch()['age']);

        $this->db()->table('users')->where('name', 'ada')->update(['age' => 99]);

        self::assertSame(99, (int) $this->db()->table('users')->where('name', 'ada')->fetch()['age']);
    }

    public function testAReadAfterADeleteDoesNotSeeTheRow(): void
    {
        self::assertCount(4, $this->db()->table('users')->get());

        $this->db()->table('users')->where('name', 'dee')->delete();

        self::assertCount(3, $this->db()->table('users')->get());
    }

    public function testAReadAfterAnInsertSeesTheRow(): void
    {
        self::assertCount(4, $this->db()->table('users')->get());

        $this->db()->table('users')->insert(['name' => 'gil', 'email' => 'gil@example.test', 'age' => 40]);

        self::assertCount(5, $this->db()->table('users')->get());
    }

    // ─── Transactions ────────────────────────────────────────────────

    public function testATransactionRollsBack(): void
    {
        try {
            $this->db()->transaction(function (): void {
                $this->db()->table('users')->insert(['name' => 'ghost', 'email' => 'ghost@example.test', 'age' => 1]);
                throw new \RuntimeException('abort');
            });
        } catch (\RuntimeException) {
            // expected
        }

        self::assertFalse($this->db()->table('users')->where('name', 'ghost')->exists());
    }

    public function testATransactionCommits(): void
    {
        $this->db()->transaction(function (): void {
            $this->db()->table('users')->insert(['name' => 'kept', 'email' => 'kept@example.test', 'age' => 2]);
        });

        self::assertTrue($this->db()->table('users')->where('name', 'kept')->exists());
    }

    // ─── Pagination ──────────────────────────────────────────────────

    public function testSimplePaginateReportsHasMoreWithoutCounting(): void
    {
        $page = $this->db()->table('users')->orderBy('id', 'ASC')->simplePaginate(2, 1);

        self::assertCount(2, $page['data']);
        self::assertTrue($page['has_more']);
        self::assertSame(1, $page['from']);
        self::assertSame(2, $page['to']);
    }

    public function testSimplePaginateOnTheLastPage(): void
    {
        $page = $this->db()->table('users')->orderBy('id', 'ASC')->simplePaginate(2, 2);

        self::assertCount(2, $page['data']);
        self::assertFalse($page['has_more']);
        self::assertSame(3, $page['from']);
    }

    public function testSimplePaginatePastTheEnd(): void
    {
        $page = $this->db()->table('users')->orderBy('id', 'ASC')->simplePaginate(2, 99);

        self::assertSame([], $page['data']);
        self::assertFalse($page['has_more']);
        self::assertSame(0, $page['from']);
    }
}
