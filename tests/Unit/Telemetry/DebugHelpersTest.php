<?php

declare(strict_types=1);

namespace Tests\Unit\Telemetry;

use Core\Telemetry\Entry;
use Core\Telemetry\Gate;
use Core\Telemetry\Recorder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * dbg(), the timers and the query grouping — the debugging surface that exists
 * so nobody has to var_dump() into a response and change what they are
 * debugging.
 */
final class DebugHelpersTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/myth-dbg-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);

        parent::tearDown();
    }

    /** @param array<string, mixed> $config */
    private function recorder(array $config = []): Recorder
    {
        return new Recorder(array_merge([
            'enabled' => true,
            'mode' => Gate::MODE_ALL,
            'storage' => ['path' => $this->directory],
        ], $config));
    }

    /** @return list<Entry> */
    private function ofType(Recorder $recorder, string $type): array
    {
        return array_values(array_filter(
            $recorder->entries(),
            static fn(Entry $e): bool => $e->type === $type
        ));
    }

    // ─── dbg() ───────────────────────────────────────────────────────

    public function testADumpRecordsTheValueLabelAndOrigin(): void
    {
        $recorder = $this->recorder();
        $recorder->recordDump(['a' => 1], 'payload', 'app/Foo.php:12');

        $dump = $this->ofType($recorder, Entry::TYPE_DUMP)[0];

        self::assertSame('payload', $dump->payload['label']);
        self::assertSame('app/Foo.php:12', $dump->payload['origin']);
        self::assertSame('array', $dump->payload['type']);
        self::assertSame(['a' => 1], $dump->payload['value']);
    }

    #[DataProvider('scalarValues')]
    public function testScalarsAreRecordedAsThemselves(mixed $value, string $expectedType): void
    {
        $recorder = $this->recorder();
        $recorder->recordDump($value);

        $dump = $this->ofType($recorder, Entry::TYPE_DUMP)[0];

        self::assertSame($expectedType, $dump->payload['type']);
        self::assertSame($value, $dump->payload['value']);
    }

    /** @return array<string, array{0: mixed, 1: string}> */
    public static function scalarValues(): array
    {
        return [
            'int' => [42, 'int'],
            'string' => ['hello', 'string'],
            'bool' => [true, 'bool'],
            'float' => [1.5, 'float'],
            'null' => [null, 'null'],
        ];
    }

    /** Dumping a full object graph is how a debug tool becomes a memory problem. */
    public function testAnObjectIsRecordedAsItsClassAndPublicState(): void
    {
        $recorder = $this->recorder();
        $recorder->recordDump(new class () {
            public string $name = 'Ada';
            public int $count = 3;
        });

        $value = $this->ofType($recorder, Entry::TYPE_DUMP)[0]->payload['value'];

        self::assertArrayHasKey('_class', $value);
        self::assertSame('Ada', $value['name']);
        self::assertSame(3, $value['count']);
    }

    public function testAnObjectWithToArrayUsesIt(): void
    {
        $recorder = $this->recorder();
        $recorder->recordDump(new class () {
            public function toArray(): array
            {
                return ['id' => 7, 'via' => 'toArray'];
            }
        });

        $value = $this->ofType($recorder, Entry::TYPE_DUMP)[0]->payload['value'];

        self::assertSame('toArray', $value['via']);
        self::assertSame(7, $value['id']);
    }

    public function testAThrowableIsRecordedAsMessageAndLocation(): void
    {
        $recorder = $this->recorder();
        $recorder->recordDump(new \RuntimeException('nope'));

        $value = $this->ofType($recorder, Entry::TYPE_DUMP)[0]->payload['value'];

        self::assertSame(\RuntimeException::class, $value['_class']);
        self::assertSame('nope', $value['message']);
        self::assertStringContainsString('DebugHelpersTest.php:', $value['at']);
    }

    /** Dumps go through the same redaction as everything else. */
    public function testSecretsInADumpAreRedacted(): void
    {
        $recorder = $this->recorder();
        $recorder->recordDump(['email' => 'a@b.c', 'password' => 'hunter2']);

        $value = $this->ofType($recorder, Entry::TYPE_DUMP)[0]->payload['value'];

        self::assertSame('a@b.c', $value['email']);
        self::assertSame('[redacted]', $value['password']);
    }

    // ─── Timers ──────────────────────────────────────────────────────

    public function testATimerRecordsItsElapsedTime(): void
    {
        $recorder = $this->recorder();

        $recorder->startTimer('parse');
        usleep(3000);
        $elapsed = $recorder->stopTimer('parse', 'app/Foo.php:9');

        self::assertNotNull($elapsed);
        self::assertGreaterThan(1.0, $elapsed);

        $timer = $this->ofType($recorder, Entry::TYPE_TIMER)[0];

        self::assertSame('parse', $timer->payload['name']);
        self::assertSame('span', $timer->payload['kind']);
        self::assertSame('app/Foo.php:9', $timer->payload['origin']);
        self::assertGreaterThan(1.0, (float) $timer->durationMs);
    }

    /** A stop with no start is a caller bug; recording 0ms would hide it. */
    public function testStoppingATimerThatNeverStartedReturnsNull(): void
    {
        $recorder = $this->recorder();

        self::assertNull($recorder->stopTimer('never-started'));
        self::assertSame([], $this->ofType($recorder, Entry::TYPE_TIMER));
    }

    public function testAnUnclosedTimerIsReportedAtFlush(): void
    {
        $recorder = $this->recorder();
        $recorder->startTimer('abandoned');
        $recorder->flush();

        $stored = (new \Core\Telemetry\FileStore(['path' => $this->directory]))->read();
        $timers = array_values(array_filter($stored, static fn(Entry $e): bool => $e->type === Entry::TYPE_TIMER));

        self::assertCount(1, $timers);
        self::assertSame('unclosed', $timers[0]->payload['kind']);
        self::assertContains('unclosed', $timers[0]->tags);
    }

    public function testTimersAreIndependent(): void
    {
        $recorder = $this->recorder();

        $recorder->startTimer('outer');
        $recorder->startTimer('inner');
        $recorder->stopTimer('inner');
        $recorder->stopTimer('outer');

        $names = array_map(
            static fn(Entry $e): string => $e->payload['name'],
            $this->ofType($recorder, Entry::TYPE_TIMER)
        );

        self::assertSame(['inner', 'outer'], $names);
    }

    // ─── Counters ────────────────────────────────────────────────────

    /** Counting in a hot loop must cost an increment, not an entry each time. */
    public function testACounterProducesOneEntryHoweverOftenItIsBumped(): void
    {
        $recorder = $this->recorder();

        for ($i = 0; $i < 1000; $i++) {
            $recorder->increment('rows');
        }

        self::assertSame([], $this->ofType($recorder, Entry::TYPE_TIMER), 'nothing recorded while counting');
        self::assertSame(['rows' => 1000], $recorder->counters());

        $recorder->flush();

        $stored = (new \Core\Telemetry\FileStore(['path' => $this->directory]))->read();
        $counters = array_values(array_filter(
            $stored,
            static fn(Entry $e): bool => ($e->payload['kind'] ?? '') === 'counter'
        ));

        self::assertCount(1, $counters);
        self::assertSame(1000, $counters[0]->payload['count']);
    }

    public function testIncrementReturnsTheRunningTotal(): void
    {
        $recorder = $this->recorder();

        self::assertSame(1, $recorder->increment('n'));
        self::assertSame(3, $recorder->increment('n', 2));
    }

    // ─── Query grouping: the N+1 answer ──────────────────────────────

    /**
     * The whole point: the same query shape run many times with different
     * literals collapses to one row with a count.
     */
    public function testQueriesDifferingOnlyInLiteralsCollapseToOneShape(): void
    {
        $recorder = $this->recorder();

        for ($id = 1; $id <= 25; $id++) {
            $recorder->recordQuery('select * from users where id = ' . $id, [], 1.5);
        }
        $recorder->recordQuery('select * from orders', [], 4.0);

        $shapes = $recorder->queryShapes();

        self::assertCount(2, $shapes);
        self::assertSame(25, $shapes[0]['count'], 'busiest shape first');
        self::assertSame('select * from users where id = ?', $shapes[0]['sql']);
        self::assertEqualsWithDelta(37.5, $shapes[0]['total_ms'], 0.01);
        self::assertSame(1, $shapes[1]['count']);
    }

    #[DataProvider('equivalentStatements')]
    public function testFingerprintingNormalisesLiterals(string $a, string $b): void
    {
        self::assertSame(Recorder::fingerprint($a), Recorder::fingerprint($b));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function equivalentStatements(): array
    {
        return [
            'integers' => ['select * from t where id = 1', 'select * from t where id = 9999'],
            'strings' => ["select * from t where name = 'ada'", "select * from t where name = 'bob'"],
            'whitespace' => ["select  *\n from t", 'select * from t'],
            'in lists' => ['select * from t where id in (1, 2, 3)', 'select * from t where id in (7, 8)'],
        ];
    }

    public function testDifferentStatementsDoNotCollapse(): void
    {
        self::assertNotSame(
            Recorder::fingerprint('select * from users'),
            Recorder::fingerprint('select * from orders')
        );
    }

    public function testQueryShapesIsEmptyWithoutQueries(): void
    {
        $recorder = $this->recorder();
        $recorder->recordDump('just a dump');

        self::assertSame([], $recorder->queryShapes());
    }
}
