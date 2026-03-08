<?php

namespace Core\Console;

/**
 * Schedule — Laravel-like task scheduling manager.
 *
 * Manages a collection of ScheduleEvent objects. Provides a fluent API
 * for registering commands and closures, then running all due tasks.
 *
 * Usage in app/routes/console.php:
 *   $schedule->command('backup:run')->daily()->at('02:00')->description('Daily backup');
 *   $schedule->command('cache:clear')->weekly()->sundays()->at('03:00');
 *   $schedule->call(function () { ... })->everyFiveMinutes();
 *
 * Cron entry (run every minute):
 *   * * * * * cd /path-to-project && php myth schedule:run >> /dev/null 2>&1
 */
class Schedule
{
    /** @var ScheduleEvent[] */
    private array $events = [];

    /** @var array<string, callable> Registered closures keyed by internal name */
    private array $closureCallbacks = [];

    private int $closureCounter = 0;

    /**
     * Schedule a registered console command
     */
    public function command(string $command): ScheduleEvent
    {
        $event = new ScheduleEvent($command);
        $this->events[] = $event;
        return $event;
    }

    /**
     * Schedule a closure/callable to run
     */
    public function call(callable $callback): ScheduleEvent
    {
        $name = '__closure_' . (++$this->closureCounter);
        $this->closureCallbacks[$name] = $callback;

        $event = new ScheduleEvent($name);
        $this->events[] = $event;
        return $event;
    }

    /**
     * Get all registered events
     *
     * @return ScheduleEvent[]
     */
    public function events(): array
    {
        return $this->events;
    }

    /**
     * Get events that are due to run now
     *
     * @return ScheduleEvent[]
     */
    public function dueEvents(): array
    {
        return array_filter($this->events, function (ScheduleEvent $event) {
            return $event->isDue() && $event->filtersPass();
        });
    }

    /**
     * Run all due scheduled events
     *
     * @param Kernel $kernel The console kernel (to look up command handlers)
     * @return array{ran: int, failed: int, skipped: int, results: array}
     */
    public function runDueEvents(Kernel $kernel): array
    {
        $dueEvents = $this->dueEvents();
        $results = [];
        $ran = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($dueEvents as $event) {
            $commandName = $event->getCommand();

            // Check overlap lock
            if (!$event->acquireLock()) {
                $skipped++;
                $results[] = [
                    'command' => $commandName,
                    'status' => 'skipped',
                    'reason' => 'overlapping',
                ];
                continue;
            }

            try {
                $event->callBeforeCallbacks();
                $event->startOutputCapture();

                // Execute the task
                if (isset($this->closureCallbacks[$commandName])) {
                    // Closure-based event
                    call_user_func($this->closureCallbacks[$commandName]);
                } elseif ($kernel->hasCommand($commandName)) {
                    // Registered console command
                    $commands = $kernel->getCommands();
                    call_user_func($commands[$commandName]['handler'], [], []);
                } else {
                    throw new \RuntimeException("Scheduled command not found: {$commandName}");
                }

                $event->flushOutput();
                $event->callAfterCallbacks(true);

                $ran++;
                $results[] = [
                    'command' => $commandName,
                    'status' => 'success',
                    'description' => $event->getDescription(),
                ];
            } catch (\Throwable $e) {
                // Ensure output buffer is flushed even on failure
                if (ob_get_level() > 0) {
                    $event->flushOutput();
                }

                $event->callAfterCallbacks(false);

                $failed++;
                $results[] = [
                    'command' => $commandName,
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                ];

                // Log the error
                if (function_exists('logger')) {
                    logger()->logException($e);
                }
            } finally {
                $event->releaseLock();
            }
        }

        return [
            'ran' => $ran,
            'failed' => $failed,
            'skipped' => $skipped,
            'results' => $results,
        ];
    }

    /**
     * Check if there are any registered events
     */
    public function isEmpty(): bool
    {
        return empty($this->events);
    }

    /**
     * Get a closure callback by internal name
     */
    public function getClosureCallback(string $name): ?callable
    {
        return $this->closureCallbacks[$name] ?? null;
    }
}
