<?php

namespace Core\Console;

/**
 * ScheduleEvent — represents a single scheduled task with Laravel-like fluent API.
 *
 * Supports cron expressions, fluent frequency helpers, day/time constraints,
 * overlap prevention, conditional execution, output handling, and lifecycle hooks.
 */
class ScheduleEvent
{
    private string $command;
    private string $expression = '* * * * *';
    private string $description = '';
    private ?string $timezone = null;

    /** @var callable|null */
    private $whenCallback = null;

    /** @var callable|null */
    private $skipCallback = null;

    private bool $preventOverlapping = false;
    private int $expiresAt = 1440; // minutes

    private ?string $outputPath = null;
    private bool $appendOutput = false;

    /** @var callable|null */
    private $beforeCallback = null;

    /** @var callable|null */
    private $afterCallback = null;

    /** @var callable|null */
    private $onSuccessCallback = null;

    /** @var callable|null */
    private $onFailureCallback = null;

    private array $environments = [];
    private bool $runInMaintenanceMode = false;
    private bool $runInBackground = false;

    public function __construct(string $command)
    {
        $this->command = $command;
    }

    // ─── Getters ─────────────────────────────────────────────

    public function getCommand(): string
    {
        return $this->command;
    }

    public function getExpression(): string
    {
        return $this->expression;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getSummary(): string
    {
        return $this->description ?: $this->command;
    }

    // ─── Cron Expression ─────────────────────────────────────

    /**
     * Set an explicit cron expression
     */
    public function cron(string $expression): self
    {
        $this->expression = $expression;
        return $this;
    }

    // ─── Frequency Helpers ───────────────────────────────────

    public function everyMinute(): self
    {
        return $this->cron('* * * * *');
    }

    public function everyTwoMinutes(): self
    {
        return $this->cron('*/2 * * * *');
    }

    public function everyThreeMinutes(): self
    {
        return $this->cron('*/3 * * * *');
    }

    public function everyFiveMinutes(): self
    {
        return $this->cron('*/5 * * * *');
    }

    public function everyTenMinutes(): self
    {
        return $this->cron('*/10 * * * *');
    }

    public function everyFifteenMinutes(): self
    {
        return $this->cron('*/15 * * * *');
    }

    public function everyThirtyMinutes(): self
    {
        return $this->cron('*/30 * * * *');
    }

    public function hourly(): self
    {
        return $this->cron('0 * * * *');
    }

    public function hourlyAt(int $minutes): self
    {
        $minutes = max(0, min(59, $minutes));
        return $this->cron("{$minutes} * * * *");
    }

    public function everyOddHour(int $minutes = 0): self
    {
        $minutes = max(0, min(59, $minutes));
        return $this->cron("{$minutes} 1-23/2 * * *");
    }

    public function everyTwoHours(int $minutes = 0): self
    {
        $minutes = max(0, min(59, $minutes));
        return $this->cron("{$minutes} */2 * * *");
    }

    public function everyThreeHours(int $minutes = 0): self
    {
        $minutes = max(0, min(59, $minutes));
        return $this->cron("{$minutes} */3 * * *");
    }

    public function everyFourHours(int $minutes = 0): self
    {
        $minutes = max(0, min(59, $minutes));
        return $this->cron("{$minutes} */4 * * *");
    }

    public function everySixHours(int $minutes = 0): self
    {
        $minutes = max(0, min(59, $minutes));
        return $this->cron("{$minutes} */6 * * *");
    }

    public function daily(): self
    {
        return $this->cron('0 0 * * *');
    }

    public function dailyAt(string $time): self
    {
        $parts = explode(':', $time);
        $hour = max(0, min(23, (int) ($parts[0] ?? 0)));
        $minute = max(0, min(59, (int) ($parts[1] ?? 0)));
        return $this->cron("{$minute} {$hour} * * *");
    }

    public function twiceDaily(int $first = 1, int $second = 13): self
    {
        $first = max(0, min(23, $first));
        $second = max(0, min(23, $second));
        return $this->cron("0 {$first},{$second} * * *");
    }

    public function twiceDailyAt(int $first = 1, int $second = 13, int $minutes = 0): self
    {
        $first = max(0, min(23, $first));
        $second = max(0, min(23, $second));
        $minutes = max(0, min(59, $minutes));
        return $this->cron("{$minutes} {$first},{$second} * * *");
    }

    public function weekly(): self
    {
        return $this->cron('0 0 * * 0');
    }

    public function weeklyOn(int $dayOfWeek, string $time = '0:0'): self
    {
        $dayOfWeek = max(0, min(6, $dayOfWeek));
        $parts = explode(':', $time);
        $hour = max(0, min(23, (int) ($parts[0] ?? 0)));
        $minute = max(0, min(59, (int) ($parts[1] ?? 0)));
        return $this->cron("{$minute} {$hour} * * {$dayOfWeek}");
    }

    public function monthly(): self
    {
        return $this->cron('0 0 1 * *');
    }

    public function monthlyOn(int $dayOfMonth = 1, string $time = '0:0'): self
    {
        $dayOfMonth = max(1, min(31, $dayOfMonth));
        $parts = explode(':', $time);
        $hour = max(0, min(23, (int) ($parts[0] ?? 0)));
        $minute = max(0, min(59, (int) ($parts[1] ?? 0)));
        return $this->cron("{$minute} {$hour} {$dayOfMonth} * *");
    }

    public function twiceMonthly(int $first = 1, int $second = 16, string $time = '0:0'): self
    {
        $first = max(1, min(31, $first));
        $second = max(1, min(31, $second));
        $parts = explode(':', $time);
        $hour = max(0, min(23, (int) ($parts[0] ?? 0)));
        $minute = max(0, min(59, (int) ($parts[1] ?? 0)));
        return $this->cron("{$minute} {$hour} {$first},{$second} * *");
    }

    public function lastDayOfMonth(string $time = '0:0'): self
    {
        $parts = explode(':', $time);
        $hour = max(0, min(23, (int) ($parts[0] ?? 0)));
        $minute = max(0, min(59, (int) ($parts[1] ?? 0)));

        // Use L (last day) — handled via special check in isDue()
        return $this->cron("{$minute} {$hour} L * *");
    }

    public function quarterly(): self
    {
        return $this->cron('0 0 1 1,4,7,10 *');
    }

    public function quarterlyOn(int $dayOfQuarter = 1, string $time = '0:0'): self
    {
        $dayOfQuarter = max(1, min(31, $dayOfQuarter));
        $parts = explode(':', $time);
        $hour = max(0, min(23, (int) ($parts[0] ?? 0)));
        $minute = max(0, min(59, (int) ($parts[1] ?? 0)));
        return $this->cron("{$minute} {$hour} {$dayOfQuarter} 1,4,7,10 *");
    }

    public function yearly(): self
    {
        return $this->cron('0 0 1 1 *');
    }

    public function yearlyOn(int $month = 1, int $dayOfMonth = 1, string $time = '0:0'): self
    {
        $month = max(1, min(12, $month));
        $dayOfMonth = max(1, min(31, $dayOfMonth));
        $parts = explode(':', $time);
        $hour = max(0, min(23, (int) ($parts[0] ?? 0)));
        $minute = max(0, min(59, (int) ($parts[1] ?? 0)));
        return $this->cron("{$minute} {$hour} {$dayOfMonth} {$month} *");
    }

    // ─── Day Constraints ─────────────────────────────────────

    public function weekdays(): self
    {
        return $this->spliceIntoPosition(4, '1-5');
    }

    public function weekends(): self
    {
        return $this->spliceIntoPosition(4, '0,6');
    }

    public function sundays(): self
    {
        return $this->spliceIntoPosition(4, '0');
    }

    public function mondays(): self
    {
        return $this->spliceIntoPosition(4, '1');
    }

    public function tuesdays(): self
    {
        return $this->spliceIntoPosition(4, '2');
    }

    public function wednesdays(): self
    {
        return $this->spliceIntoPosition(4, '3');
    }

    public function thursdays(): self
    {
        return $this->spliceIntoPosition(4, '4');
    }

    public function fridays(): self
    {
        return $this->spliceIntoPosition(4, '5');
    }

    public function saturdays(): self
    {
        return $this->spliceIntoPosition(4, '6');
    }

    public function days(array $days): self
    {
        return $this->spliceIntoPosition(4, implode(',', $days));
    }

    // ─── Time Constraints ────────────────────────────────────

    /**
     * Set the time for the current schedule (preserves day/month/weekday fields)
     */
    public function at(string $time): self
    {
        $parts = explode(':', $time);
        $hour = max(0, min(23, (int) ($parts[0] ?? 0)));
        $minute = max(0, min(59, (int) ($parts[1] ?? 0)));

        return $this->spliceIntoPosition(0, (string) $minute)
                     ->spliceIntoPosition(1, (string) $hour);
    }

    /**
     * Set time range constraint (checked at runtime, not in cron)
     */
    public function between(string $startTime, string $endTime): self
    {
        return $this->when(function () use ($startTime, $endTime) {
            $now = date('H:i');
            // Handle overnight ranges (e.g., '22:00' to '06:00')
            if ($startTime > $endTime) {
                return $now >= $startTime || $now <= $endTime;
            }
            return $now >= $startTime && $now <= $endTime;
        });
    }

    /**
     * Set time exclusion range (checked at runtime)
     */
    public function unlessBetween(string $startTime, string $endTime): self
    {
        return $this->skip(function () use ($startTime, $endTime) {
            $now = date('H:i');
            // Handle overnight ranges (e.g., '22:00' to '06:00')
            if ($startTime > $endTime) {
                return $now >= $startTime || $now <= $endTime;
            }
            return $now >= $startTime && $now <= $endTime;
        });
    }

    // ─── Description & Meta ──────────────────────────────────

    public function description(string $description): self
    {
        $this->description = $description;
        return $this;
    }

    public function timezone(string $timezone): self
    {
        $this->timezone = $timezone;
        return $this;
    }

    // ─── Overlap Prevention ──────────────────────────────────

    /**
     * Prevent overlapping execution using a lock file
     */
    public function withoutOverlapping(int $expiresAfterMinutes = 1440): self
    {
        $this->preventOverlapping = true;
        $this->expiresAt = $expiresAfterMinutes;
        return $this;
    }

    // ─── Conditional Execution ───────────────────────────────

    /**
     * Only run when callback returns true
     */
    public function when(callable $callback): self
    {
        $this->whenCallback = $callback;
        return $this;
    }

    /**
     * Skip when callback returns true
     */
    public function skip(callable $callback): self
    {
        $this->skipCallback = $callback;
        return $this;
    }

    /**
     * Only run in given environments
     */
    public function environments(array $environments): self
    {
        $this->environments = $environments;
        return $this;
    }

    /**
     * Allow running even in maintenance mode
     */
    public function evenInMaintenanceMode(): self
    {
        $this->runInMaintenanceMode = true;
        return $this;
    }

    /**
     * Run in background (non-blocking)
     */
    public function runInBackground(): self
    {
        $this->runInBackground = true;
        return $this;
    }

    // ─── Output ──────────────────────────────────────────────

    /**
     * Send output to a file (overwrite)
     */
    public function sendOutputTo(string $filePath): self
    {
        $this->outputPath = $this->sanitizeOutputPath($filePath);
        $this->appendOutput = false;
        return $this;
    }

    /**
     * Append output to a file
     */
    public function appendOutputTo(string $filePath): self
    {
        $this->outputPath = $this->sanitizeOutputPath($filePath);
        $this->appendOutput = true;
        return $this;
    }

    // ─── Lifecycle Hooks ─────────────────────────────────────

    public function before(callable $callback): self
    {
        $this->beforeCallback = $callback;
        return $this;
    }

    public function after(callable $callback): self
    {
        $this->afterCallback = $callback;
        return $this;
    }

    public function onSuccess(callable $callback): self
    {
        $this->onSuccessCallback = $callback;
        return $this;
    }

    public function onFailure(callable $callback): self
    {
        $this->onFailureCallback = $callback;
        return $this;
    }

    // ─── Execution Logic ─────────────────────────────────────

    /**
     * Check if this event is due to run now
     */
    public function isDue(): bool
    {
        $parts = preg_split('/\s+/', trim($this->expression));
        if (count($parts) !== 5) {
            return false;
        }

        // Handle timezone
        $now = $this->timezone
            ? new \DateTime('now', new \DateTimeZone($this->timezone))
            : new \DateTime('now');

        [$minute, $hour, $day, $month, $weekday] = $parts;

        $currentValues = [
            (int) $now->format('i'),
            (int) $now->format('G'),
            (int) $now->format('j'),
            (int) $now->format('n'),
            (int) $now->format('w'),
        ];

        // Handle L (last day of month) in day field
        if ($day === 'L') {
            $lastDay = (int) $now->format('t');
            if ($currentValues[2] !== $lastDay) {
                return false;
            }
            $day = '*'; // Day matched, skip normal check
        }

        $fields = [$minute, $hour, $day, $month, $weekday];

        for ($i = 0; $i < 5; $i++) {
            if (!$this->cronFieldMatches($fields[$i], $currentValues[$i])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Determine if the event should run based on all conditions
     */
    public function filtersPass(): bool
    {
        // Environment check
        if (!empty($this->environments)) {
            $currentEnv = defined('APP_ENV') ? APP_ENV : (defined('ENVIRONMENT') ? ENVIRONMENT : 'production');
            if (!in_array($currentEnv, $this->environments, true)) {
                return false;
            }
        }

        // When callback
        if ($this->whenCallback !== null && !call_user_func($this->whenCallback)) {
            return false;
        }

        // Skip callback
        if ($this->skipCallback !== null && call_user_func($this->skipCallback)) {
            return false;
        }

        return true;
    }

    /**
     * Acquire the overlap lock
     */
    public function acquireLock(): bool
    {
        if (!$this->preventOverlapping) {
            return true;
        }

        $lockFile = $this->getLockPath();
        $lockDir = dirname($lockFile);
        if (!is_dir($lockDir)) {
            mkdir($lockDir, 0775, true);
        }

        // Check if lock exists and hasn't expired
        if (file_exists($lockFile)) {
            $lockTime = (int) @file_get_contents($lockFile);
            $elapsed = time() - $lockTime;

            if ($elapsed < ($this->expiresAt * 60)) {
                return false; // Still locked
            }

            // Lock expired — remove it
            @unlink($lockFile);
        }

        // Atomic lock creation using LOCK_EX
        file_put_contents($lockFile, (string) time(), LOCK_EX);
        return true;
    }

    /**
     * Release the overlap lock
     */
    public function releaseLock(): void
    {
        if ($this->preventOverlapping) {
            @unlink($this->getLockPath());
        }
    }

    /**
     * Run the event's lifecycle hooks and capture output
     */
    public function callBeforeCallbacks(): void
    {
        if ($this->beforeCallback) {
            call_user_func($this->beforeCallback);
        }
    }

    public function callAfterCallbacks(bool $success): void
    {
        if ($this->afterCallback) {
            call_user_func($this->afterCallback);
        }

        if ($success && $this->onSuccessCallback) {
            call_user_func($this->onSuccessCallback);
        }

        if (!$success && $this->onFailureCallback) {
            call_user_func($this->onFailureCallback);
        }
    }

    /**
     * Start output buffering for this event
     */
    public function startOutputCapture(): void
    {
        if ($this->outputPath !== null) {
            ob_start();
        }
    }

    /**
     * Flush captured output to file
     */
    public function flushOutput(): void
    {
        if ($this->outputPath !== null && ob_get_level() > 0) {
            $output = ob_get_clean();

            $dir = dirname($this->outputPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }

            $flags = LOCK_EX | ($this->appendOutput ? FILE_APPEND : 0);
            file_put_contents($this->outputPath, $output, $flags);
        }
    }

    public function shouldRunInBackground(): bool
    {
        return $this->runInBackground;
    }

    public function shouldPreventOverlapping(): bool
    {
        return $this->preventOverlapping;
    }

    public function runsInMaintenanceMode(): bool
    {
        return $this->runInMaintenanceMode;
    }

    // ─── Helper Methods ──────────────────────────────────────

    /**
     * Splice a value into a specific position of the cron expression
     */
    private function spliceIntoPosition(int $position, string $value): self
    {
        $parts = preg_split('/\s+/', $this->expression);
        $parts[$position] = $value;
        $this->expression = implode(' ', $parts);
        return $this;
    }

    /**
     * Check if a single cron field matches a value
     */
    private function cronFieldMatches(string $field, int $value): bool
    {
        if ($field === '*') {
            return true;
        }

        // Step: */n
        if (str_starts_with($field, '*/')) {
            $step = (int) substr($field, 2);
            return $step > 0 && ($value % $step) === 0;
        }

        // List with possible ranges: 1,3,5 or 1-5,10,12-15
        $segments = explode(',', $field);
        foreach ($segments as $segment) {
            $segment = trim($segment);

            // Range with step: 1-5/2
            if (str_contains($segment, '/')) {
                [$range, $step] = explode('/', $segment, 2);
                $step = (int) $step;

                if (str_contains($range, '-')) {
                    [$min, $max] = explode('-', $range, 2);
                    if ($value >= (int) $min && $value <= (int) $max && $step > 0 && (($value - (int) $min) % $step) === 0) {
                        return true;
                    }
                }
                continue;
            }

            // Range: 1-5
            if (str_contains($segment, '-')) {
                [$min, $max] = explode('-', $segment, 2);
                if ($value >= (int) $min && $value <= (int) $max) {
                    return true;
                }
                continue;
            }

            // Exact value
            if ((int) $segment === $value) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the lock file path for overlap prevention
     */
    private function getLockPath(): string
    {
        $key = 'schedule-' . md5($this->command);
        $rootDir = defined('ROOT_DIR') ? ROOT_DIR : dirname(__DIR__, 3) . DIRECTORY_SEPARATOR;
        return $rootDir . 'storage' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . $key . '.lock';
    }

    /**
     * Sanitize output file path to prevent directory traversal
     */
    private function sanitizeOutputPath(string $filePath): string
    {
        $rootDir = defined('ROOT_DIR') ? ROOT_DIR : dirname(__DIR__, 3) . DIRECTORY_SEPARATOR;
        $realRoot = realpath($rootDir);

        // Resolve the absolute path
        $resolved = realpath(dirname($filePath));
        if ($resolved === false) {
            // Directory doesn't exist yet — use the logs directory as safe fallback
            $safeDir = $rootDir . 'logs';
            if (!is_dir($safeDir)) {
                mkdir($safeDir, 0775, true);
            }
            return $safeDir . DIRECTORY_SEPARATOR . basename($filePath);
        }

        // Prevent path traversal outside project root
        if ($realRoot !== false && strpos($resolved, $realRoot) !== 0) {
            $safeDir = $rootDir . 'logs';
            if (!is_dir($safeDir)) {
                mkdir($safeDir, 0775, true);
            }
            return $safeDir . DIRECTORY_SEPARATOR . basename($filePath);
        }

        return $filePath;
    }
}
