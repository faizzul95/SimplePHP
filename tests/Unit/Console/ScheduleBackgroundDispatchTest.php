<?php

declare(strict_types=1);

use Core\Console\Kernel;
use Core\Console\Schedule;
use PHPUnit\Framework\TestCase;

final class ScheduleBackgroundDispatchProbe extends Schedule
{
    public bool $windowsLaunchResult = true;
    public bool $unixLaunchResult = true;

    protected function launchWindowsBackgroundCommand(string $command): bool
    {
        return $this->windowsLaunchResult;
    }

    protected function launchUnixBackgroundCommand(string $command): bool
    {
        return $this->unixLaunchResult;
    }
}

final class ScheduleBackgroundDispatchTest extends TestCase
{
    public function testBackgroundLaunchFailureMarksEventAsFailed(): void
    {
        $schedule = new ScheduleBackgroundDispatchProbe();
        $event = $schedule->command('demo:run')->runInBackground();

        $failureSignals = 0;
        $successSignals = 0;
        $event->onFailure(static function () use (&$failureSignals): void {
            $failureSignals++;
        });
        $event->onSuccess(static function () use (&$successSignals): void {
            $successSignals++;
        });

        if (stripos(PHP_OS_FAMILY, 'Windows') === 0) {
            $schedule->windowsLaunchResult = false;
        } else {
            $schedule->unixLaunchResult = false;
        }

        $kernel = new Kernel();
        $kernel->command('demo:run', static fn (): int => 0, 'Demo command');

        $result = $schedule->runDueEvents($kernel, [$event]);

        self::assertSame(0, $result['ran']);
        self::assertSame(1, $result['failed']);
        self::assertSame('failed', $result['results'][0]['status']);
        self::assertStringContainsString('Failed to launch scheduled command in background', $result['results'][0]['error']);
        self::assertSame(1, $failureSignals);
        self::assertSame(0, $successSignals);
    }

    public function testBackgroundLaunchSuccessMarksEventAsSuccessful(): void
    {
        $schedule = new ScheduleBackgroundDispatchProbe();
        $event = $schedule->command('demo:run')->runInBackground();

        $kernel = new Kernel();
        $kernel->command('demo:run', static fn (): int => 0, 'Demo command');

        $result = $schedule->runDueEvents($kernel, [$event]);

        self::assertSame(1, $result['ran']);
        self::assertSame(0, $result['failed']);
        self::assertSame('success', $result['results'][0]['status']);
    }
}