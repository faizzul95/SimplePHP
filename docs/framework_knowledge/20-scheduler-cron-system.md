# 20. Scheduler / Cron System

## Schedule (`Core\Console\Schedule`)

### Registration

- `command(string $command): ScheduleEvent` — Register a CLI command to schedule.
- `call(callable $callback): ScheduleEvent` — Register a callback to schedule.

### Inspection / Execution

- `events(): array` — All registered events.
- `dueEvents(): array` — Events due now (cron + filters pass).
- `runDueEvents(callable $outputCallback = null): void` — Execute all due events.

## ScheduleEvent — Complete Frequency Methods

### Minute-based

| Method | Cron | Description |
|--------|------|-------------|
| `everyMinute()` | `* * * * *` | Every minute |
| `everyTwoMinutes()` | `*/2 * * * *` | Every 2 minutes |
| `everyThreeMinutes()` | `*/3 * * * *` | Every 3 minutes |
| `everyFiveMinutes()` | `*/5 * * * *` | Every 5 minutes |
| `everyTenMinutes()` | `*/10 * * * *` | Every 10 minutes |
| `everyFifteenMinutes()` | `*/15 * * * *` | Every 15 minutes |
| `everyThirtyMinutes()` | `*/30 * * * *` | Every 30 minutes |

### Hour-based

| Method | Description |
|--------|-------------|
| `hourly()` | At minute 0 every hour |
| `hourlyAt(int $minutes)` | At specified minute every hour |
| `everyOddHour(int $minutes = 0)` | Every odd hour (1,3,5,...) |
| `everyTwoHours(int $minutes = 0)` | Every 2 hours |
| `everyThreeHours(int $minutes = 0)` | Every 3 hours |
| `everyFourHours(int $minutes = 0)` | Every 4 hours |
| `everySixHours(int $minutes = 0)` | Every 6 hours |

### Day-based

| Method | Description |
|--------|-------------|
| `daily()` | Midnight daily |
| `dailyAt(string $time)` | Daily at time (e.g., `'14:30'`) |
| `twiceDaily(int $first = 1, int $second = 13)` | Twice daily at hours |
| `twiceDailyAt(int $first, int $second, int $minutes)` | Twice daily at hours + minutes |

### Week-based

| Method | Description |
|--------|-------------|
| `weekly()` | Sunday midnight |
| `weeklyOn(int $dayOfWeek, string $time = '0:0')` | Specific day + time (0=Sun, 6=Sat) |

### Month-based

| Method | Description |
|--------|-------------|
| `monthly()` | 1st of month midnight |
| `monthlyOn(int $day = 1, string $time = '0:0')` | Specific day of month + time |
| `twiceMonthly(int $first = 1, int $second = 16, string $time)` | Twice monthly |
| `lastDayOfMonth(string $time = '0:0')` | Last day of month (uses `L` in cron) |

### Quarter / Year

| Method | Description |
|--------|-------------|
| `quarterly()` | 1st of Jan, Apr, Jul, Oct |
| `quarterlyOn(int $day = 1, string $time = '0:0')` | Specific day of quarter months |
| `yearly()` | Jan 1st midnight |
| `yearlyOn(int $month, int $day, string $time)` | Specific month + day + time |

### Custom Cron

- `cron(string $expression): self` — Set raw cron expression (5-field).

### Day Constraints

`weekdays()`, `weekends()`, `sundays()`, `mondays()`, `tuesdays()`, `wednesdays()`, `thursdays()`, `fridays()`, `saturdays()`, `days(array $days)`

### Time Constraints

- `at(string $time)` — Set HH:MM (preserves other fields).
- `between(string $start, string $end)` — Only run within time range (runtime check).
- `unlessBetween(string $start, string $end)` — Skip during time range.

## Execution Controls

- `withoutOverlapping(int $expiresAfterMinutes = 1440)` — File-based lock to prevent overlap.
- `when(callable $callback)` — Only run when callback returns true.
- `skip(callable $callback)` — Skip when callback returns true.
- `environments(array $environments)` — Only run in specified environments.
- `evenInMaintenanceMode()` — Run even during maintenance.
- `runInBackground()` — Non-blocking execution.

## Meta / Description

- `description(string $description)` — Describe the scheduled event.
- `timezone(string $timezone)` — Set timezone for schedule evaluation.

## Output & Hooks

- `sendOutputTo(string $filePath)` — Capture output to file (overwrite).
- `appendOutputTo(string $filePath)` — Append output to file.
- `before(callable $callback)` — Hook before execution.
- `after(callable $callback)` — Hook after execution.
- `onSuccess(callable $callback)` — Hook on successful execution.
- `onFailure(callable $callback)` — Hook on failure.

## Examples

### 1) Daily backup with overlap protection

```php
$schedule->command('backup:run')
	->dailyAt('02:00')
	->withoutOverlapping()
	->description('Daily full backup')
	->onFailure(function () {
		logger()->log_error('Backup failed');
	});
```

### 2) Queue worker every minute

```php
$schedule->command('queue:work --once')
	->everyMinute()
	->withoutOverlapping(5);
```

### 3) Business hours only callback

```php
$schedule->call(function () {
		// Send reminder emails
	})
	->everyThirtyMinutes()
	->weekdays()
	->between('09:00', '17:00')
	->description('Business hours reminders');
```

### 4) Twice daily with environment filter

```php
$schedule->command('cache:clear')
	->twiceDaily(2, 14)
	->environments(['production'])
	->description('Clear cache twice daily');
```

### 5) Custom cron expression

```php
$schedule->command('reports:generate')
	->cron('0 6 * * 1-5') // 6 AM weekdays
	->timezone('Asia/Kuala_Lumpur')
	->sendOutputTo(ROOT_DIR . 'logs/report.log');
```

## How To Use

1. Register all schedules in `app/routes/console.php` using `$schedule->command()` or `$schedule->call()`.
2. Chain frequency method + constraints + hooks.
3. Add system cron: `* * * * * php /path/to/console.php schedule:run >> /dev/null 2>&1`.
4. Use `withoutOverlapping()` for long-running tasks.
5. Use `onFailure()` for alerting on critical jobs.

## What To Avoid

- Avoid overlapping long jobs without `withoutOverlapping()`.
- Avoid scheduling heavy jobs at peak traffic times.
- Avoid using raw `cron()` when a named method exists.

## Benefits

- Laravel-like fluent scheduler API.
- 30+ frequency methods covering every common interval.
- Overlap prevention, conditional execution, and lifecycle hooks.
- Timezone-aware scheduling.

## Evidence

- `systems/Core/Console/Schedule.php` (175 lines)
- `systems/Core/Console/ScheduleEvent.php` (767 lines)
- `app/routes/console.php`
