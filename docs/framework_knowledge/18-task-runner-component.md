# 18. TaskRunner Component

## Purpose

`Components\TaskRunner` executes shell tasks with controlled concurrency and timeout handling. Tasks are run via PHP CLI processes.

## Defaults

- **Max concurrent tasks**: 4
- **Process timeout**: 36000 seconds (10 hours)
- **Sleep between chunks**: 10 seconds
- **Jobs directory**: `ROOT_DIR/jobs/`
- **Log path**: `ROOT_DIR/logs/TaskRunnerLog/`

## Public API

- `addTask(string $command, ?array $params = null): void` — Add task to queue. Params must be array or null.
- `setMaxConcurrentTasks(int $max): void` — Set max concurrent tasks.
- `setJobsDir(string $path): void` — Set jobs directory (absolute path).
- `setLogPath(string $path): void` — Set log directory (absolute path).
- `setProcessTimeout(int $seconds = 36000): void` — Set per-task timeout in seconds.
- `run(): void` — Execute all queued tasks in chunks.

## Constructor

```php
new TaskRunner(?string $jobsDir = null, ?string $logPath = null)
```

Both parameters default to `ROOT_DIR` + standard subdirectories if null.

## Execution Behavior

1. Tasks are chunked by `maxConcurrentTasks`.
2. Each chunk runs concurrently via `proc_open()` PHP CLI processes.
3. Between chunks, sleeps for `$sleep` seconds (default 10).
4. Monitors running processes and resolves deadlocks.
5. Logs execution output/status to configured log path.
6. Reports total process time on completion.
7. `max_execution_time` is temporarily set to `processTimeout` during run.

## Examples

### 1) Basic parallel task execution

```php
$runner = new \Components\TaskRunner();
$runner->setMaxConcurrentTasks(3);
$runner->setProcessTimeout(600);

$runner->addTask('sync-users.php', ['--chunk=1000']);
$runner->addTask('sync-roles.php');
$runner->addTask('sync-permissions.php');
$runner->run();
// Runs sync-users, sync-roles, sync-permissions concurrently (max 3)
```

### 2) Custom directories

```php
$runner = new \Components\TaskRunner(
	jobsDir: ROOT_DIR . 'scripts/',
	logPath: ROOT_DIR . 'logs/migration/'
);
$runner->addTask('migrate-data.php');
$runner->run();
```

## How To Use

1. Define short, composable CLI tasks as PHP scripts in jobs directory.
2. Configure timeout/concurrency according to server capacity.
3. Keep logs enabled for long-running operations.
4. Use `setProcessTimeout()` for tasks expected to run longer than 10 hours.

## What To Avoid

- Avoid adding too many concurrent tasks on low-resource hosts.
- Avoid unbounded task runtime without timeout safeguards.
- Avoid non-array params — will throw `InvalidArgumentException`.

## Benefits

- Controlled concurrency with chunked execution.
- Process timeout and deadlock resolution.
- Automatic logging with timing metrics.
- Simple API for orchestrating background shell jobs.

## Evidence

- `systems/Components/TaskRunner.php` (411 lines)
