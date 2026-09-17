<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use Core\Database\Drivers\SqliteDriver;
use Core\Database\QueryCache;
use Core\Database\Query\Grammars\MySQLGrammar;
use Core\Database\Query\Grammars\SqliteGrammar;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Defects found by driving the builder's public surface against a real
 * database. Each of these passed every string-level assertion in the suite.
 */
final class BuilderDefectsTest extends TestCase
{
    private string $file = '';

    private string $connection = '';

    public static function setUpBeforeClass(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is not loaded; enable extension=pdo_sqlite.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->file = sys_get_temp_dir() . '/myth-defects-' . bin2hex(random_bytes(6)) . '.sqlite';
        $this->connection = 'defects_' . bin2hex(random_bytes(4));

        $pdo = $this->raw($this->boot());
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, email TEXT, age INTEGER)');
        $pdo->exec("INSERT INTO users (name, email, age) VALUES
            ('ada','ada@e.test',36),('bob','bob@e.test',28),('cy','cy@e.test',45),('dee','dee@e.test',22)");
    }

    protected function tearDown(): void
    {
        \Core\Database\ConnectionPool::removeConnection($this->connection);
        QueryCache::invalidateTable(['users']);

        if ($this->file !== '') { @unlink($this->file); }

        parent::tearDown();
    }

    private function boot(): SqliteDriver
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

    private function users(): SqliteDriver
    {
        return $this->boot()->table('users');
    }

    // ─── orderBy defaulted to DESC ───────────────────────────────────

    /**
     * `orderBy('name')` reads as ascending, SQL defaults to ascending, and
     * BuilderStatementInterface declared ASC. The implementation defaulted to
     * DESC, so it silently returned the reverse.
     */
    public function testOrderByDefaultsToAscending(): void
    {
        $names = array_column($this->users()->orderBy('name')->get(), 'name');

        self::assertSame(['ada', 'bob', 'cy', 'dee'], $names);
    }

    public function testOrderByStillHonoursAnExplicitDirection(): void
    {
        $names = array_column($this->users()->orderBy('name', 'DESC')->get(), 'name');

        self::assertSame(['dee', 'cy', 'bob', 'ada'], $names);
    }

    public function testTheInterfaceAndImplementationAgreeOnTheDefault(): void
    {
        $interface = new \ReflectionMethod(
            \Core\Database\Interface\BuilderStatementInterface::class,
            'orderBy'
        );
        $implementation = new \ReflectionMethod(SqliteDriver::class, 'orderBy');

        self::assertSame(
            $interface->getParameters()[1]->getDefaultValue(),
            $implementation->getParameters()[1]->getDefaultValue()
        );
    }

    // ─── inRandomOrder emitted MySQL's RAND() ────────────────────────

    public function testInRandomOrderUsesTheEnginesOwnFunction(): void
    {
        // RAND() does not exist in SQLite; this threw before the grammar was consulted.
        self::assertCount(4, $this->users()->inRandomOrder()->get());
    }

    public function testEachGrammarSpellsRandomOrderItsOwnWay(): void
    {
        self::assertSame('RANDOM()', (new SqliteGrammar())->compileRandomOrder());
        self::assertSame('RAND()', (new MySQLGrammar())->compileRandomOrder());
    }

    // ─── TRUNCATE does not exist in SQLite ───────────────────────────

    public function testTruncateEmptiesTheTable(): void
    {
        $this->users()->truncate();

        self::assertSame(0, $this->users()->count());
    }

    public function testEachGrammarSpellsTruncateItsOwnWay(): void
    {
        self::assertSame('DELETE FROM "t"', (new SqliteGrammar())->compileTruncate('"t"'));
        self::assertSame('TRUNCATE `t`', (new MySQLGrammar())->compileTruncate('`t`'));
    }

    // ─── ANALYZE TABLE is MySQL-only ─────────────────────────────────

    public function testAnalyzeSucceedsOnAnEngineThatReturnsNoStatusRows(): void
    {
        // `ANALYZE TABLE x` is a syntax error here, and reading Msg_text off an
        // empty result reported a working analyze as a failure.
        self::assertTrue($this->users()->analyze());
    }

    public function testOnlyMysqlReportsAnalyzeAsRows(): void
    {
        self::assertTrue((new MySQLGrammar())->analyzeReturnsStatusRows());
        self::assertFalse((new SqliteGrammar())->analyzeReturnsStatusRows());
    }

    // ─── whereAny/All/None refused the operator they exist for ───────

    /**
     * Searching a term across several columns is what whereAny is for, and it
     * validated against the bare comparison set — refusing LIKE, which the
     * where() it delegates to accepts.
     */
    public function testWhereAnyAcceptsLike(): void
    {
        $rows = $this->users()->whereAny(['name', 'email'], 'LIKE', 'ada%')->get();

        self::assertCount(1, $rows);
        self::assertSame('ada', $rows[0]['name']);
    }

    public function testWhereAllAcceptsLike(): void
    {
        self::assertIsArray($this->users()->whereAll(['name', 'email'], 'LIKE', '%a%')->get());
    }

    public function testWhereNoneAcceptsLike(): void
    {
        self::assertCount(4, $this->users()->whereNone(['name'], 'LIKE', 'zz%')->get());
    }

    /** Operators that cannot work in a single binary clause are still refused. */
    public function testWhereColumnStillRefusesSetOperators(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->users()->whereColumn('age', 'BETWEEN', 'id')->get();
    }

    // ─── skip()->take() produced unparseable SQL ─────────────────────

    /**
     * offset() writes SQLite's no-limit sentinel, and limit() appended its own
     * clause after it — `LIMIT 2 LIMIT -1 OFFSET 1`. Only this call order was
     * affected, which is why take()->skip() worked and skip()->take() did not.
     */
    #[DataProvider('limitOffsetOrders')]
    public function testLimitAndOffsetInEitherOrder(string $first): void
    {
        $query = $this->users()->orderBy('id');

        if ($first === 'offset') {
            $query->skip(1)->take(2);
        } else {
            $query->take(2)->skip(1);
        }

        $names = array_column($query->get(), 'name');

        self::assertSame(['bob', 'cy'], $names, 'call order must not change the result');
    }

    /** @return array<string, array{0: string}> */
    public static function limitOffsetOrders(): array
    {
        return ['limit first' => ['limit'], 'offset first' => ['offset']];
    }

    public function testForPage(): void
    {
        $names = array_column($this->users()->orderBy('id')->forPage(2, 2)->get(), 'name');

        self::assertSame(['cy', 'dee'], $names);
    }

    // ─── toJson() and toObject() did nothing ─────────────────────────

    /**
     * Both set $returnType, which reset() cleared inside the select pipeline
     * before the formatter read it — so two documented ResultInterface methods
     * silently returned plain arrays.
     */
    public function testToJsonReturnsJson(): void
    {
        $json = $this->users()->orderBy('id')->toJson()->get();

        self::assertIsString($json);
        self::assertSame('ada', json_decode($json, true)[0]['name']);
    }

    public function testToJsonOnASingleRow(): void
    {
        $json = $this->users()->where('name', 'ada')->toJson()->fetch();

        self::assertIsString($json);
        self::assertSame('ada', json_decode($json, true)['name']);
    }

    public function testToObjectReturnsObjects(): void
    {
        $rows = $this->users()->orderBy('id')->toObject()->get();

        self::assertIsObject($rows[0]);
        self::assertSame('ada', $rows[0]->name);
    }

    public function testToArrayIsStillTheDefault(): void
    {
        self::assertIsArray($this->users()->get()[0]);
    }

    /** A format asked for once must not persist into the next query. */
    public function testTheRequestedFormatDoesNotLeakIntoTheNextQuery(): void
    {
        self::assertIsString($this->users()->toJson()->get());
        self::assertIsArray($this->users()->get());
    }

    /** An empty result must not leave the format armed either. */
    public function testAnEmptyResultDoesNotLeaveTheFormatArmed(): void
    {
        $this->users()->where('name', 'nobody')->toJson()->get();

        self::assertIsArray($this->users()->get(), 'the next query should be unaffected');
    }
}
