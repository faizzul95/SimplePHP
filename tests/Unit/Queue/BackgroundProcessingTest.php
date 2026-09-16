<?php

declare(strict_types=1);

namespace Tests\Unit\Queue;

use Core\Queue\Job;
use Core\Queue\RestartSignal;
use Core\Queue\Supervisor;
use Core\Queue\UniqueLock;
use PHPUnit\Framework\TestCase;

final class PlainJob extends Job
{
    public function handle(): void
    {
    }
}

final class UniqueReportJob extends Job
{
    public function __construct(private int $reportId = 1)
    {
    }

    public function handle(): void
    {
    }

    public function uniqueId(): ?string
    {
        return 'report-' . $this->reportId;
    }

    public function uniqueFor(): int
    {
        return 120;
    }
}

final class OtherUniqueJob extends Job
{
    public function handle(): void
    {
    }

    public function uniqueId(): ?string
    {
        return 'report-1';
    }
}

/**
 * Background work had three gaps that matter as soon as more than one process is
 * involved: no way to reload workers after a deploy, no protection against the
 * same job being queued twice by parallel producers, and no supervision, so a
 * dead worker meant a silently stalled queue.
 */
final class BackgroundProcessingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RestartSignal::clear();
    }

    protected function tearDown(): void
    {
        RestartSignal::clear();
        parent::tearDown();
    }

    // ─── Graceful restart ────────────────────────────────────────────

    public function testNoRestartIsRequestedByDefault(): void
    {
        self::assertNull(RestartSignal::lastRequestedAt());
        self::assertFalse(RestartSignal::shouldRestart(null));
    }

    public function testAWorkerStartedBeforeTheSignalMustRestart(): void
    {
        $bootedWith = RestartSignal::lastRequestedAt(); // null

        RestartSignal::request(1_700_000_000);

        self::assertTrue(
            RestartSignal::shouldRestart($bootedWith),
            'A worker booted before the deploy is running the old code.'
        );
    }

    public function testAWorkerStartedAfterTheSignalKeepsRunning(): void
    {
        RestartSignal::request(1_700_000_000);

        // This worker booted after the signal, so it already has the new code.
        $bootedWith = RestartSignal::lastRequestedAt();

        self::assertFalse(RestartSignal::shouldRestart($bootedWith));
    }

    public function testASecondDeployRestartsWorkersStartedAfterTheFirst(): void
    {
        RestartSignal::request(1_700_000_000);
        $bootedWith = RestartSignal::lastRequestedAt();

        RestartSignal::request(1_700_000_500);

        self::assertTrue(RestartSignal::shouldRestart($bootedWith));
    }

    public function testClearRemovesTheSignal(): void
    {
        RestartSignal::request(1_700_000_000);
        RestartSignal::clear();

        self::assertNull(RestartSignal::lastRequestedAt());
        self::assertFalse(RestartSignal::shouldRestart(null));
    }

    public function testRequestDefaultsToNow(): void
    {
        $before = time();
        $recorded = RestartSignal::request();

        self::assertGreaterThanOrEqual($before, $recorded);
    }

    // ─── Unique jobs ─────────────────────────────────────────────────

    public function testAJobWithoutUniqueIdHasNoLockKey(): void
    {
        self::assertNull(UniqueLock::keyFor(new PlainJob()));
    }

    public function testAUniqueJobProducesAStableKey(): void
    {
        $first = UniqueLock::keyFor(new UniqueReportJob(7));
        $second = UniqueLock::keyFor(new UniqueReportJob(7));

        self::assertNotNull($first);
        self::assertSame($first, $second);
    }

    public function testDifferentIdsProduceDifferentKeys(): void
    {
        self::assertNotSame(
            UniqueLock::keyFor(new UniqueReportJob(1)),
            UniqueLock::keyFor(new UniqueReportJob(2))
        );
    }

    public function testTwoJobClassesSharingAnIdDoNotCollide(): void
    {
        // Both return 'report-1'; the class must be part of the key.
        self::assertNotSame(
            UniqueLock::keyFor(new UniqueReportJob(1)),
            UniqueLock::keyFor(new OtherUniqueJob())
        );
    }

    public function testOnlyTheFirstClaimWins(): void
    {
        $key = UniqueLock::keyForClass(UniqueReportJob::class, 'race');

        self::assertTrue(UniqueLock::acquire($key, 60), 'The first producer queues the job.');
        self::assertFalse(UniqueLock::acquire($key, 60), 'A parallel producer must be turned away.');
        self::assertTrue(UniqueLock::isLocked($key));

        UniqueLock::release($key);
    }

    public function testReleasingAllowsTheJobToBeQueuedAgain(): void
    {
        $key = UniqueLock::keyForClass(UniqueReportJob::class, 'cycle');

        UniqueLock::acquire($key, 60);
        UniqueLock::release($key);

        self::assertFalse(UniqueLock::isLocked($key));
        self::assertTrue(UniqueLock::acquire($key, 60), 'Once the work is done the key must be reusable.');

        UniqueLock::release($key);
    }

    public function testTheDeclaredTtlIsUsed(): void
    {
        self::assertSame(120, UniqueLock::ttlFor(new UniqueReportJob()));
        self::assertSame(UniqueLock::DEFAULT_TTL, UniqueLock::ttlFor(new PlainJob()));
    }

    public function testTheUniqueKeyTravelsWithThePayloadSoTheWorkerCanReleaseIt(): void
    {
        $payload = (new UniqueReportJob(9))->toPayload();

        self::assertArrayHasKey('unique_key', $payload);
        self::assertSame(UniqueLock::keyFor(new UniqueReportJob(9)), $payload['unique_key']);
    }

    public function testAPlainJobPayloadCarriesNoUniqueKey(): void
    {
        self::assertArrayNotHasKey('unique_key', (new PlainJob())->toPayload());
    }

    public function testAnEmptyKeyIsTreatedAsNoConstraint(): void
    {
        // No cache coordination is possible without a key; allowing the dispatch
        // is the right failure mode, because dropping jobs is worse than
        // occasionally running one twice.
        self::assertTrue(UniqueLock::acquire('', 60));
        self::assertFalse(UniqueLock::isLocked(''));
    }

    // ─── Parallel supervision ────────────────────────────────────────

    public function testTheWorkerCommandTargetsQueueWork(): void
    {
        $command = (new Supervisor())->workerCommand('emails');

        self::assertSame(PHP_BINARY, $command[0]);
        self::assertStringEndsWith('myth', $command[1]);
        self::assertSame('queue:work', $command[2]);
        self::assertSame('emails', $command[3]);
    }

    public function testWorkerOptionsArePassedThrough(): void
    {
        $command = (new Supervisor())->workerCommand('default', [
            'sleep' => 1,
            'tries' => 5,
            'timeout' => 120,
        ]);

        self::assertContains('--sleep=1', $command);
        self::assertContains('--tries=5', $command);
        self::assertContains('--timeout=120', $command);
    }

    public function testTheWorkersOptionIsNotForwardedToTheWorker(): void
    {
        // --workers belongs to the supervisor; queue:work would reject it.
        $command = (new Supervisor())->workerCommand('default', ['workers' => 8]);

        self::assertSame([], array_filter($command, static fn(string $arg): bool => str_contains($arg, 'workers')));
    }

    public function testEmptyOptionValuesAreOmitted(): void
    {
        $command = (new Supervisor())->workerCommand('default', ['sleep' => '', 'tries' => 3]);

        self::assertSame([], array_filter($command, static fn(string $arg): bool => str_contains($arg, 'sleep')));
        self::assertContains('--tries=3', $command);
    }
}
