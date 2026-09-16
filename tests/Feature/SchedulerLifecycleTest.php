<?php

declare(strict_types=1);

namespace Tests\Feature;

use Core\Console\Kernel;
use Core\Console\Schedule;
use Core\Console\ScheduleEvent;
use PHPUnit\Framework\TestCase;

/**
 * A Schedule that records what it dispatched instead of spawning processes.
 */
final class RecordingSchedule extends Schedule
{
    /** @var list<string> */
    public array $backgrounded = [];

    public bool $launchSucceeds = true;

    protected function runCommandInBackground(string $commandLine): void
    {
        $this->backgrounded[] = $commandLine;

        if (!$this->launchSucceeds) {
            throw new \RuntimeException('Failed to launch scheduled command in background: ' . $commandLine);
        }
    }
}

/**
 * The scheduler run loop, driven end to end against a real console Kernel.
 *
 * Two things it got wrong. A backgrounded task released its overlap lock in the
 * `finally` — which runs the instant the child is *launched*, not when it
 * finishes — so `withoutOverlapping()` permitted a second copy on the very next
 * minute, which is the one thing it exists to prevent. And the failure path
 * called flushOutput() behind a bare `ob_get_level()` check, so a task that
 * threw before opening its own buffer cleaned somebody else's.
 */
final class SchedulerLifecycleTest extends TestCase
{
    private string $lockDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        // ScheduleEvent::getLockPath() writes to storage/cache, keyed by md5 of
        // the command — so two tests scheduling the same command share a lock
        // file and one deliberately leaves it held.
        $this->lockDirectory = ROOT_DIR . 'storage/cache';
        $this->clearLocks();
    }

    protected function tearDown(): void
    {
        $this->clearLocks();
        parent::tearDown();
    }

    private function clearLocks(): void
    {
        foreach (glob($this->lockDirectory . '/schedule-*.lock') ?: [] as $file) {
            @unlink($file);
        }
    }

    /**
     * A kernel carrying one no-op command.
     *
     * A bare Kernel has an empty registry — the application's commands are
     * registered elsewhere — so relying on a real command name would make these
     * tests fail for a reason that has nothing to do with the scheduler.
     */
    private function kernel(): Kernel
    {
        $kernel = new Kernel();
        $kernel->command('noop:task', static fn(): int => 0, 'Does nothing.');

        return $kernel;
    }

    /** Force an event to be due regardless of the clock. */
    private function makeDue(ScheduleEvent $event): ScheduleEvent
    {
        return $event->cron('* * * * *');
    }

    // ─── The happy path ──────────────────────────────────────────────

    public function testAClosureEventRuns(): void
    {
        $schedule = new RecordingSchedule();
        $ran = false;

        $this->makeDue($schedule->call(static function () use (&$ran): void {
            $ran = true;
        }));

        $result = $schedule->runDueEvents($this->kernel());

        self::assertTrue($ran);
        self::assertSame(1, $result['ran']);
        self::assertSame(0, $result['failed']);
    }

    public function testAnEventThatIsNotDueDoesNotRun(): void
    {
        $schedule = new RecordingSchedule();
        $ran = false;

        // 31 February never arrives.
        $schedule->call(static function () use (&$ran): void {
            $ran = true;
        })->cron('0 0 31 2 *');

        $result = $schedule->runDueEvents($this->kernel());

        self::assertFalse($ran);
        self::assertSame(0, $result['ran']);
    }

    public function testAThrowingTaskIsRecordedAsFailedAndDoesNotStopTheRun(): void
    {
        $schedule = new RecordingSchedule();
        $secondRan = false;

        $this->makeDue($schedule->call(static fn() => throw new \RuntimeException('boom')));
        $this->makeDue($schedule->call(static function () use (&$secondRan): void {
            $secondRan = true;
        }));

        $result = $schedule->runDueEvents($this->kernel());

        self::assertSame(1, $result['failed']);
        self::assertSame(1, $result['ran']);
        self::assertTrue($secondRan, 'One failing task must not abandon the rest of the schedule.');
    }

    public function testAnUnknownCommandFailsRatherThanBeingSilentlySkipped(): void
    {
        $schedule = new RecordingSchedule();
        $this->makeDue($schedule->command('does:not:exist'));

        $result = $schedule->runDueEvents($this->kernel());

        self::assertSame(1, $result['failed']);
        self::assertStringContainsString('not found', $result['results'][0]['error']);
    }

    // ─── Overlap prevention ──────────────────────────────────────────

    public function testASecondRunIsSkippedWhileTheFirstHoldsTheLock(): void
    {
        $schedule = new RecordingSchedule();

        $event = $this->makeDue($schedule->call(static fn() => null))->withoutOverlapping();
        self::assertTrue($event->acquireLock(), 'A fresh lock must be acquirable.');

        // A second scheduler process arriving while the first still holds it.
        $result = $schedule->runDueEvents($this->kernel());

        self::assertSame(1, $result['skipped']);
        self::assertSame('overlapping', $result['results'][0]['reason']);

        $event->releaseLock();
    }

    public function testTheLockIsReleasedAfterAForegroundTaskFinishes(): void
    {
        $schedule = new RecordingSchedule();
        $event = $this->makeDue($schedule->call(static fn() => null))->withoutOverlapping();

        $schedule->runDueEvents($this->kernel());

        // Acquirable again means the previous run let go of it.
        self::assertTrue($event->acquireLock());
        $event->releaseLock();
    }

    /** Released even when the task threw — otherwise one failure wedges it forever. */
    public function testTheLockIsReleasedAfterAForegroundTaskFails(): void
    {
        $schedule = new RecordingSchedule();
        $event = $this->makeDue($schedule->call(static fn() => throw new \RuntimeException('boom')))
            ->withoutOverlapping();

        $schedule->runDueEvents($this->kernel());

        self::assertTrue($event->acquireLock());
        $event->releaseLock();
    }

    /**
     * The bug this file exists for. The child outlives this process, so
     * releasing the lock here releases it at *launch* — and the next minute's
     * scheduler run starts a second copy while the first is still working.
     */
    public function testABackgroundedTaskKeepsItsLockAfterLaunch(): void
    {
        $schedule = new RecordingSchedule();
        $event = $this->makeDue($schedule->command('noop:task'))
            ->withoutOverlapping()
            ->runInBackground();

        $result = $schedule->runDueEvents($this->kernel());

        self::assertSame(1, $result['ran']);
        self::assertSame(['noop:task'], $schedule->backgrounded);
        self::assertFalse(
            $event->acquireLock(),
            'The lock must still be held: the child is only just starting.'
        );
    }

    /** If the launch itself failed there is no child, so the lock must come back. */
    public function testAFailedBackgroundLaunchReleasesTheLock(): void
    {
        $schedule = new RecordingSchedule();
        $schedule->launchSucceeds = false;

        $event = $this->makeDue($schedule->command('noop:task'))
            ->withoutOverlapping()
            ->runInBackground();

        $result = $schedule->runDueEvents($this->kernel());

        self::assertSame(1, $result['failed']);
        self::assertTrue($event->acquireLock(), 'Nothing is running, so nothing should hold the lock.');
        $event->releaseLock();
    }

    public function testAnEventWithoutOverlapProtectionAlwaysRuns(): void
    {
        $schedule = new RecordingSchedule();
        $runs = 0;

        $this->makeDue($schedule->call(static function () use (&$runs): void {
            $runs++;
        }));

        $schedule->runDueEvents($this->kernel());
        $schedule->runDueEvents($this->kernel());

        self::assertSame(2, $runs);
    }

    // ─── Output capture ──────────────────────────────────────────────

    /**
     * A task that throws before startOutputCapture() used to reach flushOutput()
     * with only an ob_get_level() guard, cleaning a buffer it never opened.
     */
    public function testAFailureDoesNotStealAnOuterOutputBuffer(): void
    {
        $schedule = new RecordingSchedule();
        $logFile = ROOT_DIR . 'storage/framework/schedule/test-output.log';

        $this->makeDue($schedule->call(static fn() => throw new \RuntimeException('boom')))
            ->before(static fn() => throw new \RuntimeException('failed before capture started'))
            ->sendOutputTo($logFile);

        ob_start();
        echo 'belongs to the caller';

        $schedule->runDueEvents($this->kernel());

        $captured = ob_get_clean();

        self::assertSame('belongs to the caller', $captured, 'The outer buffer must survive.');
        @unlink($logFile);
    }

    public function testATaskWithNoOutputPathOpensNoBuffer(): void
    {
        $schedule = new RecordingSchedule();
        $depthInside = null;

        $this->makeDue($schedule->call(static function () use (&$depthInside): void {
            $depthInside = ob_get_level();
        }));

        $depthBefore = ob_get_level();
        $schedule->runDueEvents($this->kernel());

        self::assertSame($depthBefore, $depthInside);
        self::assertSame($depthBefore, ob_get_level());
    }
}
