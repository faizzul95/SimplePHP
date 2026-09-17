<?php

declare(strict_types=1);

namespace Tests\Unit\Queue;

use Core\Queue\Bus;
use Core\Queue\ChainedJob;
use Core\Queue\Job;
use PHPUnit\Framework\TestCase;

/** Records the order links ran in, across job instances. */
final class ChainLog
{
    /** @var list<string> */
    public static array $ran = [];

    public static function reset(): void
    {
        self::$ran = [];
    }
}

final class ChainStep extends Job
{
    public function __construct(private string $name, private bool $explode = false)
    {
    }

    public function handle(): void
    {
        ChainLog::$ran[] = $this->name;

        if ($this->explode) {
            throw new \RuntimeException('step ' . $this->name . ' failed');
        }
    }
}

final class ChainTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ChainLog::reset();
    }

    protected function tearDown(): void
    {
        ChainLog::reset();
        parent::tearDown();
    }

    /**
     * Run a chain to completion without a queue, by following the tail the way
     * a worker would.
     */
    private function drain(ChainedJob $chain, int $guard = 20): void
    {
        $current = $chain;

        for ($i = 0; $i < $guard; $i++) {
            $jobs = $current->jobs();
            $head = array_shift($jobs);

            if (!$head instanceof Job) {
                return;
            }

            $head->handle();

            if ($jobs === []) {
                return;
            }

            $current = new ChainedJob($jobs);
        }

        self::fail('chain did not terminate');
    }

    // ─── Ordering ────────────────────────────────────────────────────

    public function testLinksRunInTheOrderGiven(): void
    {
        $this->drain(Bus::chain([
            new ChainStep('a'),
            new ChainStep('b'),
            new ChainStep('c'),
        ])->toJob());

        self::assertSame(['a', 'b', 'c'], ChainLog::$ran);
    }

    public function testASingleLinkChainStillRuns(): void
    {
        $this->drain(Bus::chain([new ChainStep('only')])->toJob());

        self::assertSame(['only'], ChainLog::$ran);
    }

    public function testAnEmptyChainIsAHarmlessNoOp(): void
    {
        $chain = Bus::chain([]);

        self::assertSame(0, $chain->count());
        self::assertNull($chain->go());
        self::assertSame([], ChainLog::$ran);
    }

    public function testNonJobsAreFilteredOut(): void
    {
        $chain = Bus::chain([new ChainStep('a'), 'not a job', null, new ChainStep('b')]);

        self::assertSame(2, $chain->count());
    }

    // ─── Failure stops the chain ─────────────────────────────────────

    /**
     * The point of a chain: if step two did not happen, step three must not
     * either.
     */
    public function testAFailingLinkStopsTheRest(): void
    {
        $chain = Bus::chain([
            new ChainStep('a'),
            new ChainStep('b', true),
            new ChainStep('c'),
        ])->toJob();

        $this->expectException(\RuntimeException::class);

        try {
            $this->drain($chain);
        } finally {
            self::assertSame(['a', 'b'], ChainLog::$ran, 'c must not have run');
        }
    }

    /** The failure propagates, so the worker's retry budget applies to it. */
    public function testTheFailureIsRethrownRatherThanSwallowed(): void
    {
        $chain = new ChainedJob([new ChainStep('boom', true)]);

        $this->expectExceptionMessage('step boom failed');

        $chain->handle();
    }

    // ─── Settings ────────────────────────────────────────────────────

    public function testTheChainInheritsTheHeadsQueue(): void
    {
        $head = new ChainStep('a');
        $head->queue = 'reports';
        $head->priority = Job::PRIORITY_HIGH;

        $chain = new ChainedJob([$head, new ChainStep('b')]);

        self::assertSame('reports', $chain->queue);
        self::assertSame(Job::PRIORITY_HIGH, $chain->priority);
    }

    public function testExplicitSettingsOverrideTheHead(): void
    {
        $head = new ChainStep('a');
        $head->queue = 'default';
        $head->tries = 2;

        $chain = Bus::chain([$head])->onQueue('slow')->delay(30)->tries(5)->toJob();

        self::assertSame('slow', $chain->queue);
        self::assertSame(30, $chain->delay);
        self::assertSame(5, $chain->tries);
    }

    /** What toJob() shows must be what go() would have queued. */
    public function testInspectingTheChainShowsTheSameSettingsThatWouldBeQueued(): void
    {
        $chain = Bus::chain([new ChainStep('a')])->onQueue('reports')->toJob();

        self::assertSame('reports', $chain->queue);
    }

    public function testThenAppendsALink(): void
    {
        $chain = Bus::chain([new ChainStep('a')])->then(new ChainStep('b'));

        self::assertSame(2, $chain->count());

        $this->drain($chain->toJob());

        self::assertSame(['a', 'b'], ChainLog::$ran);
    }

    // ─── Serialisation ───────────────────────────────────────────────

    /** A chain is queued like any job, so it has to survive the round trip. */
    public function testAChainSurvivesSerialisation(): void
    {
        $chain = new ChainedJob([new ChainStep('a'), new ChainStep('b')]);

        /** @var ChainedJob $restored */
        $restored = unserialize(serialize($chain));

        self::assertInstanceOf(ChainedJob::class, $restored);
        self::assertSame(2, $restored->remaining());

        $this->drain($restored);

        self::assertSame(['a', 'b'], ChainLog::$ran);
    }

    public function testRemainingCountsTheLinksLeft(): void
    {
        self::assertSame(3, (new ChainedJob([
            new ChainStep('a'), new ChainStep('b'), new ChainStep('c'),
        ]))->remaining());
    }
}
