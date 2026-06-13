---
name: myth-console
description: >
  Console commands, queue jobs, scheduler/cron, and background task guide for
  MythPHP. Use when: creating custom CLI commands, dispatching or processing
  queue jobs, writing scheduled tasks, configuring cron frequency, managing failed
  jobs, running queue workers, using TaskRunner for parallel shell execution,
  running built-in myth commands (cache:clear, route:list, make:*, db:seed, backup,
  queue:work, queue:retry, scheduler:run, down, up, key:generate, serve, env:check).
argument-hint: 'Task type, e.g. "custom command", "queue job", "scheduler", "failed jobs"'
---

# MythPHP Console, Queue & Scheduler Guide

## When to Use This Skill

Load this skill for all background and CLI work — custom commands, queue jobs,
scheduled tasks, or myth CLI operations. Not all Laravel Artisan commands exist here;
see the verified command list below.

---

## 1. All Built-in `myth` Commands (39 total)

Reference: [19-console-built-in-commands.md](../../../docs/framework_knowledge/19-console-built-in-commands.md)

### Cache (7)

```bash
php myth cache:clear                    # Clear application cache
php myth cache:clear --store=redis      # Clear specific store
php myth config:cache                   # Compile app/config/*.php → storage/cache/config.cache.php
php myth config:clear                   # Delete compiled config
php myth route:cache                    # Compile routes (skips closures)
php myth route:clear                    # Delete compiled routes
php myth view:cache                     # Pre-compile all Blade templates
php myth view:clear                     # Delete compiled views
```

### Routes (1)

```bash
php myth route:list                     # List all routes (method, URI, controller, middleware)
php myth route:list --method=GET        # Filter by method
php myth route:list --path=/api         # Filter by path
```

### Generators (8)

```bash
php myth make:controller  UserController --resource
php myth make:request     SaveUserRequest
php myth make:model       User
php myth make:middleware  CheckSubscription
php myth make:job         SendWelcomeEmail
php myth make:command     SyncUsersCommand
php myth make:repository  UserRepository
php myth make:dto         UserDto
```

### Database & Backup (4)

```bash
php myth db:seed                        # Run seeders
php myth db:backup                      # Database backup (mysqldump)
php myth backup:run                     # Full backup
php myth backup:run --only-db           # DB only
php myth backup:run --only-files        # Files only
php myth backup:clean                   # Prune old backups
```

### App Runtime (8)

```bash
php myth serve                          # PHP dev server
php myth key:generate                   # Rotate app key
php myth storage:link                   # Create storage symlink
php myth down --message="Maintenance" --retry=60 --secret=bypass-token
php myth up                             # Bring out of maintenance
php myth env                            # Display environment
php myth env:check                      # Validate .env keys/types
php myth env:check --strict --ci        # CI-safe env validation
php myth about                          # App info
```

### Security & Performance (4)

```bash
php myth security:audit                 # OWASP-aligned checks
php myth security:audit --strict --ci   # CI mode
php myth auth:security:test             # Auth hardening tests
php myth perf:benchmark                 # Routing/validation/query benchmark
php myth perf:report                    # View perf report
php myth perf:report --json --export --reset
```

### Queue (5)

```bash
php myth queue:work                     # Process jobs (default queue)
php myth queue:work --queue=emails      # Specific queue
php myth queue:work --tries=3 --timeout=60 --sleep=3
php myth queue:work --once              # Process one job and exit
php myth queue:retry all                # Retry all failed jobs
php myth queue:retry {id}              # Retry specific failed job
php myth queue:failed                   # List failed jobs
php myth queue:flush                    # Delete all failed jobs
php myth queue:clear                    # Clear all queued jobs
```

### Scheduler (2)

```bash
php myth schedule:run                   # Run due scheduled tasks
php myth schedule:list                  # List all scheduled events
```

---

## 2. Creating a Custom Console Command

Reference: [19-console-built-in-commands.md](../../../docs/framework_knowledge/19-console-built-in-commands.md)

Generate the stub:

```bash
php myth make:command SyncUsersCommand
```

Implement the command (`app/console/Commands/SyncUsersCommand.php`):

```php
use Core\Console\Command;

class SyncUsersCommand extends Command
{
    protected string $signature   = 'users:sync {--dry-run : Preview without writing}';
    protected string $description = 'Sync users from external source';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        $this->info('Starting user sync...');

        $count = 0;
        foreach ($this->fetchExternalUsers() as $user) {
            if (!$dryRun) {
                db()->table('users')->updateOrCreate(['email' => $user['email']], $user);
            }
            $count++;
        }

        $this->info("Processed {$count} users" . ($dryRun ? ' (dry run)' : ''));

        return Command::SUCCESS;   // 0
    }

    private function fetchExternalUsers(): array
    {
        // Your logic here
        return [];
    }
}
```

Register in `app/routes/console.php`:

```php
Artisan::command('users:sync', \App\Console\Commands\SyncUsersCommand::class);
```

### Command I/O helpers

```php
$this->info('Message');          // Green
$this->error('Error');           // Red
$this->warn('Warning');          // Yellow
$this->line('Plain text');

$name = $this->argument('name');    // Positional argument
$flag = $this->option('dry-run');   // Boolean option
$val  = $this->option('limit');     // Value option

$confirm = $this->confirm('Continue?', default: true);
$choice  = $this->choice('Environment?', ['local', 'production'], default: 0);
```

---

## 3. Queue Jobs

Reference: [07-cache-queue-console.md](../../../docs/framework_knowledge/07-cache-queue-console.md)

Generate the stub:

```bash
php myth make:job SendWelcomeEmail
```

```php
use Core\Queue\Job;

class SendWelcomeEmail extends Job
{
    protected string $queue = 'emails';  // Named queue
    protected int    $delay = 0;
    protected int    $tries = 3;
    protected int    $timeout = 30;

    public function __construct(
        private int    $userId,
        private string $email
    ) {}

    public function handle(): void
    {
        // Execute job logic here
        $user = db()->table('users')->find($this->userId);
        // send email...
        logger()->info("Welcome email sent to {$this->email}");
    }

    public function failed(\Throwable $e): void
    {
        logger()->error("Failed to send welcome email to {$this->email}: " . $e->getMessage());
    }
}
```

### Dispatching jobs

```php
// Dispatch to queue
$jobId = dispatch(new SendWelcomeEmail($userId, $email));

// With delay
$job = (new SendWelcomeEmail($userId, $email))->delay(300);   // 5 min delay
dispatch($job);

// To specific queue
$job = (new SendWelcomeEmail($userId, $email))->onQueue('priority');
dispatch($job);
```

### Queue drivers

| Driver | Config `queue.default` | Notes |
|--------|----------------------|-------|
| `database` | `database` | `SELECT … FOR UPDATE SKIP LOCKED` — multi-worker safe |
| `redis` | `redis` | Sorted-set delayed jobs + atomic Lua migration |
| `sync` | `sync` | Executes inline — dev/test only |

### Running workers (production)

```bash
# Start worker (keep running with supervisor)
php myth queue:work --queue=default,emails --tries=3 --timeout=60

# One-shot (process one job and exit — useful for testing)
php myth queue:work --once
```

Worker handles SIGTERM within 100 ms (signal-aware sleep via `usleep(100ms)` loop).

---

## 4. Scheduler / Cron

Reference: [20-scheduler-cron-system.md](../../../docs/framework_knowledge/20-scheduler-cron-system.md)

Register scheduled tasks in `app/routes/console.php`:

```php
$schedule->command('users:sync')->dailyAt('02:00');
$schedule->command('backup:run')->weeklyOn(0, '03:00');        // Sunday 3am
$schedule->command('cache:clear')->hourly()->withoutOverlapping();
$schedule->command('reports:generate')->monthlyOn(1, '06:00'); // 1st of month

// Callback task
$schedule->call(function () {
    db()->table('sessions')->where('expires_at', '<', now())->delete();
})->everyThirtyMinutes();
```

### All frequency methods

```php
->everyMinute()
->everyFiveMinutes()
->everyFifteenMinutes()
->everyThirtyMinutes()
->hourly()
->hourlyAt(30)            // At minute 30 every hour
->daily()
->dailyAt('14:30')
->twiceDaily(1, 13)       // At 1am and 1pm
->weekly()
->weeklyOn(1, '08:00')    // Monday 8am
->monthly()
->monthlyOn(15, '09:00')  // 15th of month 9am
->quarterly()
->yearly()
->cron('*/5 * * * *')     // Raw cron expression
```

### Lifecycle hooks & constraints

```php
$schedule->command('send:reports')
    ->dailyAt('06:00')
    ->withoutOverlapping()                         // Skip if still running
    ->when(fn() => config('features.reports'))    // Conditional execution
    ->environments(['production', 'staging'])      // Environment filter
    ->before(fn() => logger()->info('Starting'))
    ->after(fn() => logger()->info('Done'))
    ->onSuccess(fn() => notify('success'))
    ->onFailure(fn() => notify('failure'))
    ->sendOutputTo(storage_path('logs/reports.log'))
    ->appendOutputTo(storage_path('logs/reports.log'));
```

### Cron entry (server cron.d)

```cron
* * * * * /usr/bin/php /path/to/project/myth schedule:run >> /dev/null 2>&1
```

---

## 5. TaskRunner (Parallel Shell Execution)

Reference: [18-task-runner-component.md](../../../docs/framework_knowledge/18-task-runner-component.md)

```php
use Components\TaskRunner;

$runner = new TaskRunner(concurrency: 4);  // Max 4 parallel processes

$runner->add('resize-1', 'php myth images:resize --size=300 --file=a.jpg');
$runner->add('resize-2', 'php myth images:resize --size=300 --file=b.jpg');
$runner->add('resize-3', 'php myth images:resize --size=300 --file=c.jpg');

$results = $runner->run(timeout: 120);  // Max 120s per process

foreach ($results as $name => $result) {
    if ($result['success']) {
        logger()->info("$name completed");
    } else {
        logger()->error("$name failed: " . $result['error']);
    }
}
```

---

## Console / Queue Checklist

- [ ] Custom commands registered in `app/routes/console.php`.
- [ ] Job `$tries` and `$timeout` set appropriately for the workload.
- [ ] `failed()` method implemented for jobs with side-effects.
- [ ] Jobs do NOT pass objects/closures as constructor args — only primitives and IDs.
- [ ] Queue workers managed by a process supervisor (Supervisor, systemd) in production.
- [ ] `php myth queue:work --once` used for local testing, never in production daemons.
- [ ] Scheduler cron entry added to server (runs every minute).
- [ ] `withoutOverlapping()` on long-running scheduled tasks.
- [ ] `->environments(['production'])` on tasks that should not run locally.
