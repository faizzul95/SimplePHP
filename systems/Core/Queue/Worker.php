<?php

namespace Core\Queue;

/**
 * Queue Worker
 *
 * Processes jobs from the database queue, handles retries and failures.
 *
 * Usage (CLI):
 *   php myth queue:work [queue_name] [--sleep=3] [--tries=3] [--timeout=60] [--once]
 *
 * @see \Core\Console\Commands::queueCommands()
 */
class Worker
{
    private array $config;
    private string $table;
    private string $failedTable;
    private bool $shouldQuit = false;

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? (\config('queue') ?? []);
        $connConfig = $this->config['connections']['database'] ?? [];
        $this->table = $connConfig['table'] ?? 'system_jobs';
        $this->failedTable = $connConfig['failed_table'] ?? 'system_failed_jobs';

        // Ensure tables exist on first run
        (new Dispatcher($this->config))->ensureTable();
    }

    /**
     * Start the worker loop.
     *
     * @param string   $queue    Queue name to process
     * @param array    $options  Worker options (sleep, tries, timeout, once)
     * @param callable $callback Status callback: fn(string $status, string $message)
     */
    public function work(string $queue = 'default', array $options = [], ?callable $callback = null): void
    {
        $workerDefaults = $this->config['worker'] ?? [];
        $sleep   = $options['sleep']   ?? $workerDefaults['sleep']   ?? 3;
        $tries   = $options['tries']   ?? $workerDefaults['tries']   ?? 3;
        $timeout = $options['timeout'] ?? $workerDefaults['timeout'] ?? 60;
        $once    = $options['once']    ?? false;

        // Register signal handlers for graceful shutdown
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, function () {
                $this->shouldQuit = true;
            });
            pcntl_signal(SIGINT, function () {
                $this->shouldQuit = true;
            });
        }

        while (!$this->shouldQuit) {
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            $job = $this->pop($queue);

            if ($job === null) {
                if ($once) {
                    if ($callback) {
                        $callback('info', 'No jobs available.');
                    }
                    return;
                }

                // Sleep in 100 ms chunks so SIGTERM/SIGINT are processed promptly.
                // A plain sleep($sleep) delays signal delivery by up to $sleep seconds,
                // making graceful shutdown feel unresponsive for high $sleep values.
                $ticks = $sleep * 10;
                for ($i = 0; $i < $ticks && !$this->shouldQuit; $i++) {
                    usleep(100_000); // 100 ms
                    if (function_exists('pcntl_signal_dispatch')) {
                        pcntl_signal_dispatch();
                    }
                }
                continue;
            }

            $this->process($job, $tries, $timeout, $callback);

            if ($once) {
                return;
            }
        }

        if ($callback) {
            $callback('info', 'Worker shutting down gracefully.');
        }
    }

    /**
     * Pop the next available job from the queue.
     *
     * Uses a transaction with SELECT ... FOR UPDATE to atomically
     * claim a job and prevent multiple workers from processing the same job.
     */
    private function pop(string $queue): ?array
    {
        $now = date('Y-m-d H:i:s');
        $retryAfter = $this->config['connections']['database']['retry_after'] ?? 90;
        $expiredReservation = date('Y-m-d H:i:s', time() - $retryAfter);
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $this->table);

        try {
            return db()->transaction(function ($db) use ($queue, $now, $expiredReservation, $table) {
                // SELECT FOR UPDATE locks the row so no other worker can claim it
                $job = $db->query(
                    "SELECT * FROM `{$table}` WHERE `queue` = ? AND (`reserved_at` IS NULL OR `reserved_at` <= ?) AND `available_at` <= ? ORDER BY `available_at` ASC LIMIT 1 FOR UPDATE SKIP LOCKED",
                    [$queue, $expiredReservation, $now]
                )->execute();

                if (empty($job) || !is_array($job)) {
                    return null;
                }

                // Handle both single row and array of rows
                $row = isset($job['id']) ? $job : ($job[0] ?? null);
                if (!$row) {
                    return null;
                }

                // Reserve the locked job by its ID
                $db->table($table)
                    ->where('id', $row['id'])
                    ->update([
                        'reserved_at' => $now,
                        'attempts'    => $db->raw('`attempts` + 1'),
                    ]);

                $row['reserved_at'] = $now;
                $row['attempts'] = ((int) ($row['attempts'] ?? 0)) + 1;

                return $row;
            });
        } catch (\Throwable $e) {
            if (function_exists('logger')) {
                logger()->log_error('Queue pop error: ' . $e->getMessage());
            }
            return null;
        }
    }

    /**
     * Process a single job.
     */
    private function process(array $jobRow, int $maxTries, int $timeout, ?callable $callback): void
    {
        $payload = $this->decodePayload($jobRow);
        $className = $payload['class'] ?? 'Unknown';
        $shortName = basename(str_replace('\\', '/', $className));
        $attempts = (int) $jobRow['attempts'];

        // Check max tries from job or worker config
        $jobMaxTries = $payload['tries'] ?? null;
        $effectiveTries = $jobMaxTries ?? $maxTries;

        try {
            $jobInstance = Job::fromPayload($payload);

            // Execute with timeout (if pcntl available)
            $jobTimeout = $payload['timeout'] ?? $timeout;

            if ($jobTimeout > 0 && function_exists('pcntl_alarm')) {
                pcntl_signal(SIGALRM, function () use ($className) {
                    throw new \RuntimeException("Job [{$className}] timed out.");
                });
                pcntl_alarm($jobTimeout);
            }

            $jobInstance->handle();

            if (function_exists('pcntl_alarm')) {
                pcntl_alarm(0); // Cancel alarm
                pcntl_signal(SIGALRM, SIG_DFL); // Restore default handler
            }

            // Job succeeded — remove from queue
            db()->table($this->table)
                ->where('id', $jobRow['id'])
                ->delete();

            if ($callback) {
                $callback('processed', $shortName);
            }
        } catch (\Throwable $e) {
            if (function_exists('pcntl_alarm')) {
                pcntl_alarm(0);
            }

            if (function_exists('pcntl_signal') && defined('SIGALRM')) {
                pcntl_signal(SIGALRM, SIG_DFL);
            }

            // Attempt to call failed() on the job instance
            if (isset($jobInstance) && $jobInstance instanceof Job) {
                try {
                    $jobInstance->failed($e);
                } catch (\Throwable $failedEx) {
                    // Ignore errors in the failure handler
                }
            }

            if ($attempts >= $effectiveTries) {
                // Move to failed jobs
                $this->markAsFailed($jobRow, $e);

                if ($callback) {
                    $callback('failed', "{$shortName}: {$e->getMessage()}");
                }
            } else {
                // Release back to queue for retry
                $this->release($jobRow);

                if ($callback) {
                    $callback('info', "{$shortName} will be retried (attempt {$attempts}/{$effectiveTries})");
                }
            }

            if (function_exists('logger')) {
                logger()->log_error("Queue job [{$shortName}] attempt {$attempts} failed: " . $e->getMessage());
            }
        }
    }

    /**
     * @return array{class?: string, data?: string, queue?: string, delay?: int, tries?: int|null, timeout?: int|null}
     */
    private function decodePayload(array $jobRow): array
    {
        $rawPayload = $jobRow['payload'] ?? null;
        if (!is_string($rawPayload) || trim($rawPayload) === '') {
            throw new \RuntimeException('Invalid queue payload.');
        }

        $payload = json_decode($rawPayload, true);
        if (!is_array($payload)) {
            throw new \RuntimeException('Invalid queue payload.');
        }

        return $payload;
    }

    /**
     * Release a job back to the queue for retry.
     */
    private function release(array $jobRow): void
    {
        db()->table($this->table)
            ->where('id', $jobRow['id'])
            ->update([
                'reserved_at'  => null,
                'available_at' => date('Y-m-d H:i:s', time() + 5), // 5s backoff
            ]);
    }

    /**
     * Move a job to the failed jobs table.
     */
    private function markAsFailed(array $jobRow, \Throwable $e): void
    {
        try {
            db()->table($this->failedTable)->insert([
                'queue'     => $jobRow['queue'],
                'payload'   => $jobRow['payload'],
                'attempts'  => (int) $jobRow['attempts'],
                'error'     => mb_strimwidth($e->getMessage() . "\n" . $e->getTraceAsString(), 0, 5000),
                'failed_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $insertError) {
            if (function_exists('logger')) {
                logger()->log_error("Failed to record failed job: " . $insertError->getMessage());
            }
        }

        // Remove from jobs table
        db()->table($this->table)
            ->where('id', $jobRow['id'])
            ->delete();
    }

    // ─── Failed Job Management ───────────────────────────────

    /**
     * List all failed jobs.
     */
    public function listFailed(): array
    {
        try {
            $rows = db()->table($this->failedTable)
                ->orderBy('failed_at', 'DESC')
                ->get();

            return array_map(function ($row) {
                $row['payload'] = json_decode($row['payload'] ?? '{}', true);
                return $row;
            }, $rows);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Retry a specific failed job by ID.
     */
    public function retry(string|int $id): bool
    {
        try {
            $failed = db()->table($this->failedTable)
                ->where('id', $id)
                ->fetch();

            if (!$failed) {
                return false;
            }

            $payload = json_decode($failed['payload'], true);

            // Push back to the jobs queue
            $jobId = bin2hex(random_bytes(16));
            db()->table($this->table)->insert([
                'id'           => $jobId,
                'queue'        => $failed['queue'] ?? 'default',
                'payload'      => $failed['payload'],
                'attempts'     => 0,
                'reserved_at'  => null,
                'available_at' => date('Y-m-d H:i:s'),
                'created_at'   => date('Y-m-d H:i:s'),
            ]);

            // Remove from failed
            db()->table($this->failedTable)
                ->where('id', $id)
                ->delete();

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Retry all failed jobs.
     */
    public function retryAll(): int
    {
        $failed = $this->listFailed();
        $count = 0;

        foreach ($failed as $job) {
            if ($this->retry($job['id'])) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Flush (delete) all failed jobs.
     */
    public function flush(): int
    {
        try {
            $count = db()->table($this->failedTable)->count();
            db()->rawQuery("TRUNCATE TABLE `" . preg_replace('/[^a-zA-Z0-9_]/', '', $this->failedTable) . "`");
            return $count;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Clear all pending jobs from a queue.
     */
    public function clear(string $queue = 'default'): int
    {
        try {
            $count = db()->table($this->table)
                ->where('queue', $queue)
                ->count();

            db()->table($this->table)
                ->where('queue', $queue)
                ->delete();

            return $count;
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
