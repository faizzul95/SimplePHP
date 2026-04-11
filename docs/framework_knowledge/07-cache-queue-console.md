# 07. Cache, Queue, and Console

## Cache (`Core\Cache\CacheManager`)

Source: `systems/Core/Cache/CacheManager.php` (228 lines).  
Config: `app/config/cache.php`.

### Drivers

- `file` — File-based cache in `storage/cache/`. Default driver.
- `array` — In-memory cache (request-scoped, no persistence). For testing.

### Complete Cache API

| Method | Signature | Return | Description |
|--------|-----------|--------|-------------|
| `store` | `store(?string $name = null): FileStore\|ArrayStore` | Store | Get or switch to a named store |
| `get` | `get(string $key, mixed $default = null): mixed` | `mixed` | Retrieve cached value or return default |
| `put` | `put(string $key, mixed $value, int $seconds = 0): bool` | `bool` | Store value with TTL (0 = forever) |
| `forever` | `forever(string $key, mixed $value): bool` | `bool` | Store without expiry |
| `remember` | `remember(string $key, int $seconds, \Closure $callback): mixed` | `mixed` | Get from cache; if missing, execute callback, store result, and return it |
| `rememberForever` | `rememberForever(string $key, \Closure $callback): mixed` | `mixed` | Like `remember()` but without expiry |
| `pull` | `pull(string $key, mixed $default = null): mixed` | `mixed` | Get value and delete it from cache |
| `has` | `has(string $key): bool` | `bool` | Check if key exists and not expired |
| `missing` | `missing(string $key): bool` | `bool` | Inverse of `has()` |
| `add` | `add(string $key, mixed $value, int $seconds = 0): bool` | `bool` | Store only if key doesn't exist |
| `many` | `many(array $keys): array` | `array` | Retrieve multiple keys at once |
| `putMany` | `putMany(array $values, int $seconds = 0): bool` | `bool` | Store multiple key-value pairs |
| `increment` | `increment(string $key, int $amount = 1): int` | `int` | Increment numeric value |
| `decrement` | `decrement(string $key, int $amount = 1): int` | `int` | Decrement numeric value |
| `forget` | `forget(string $key): bool` | `bool` | Remove a key |
| `flush` | `flush(): bool` | `bool` | Remove all cached data |

---

## Queue (`Core\Queue`)

Sources: `systems/Core/Queue/Dispatcher.php`, `systems/Core/Queue/Worker.php`, `systems/Core/Queue/Job.php`.  
Config: `app/config/queue.php`.

### Drivers

- `database` — Jobs stored in DB table (`jobs`, `failed_jobs`). Tables auto-created on first dispatch.
- `sync` — Execute immediately (no background processing). For development/testing.

### Job Base Class (`Core\Queue\Job`)

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `$queue` | `string` | `'default'` | Queue name this job should be dispatched to |
| `$delay` | `int` | `0` | Seconds to delay before job becomes available |
| `$tries` | `?int` | `null` | Max attempts (overrides worker default) |
| `$timeout` | `?int` | `null` | Max seconds to run (overrides worker default) |

| Method | Signature | Description |
|--------|-----------|-------------|
| `handle` | `abstract public function handle(): void` | Execute the job (must implement) |
| `failed` | `public function failed(\Throwable $e): void` | Called after all retries exhausted (override for custom failure handling) |
| `onQueue` | `public function onQueue(string $queue): static` | Set queue name fluently |
| `delay` | `public function delay(int $seconds): static` | Set delay fluently |
| `toPayload` | `public function toPayload(): array` | Serialize job for storage |
| `fromPayload` | `public static function fromPayload(array $payload): static` | Restore job from serialized payload |

### Worker Execution

- Command: `php myth queue:work`
- Options: `--queue=<name>`, `--sleep=<seconds>`, `--tries=<count>`, `--timeout=<seconds>`, `--once`
- Reservation + retry mechanism with exponential backoff.
- Failed jobs stored in `failed_jobs` table with error snapshot.
- Failed job operations: list (`queue:failed`), retry one/all (`queue:retry`), flush (`queue:flush`), clear queue (`queue:clear`).

---

## Built-in Console Commands (28 total)

### Cache & Storage

| Command | Description |
|---------|-------------|
| `cache:clear` | Clear all cached data |
| `view:clear` | Clear compiled/cached views |
| `config:clear` | Clear cached config files |

### Routes

| Command | Description |
|---------|-------------|
| `route:list` | Display all registered routes with methods, URI, controller, middleware |

### Generators

| Command | Description |
|---------|-------------|
| `make:controller` | Create a new controller class |
| `make:middleware` | Create a new middleware class |
| `make:request` | Create a new FormRequest class |
| `make:model` | Create a new model class |
| `make:job` | Create a new queue job class |
| `make:command` | Create a new console command class |

### Database & Backup

| Command | Description |
|---------|-------------|
| `db:backup` | Create database backup |
| `backup:run` | Run backup with configured strategy |
| `backup:clean` | Clean old backups |
| `db:seed` | Run database seeders |

### Application

| Command | Description |
|---------|-------------|
| `serve` | Start PHP built-in development server |
| `key:generate` | Generate application encryption key |
| `storage:link` | Create symbolic link for public storage |
| `down` | Put application into maintenance mode |
| `up` | Bring application out of maintenance mode |
| `env` | Display current environment |
| `about` | Display application information |

### Queue

| Command | Description |
|---------|-------------|
| `queue:work` | Start queue worker. Options: `--queue`, `--sleep`, `--tries`, `--timeout`, `--once` |
| `queue:retry` | Retry a failed job (by ID or `all`) |
| `queue:failed` | List all failed jobs |
| `queue:flush` | Delete all failed jobs |
| `queue:clear` | Clear all jobs from a queue |

### Scheduler

| Command | Description |
|---------|-------------|
| `schedule:run` | Run all due scheduled tasks (registered in Console Kernel) |
| `schedule:list` | List all scheduled tasks with next run time |

### System

| Command | Description |
|---------|-------------|
| `list` | Display all available commands |
| `help` | Display help for a command |

---

## Examples

### 1) Cache — remember pattern (most common)

```php
// Expensive query result cached for 5 minutes
$stats = cache()->remember('dashboard.stats', 300, function () {
    return [
        'total_users' => db()->table('users')->count(),
        'total_orders' => db()->table('orders')->count(),
        'revenue' => db()->table('orders')->where('status', 'completed')->sum('total'),
    ];
});
```

### 2) Cache — add (only if not exists)

```php
// Set value only if key doesn't already exist (atomic-like behavior)
$wasSet = cache()->add('lock:report-generation', true, 120);
if ($wasSet) {
    // We got the "lock" — proceed with report
    generateReport();
    cache()->forget('lock:report-generation');
} else {
    // Another process is already generating the report
}
```

### 3) Cache — increment/decrement for counters

```php
// Track API calls
cache()->put('api:calls:today', 0, 86400);
cache()->increment('api:calls:today');
cache()->increment('api:calls:today');
$count = cache()->get('api:calls:today'); // 2

// Rate-limiting counter
cache()->increment('rate:' . request()->ip());
```

### 4) Cache — pull (get + delete)

```php
// One-time flash messages
cache()->put('flash:success', 'Profile updated!', 60);

// In next request — get and auto-remove
$message = cache()->pull('flash:success');
// $message = 'Profile updated!', key is now deleted
```

### 5) Cache — batch get/set

```php
// Store multiple values
cache()->putMany([
    'user:1:name' => 'John',
    'user:1:email' => 'john@example.com',
    'user:1:role' => 'admin',
], 600);

// Retrieve multiple values
$data = cache()->many(['user:1:name', 'user:1:email', 'user:1:role']);
// ['user:1:name' => 'John', 'user:1:email' => 'john@example.com', ...]
```

### 6) Cache — store switching

```php
// Use file store (default)
cache()->put('key', 'value', 60);

// Use array store (in-memory, for testing)
cache()->store('array')->put('temp', 'data', 60);
```

### 7) Queue — creating a Job class

```php
// app/Jobs/SendWelcomeEmail.php (created via: php myth make:job SendWelcomeEmail)
namespace App\Jobs;

use Core\Queue\Job;

class SendWelcomeEmail extends Job
{
    public ?int $tries = 3;        // retry up to 3 times
    public ?int $timeout = 30;     // max 30 seconds

    public function __construct(
        private int $userId,
        private string $email
    ) {}

    public function handle(): void
    {
        $user = db()->table('users')->where('id', $this->userId)->fetch();
        
        // Send email logic
        $mailer = new \Components\Mailer();
        $mailer->to($this->email)
            ->subject('Welcome!')
            ->body(view_raw('emails/welcome', ['name' => $user['name']]))
            ->send();
    }

    public function failed(\Throwable $e): void
    {
        logger()->error('Welcome email failed', [
            'user_id' => $this->userId,
            'error' => $e->getMessage(),
        ]);
    }
}
```

### HTML Template Queue Pattern

When queueing work that renders HTML, prefer passing lightweight identifiers into the job instead of serializing full rendered HTML into the queue payload.

This applies to:

- Email templates
- Receipt and invoice generation
- Printable letters or reports
- Other trusted admin-defined or config-defined HTML layouts

Recommended flow:

1. Store or define the trusted template in a database record, config value, seeded default, or view-like source.
2. Dispatch the job with the template key or template ID plus placeholder data.
3. Inside the job, resolve the template from its authoritative source.
4. If the template comes from the database and includes HTML columns, use `safeOutputWithException([...])` for the HTML field so the markup is returned intact.
5. Replace placeholders and render the final HTML inside the job.

Benefits:

- Keeps queue payloads small.
- Avoids stale serialized template markup when templates are updated.
- Preserves trusted HTML while still applying normal safe output rules to other columns.
- Works for both config-based and database-based templates.

### 8) Queue — dispatching jobs

```php
use App\Jobs\SendWelcomeEmail;

// Basic dispatch
dispatch(new SendWelcomeEmail($userId, $email));

// Dispatch to specific queue with delay
dispatch(
    (new SendWelcomeEmail($userId, $email))
        ->onQueue('emails')
        ->delay(30)               // delay 30 seconds
);

// Dispatch multiple jobs
foreach ($users as $user) {
    dispatch(
        (new SendWelcomeEmail($user['id'], $user['email']))
            ->onQueue('bulk-emails')
    );
}
```

### 9) Queue worker — running in terminal

```bash
# Start default queue worker
php myth queue:work

# Start worker for specific queue with options
php myth queue:work --queue=emails --tries=3 --timeout=60 --sleep=5

# Process one job and exit (for testing)
php myth queue:work --once

# View failed jobs
php myth queue:failed

# Retry a specific failed job
php myth queue:retry 42

# Retry all failed jobs
php myth queue:retry all

# Clear all failed jobs
php myth queue:flush

# Clear all pending jobs from a queue
php myth queue:clear --queue=emails
```

### 10) Console — common commands

```bash
# List all available commands
php myth list

# Generate new scaffolded classes
php myth make:controller ProductController
php myth make:request StoreProductRequest
php myth make:job ProcessOrder
php myth make:middleware CheckSubscription
php myth make:command GenerateReport

# Application management
php myth down          # maintenance mode
php myth up            # back online
php myth env           # show current environment
php myth about         # show app info

# Cache operations
php myth cache:clear
php myth view:clear
php myth config:clear

# Database
php myth db:backup
php myth db:seed

# Routes
php myth route:list

# Scheduler
php myth schedule:run
php myth schedule:list
```

## How To Use

1. Choose cache driver in `app/config/cache.php`. Use `file` for production, `array` for tests.
2. Prefer `remember()` for expensive DB queries — it handles the get-or-compute pattern.
3. Use `add()` for lock-like patterns where only one process should proceed.
4. For async tasks, set queue driver to `database` and run `queue:work` in background.
5. Create jobs via `php myth make:job`. Implement `handle()` and optionally `failed()`.
6. Use per-job `$tries` and `$timeout` for fine-grained retry control.
7. Use console generators to scaffold framework-conformant classes.

## What To Avoid

- Avoid `array` cache in production for persistent data (lost after request ends).
- Avoid dispatching long jobs with `sync` driver when user response time matters.
- Avoid forgetting `queue:failed` / `queue:retry` operational checks.
- Avoid serializing non-serializable objects (closures, PDO) in job constructors.
- Avoid caching frequently-changing data with long TTLs.

## Benefits

- Faster responses via caching.
- Better scalability by moving heavy work to queue workers.
- Standardized dev workflow using built-in CLI generators.
- Per-job retry/timeout control for robust background processing.
- Failed job tracking with error snapshots for debugging.

## Evidence

- `systems/Core/Cache/CacheManager.php`
- `systems/Core/Cache/FileStore.php`
- `systems/Core/Cache/ArrayStore.php`
- `systems/Core/Queue/Dispatcher.php`
- `systems/Core/Queue/Worker.php`
- `systems/Core/Queue/Job.php`
- `systems/Core/Console/Commands.php`
- `app/console/Kernel.php`
- `app/config/cache.php`
- `app/config/queue.php`
