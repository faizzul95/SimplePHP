<?php

declare(strict_types=1);

namespace Core\Queue;

/**
 * Run N queue workers in parallel and keep them running.
 *
 * `queue:work` is a single process: one job at a time, and if it dies the queue
 * silently stops draining until someone notices. Production deployments solved
 * that with systemd or supervisord, which is correct but is a lot of setup to ask
 * for before any background work happens at all.
 *
 * This supervisor spawns worker processes, waits for them, and replaces any that
 * exit. Parallelism is safe because the workers claim jobs with
 * `SELECT ... FOR UPDATE SKIP LOCKED` — each takes a different row rather than
 * queueing behind the same one.
 *
 * It is deliberately simple and is not a replacement for a real process manager:
 * it does not survive its own death, daemonise, or manage log rotation. For
 * production, point systemd at `queue:work` and use `queue:restart` on deploy.
 * This is for development, containers with a single entrypoint, and hosts where
 * installing a process manager is not an option.
 */
final class Supervisor
{
    /** Wait between polling child processes. */
    private const POLL_INTERVAL_US = 250_000;

    /** Refuse to spawn more than this, to catch a fat-fingered --workers. */
    private const MAX_WORKERS = 64;

    /** Do not respawn faster than this, so a crash-looping worker cannot spin. */
    private const RESPAWN_BACKOFF_SECONDS = 2;

    private bool $shouldQuit = false;

    /** @var array<int, array{process: resource, started: float}> */
    private array $children = [];

    /**
     * @param  array<string, mixed> $options Passed through to each worker
     * @return int Exit code
     */
    public function run(string $queue = 'default', int $workers = 4, array $options = [], ?callable $report = null): int
    {
        $workers = max(1, min($workers, self::MAX_WORKERS));
        $report ??= static function (): void {};

        if (!function_exists('proc_open')) {
            $report('error', 'proc_open() is disabled, so workers cannot be spawned. Run `queue:work` directly.');

            return 1;
        }

        $this->installSignalHandlers();

        $command = $this->workerCommand($queue, $options);
        $lastSpawnAt = [];

        while (!$this->shouldQuit) {
            for ($slot = 0; $slot < $workers; $slot++) {
                if (isset($this->children[$slot]) && $this->isRunning($slot)) {
                    continue;
                }

                if (isset($this->children[$slot])) {
                    $this->reap($slot);
                    $report('warn', "Worker {$slot} exited; restarting.");
                }

                // A worker that dies instantly — a fatal on boot, a bad config —
                // would otherwise be respawned in a tight loop.
                $since = microtime(true) - ($lastSpawnAt[$slot] ?? 0);
                if ($since < self::RESPAWN_BACKOFF_SECONDS) {
                    continue;
                }

                if ($this->spawn($slot, $command)) {
                    $lastSpawnAt[$slot] = microtime(true);
                    $report('info', "Worker {$slot} started.");
                    continue;
                }

                $report('error', "Worker {$slot} failed to start.");
            }

            $this->dispatchSignals();
            usleep(self::POLL_INTERVAL_US);
        }

        $report('info', 'Shutting down — waiting for workers to finish their current job.');
        $this->stopChildren($report);

        return 0;
    }

    /**
     * Build the command that runs one worker.
     *
     * @param array<string, mixed> $options
     * @return list<string>
     */
    public function workerCommand(string $queue, array $options = []): array
    {
        $command = [PHP_BINARY, $this->consolePath(), 'queue:work', $queue];

        // Only forward the options queue:work understands; --workers is ours.
        foreach (['sleep', 'tries', 'timeout', 'max-priority'] as $name) {
            if (isset($options[$name]) && $options[$name] !== '') {
                $command[] = '--' . $name . '=' . (string) $options[$name];
            }
        }

        return $command;
    }

    private function consolePath(): string
    {
        return (defined('ROOT_DIR') ? ROOT_DIR : getcwd() . DIRECTORY_SEPARATOR) . 'myth';
    }

    /** @param list<string> $command */
    private function spawn(int $slot, array $command): bool
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => STDOUT,
            2 => STDERR,
        ];

        $pipes = [];
        $process = @proc_open($command, $descriptors, $pipes);

        if (!is_resource($process)) {
            return false;
        }

        // The worker never reads stdin; leaving it open would keep the child
        // waiting on a pipe that is never written to.
        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }

        $this->children[$slot] = ['process' => $process, 'started' => microtime(true)];

        return true;
    }

    private function isRunning(int $slot): bool
    {
        $status = @proc_get_status($this->children[$slot]['process']);

        return is_array($status) && ($status['running'] ?? false) === true;
    }

    private function reap(int $slot): void
    {
        @proc_close($this->children[$slot]['process']);
        unset($this->children[$slot]);
    }

    /**
     * Ask every worker to stop, then wait.
     *
     * The restart signal is used rather than SIGTERM because it is the mechanism
     * workers already check between jobs, so no job is interrupted mid-flight.
     */
    private function stopChildren(callable $report): void
    {
        RestartSignal::request();

        $deadline = microtime(true) + 60;

        while ($this->children !== [] && microtime(true) < $deadline) {
            foreach (array_keys($this->children) as $slot) {
                if (!$this->isRunning($slot)) {
                    $this->reap($slot);
                }
            }

            usleep(self::POLL_INTERVAL_US);
        }

        foreach (array_keys($this->children) as $slot) {
            $report('warn', "Worker {$slot} did not stop within 60s; terminating.");
            @proc_terminate($this->children[$slot]['process']);
            $this->reap($slot);
        }

        // Leaving the signal set would make freshly started workers exit at once.
        RestartSignal::clear();
    }

    private function installSignalHandlers(): void
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }

        foreach (['SIGTERM', 'SIGINT'] as $name) {
            if (defined($name)) {
                @pcntl_signal((int) constant($name), function (): void {
                    $this->shouldQuit = true;
                });
            }
        }
    }

    private function dispatchSignals(): void
    {
        if (function_exists('pcntl_signal_dispatch')) {
            @pcntl_signal_dispatch();
        }
    }
}
