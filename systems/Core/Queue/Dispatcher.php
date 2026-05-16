<?php

namespace Core\Queue;

use Core\Database\Schema\Schema;

/**
 * Queue Dispatcher
 *
 * Pushes jobs to the configured queue driver (database or sync).
 *
 * Usage:
 *   $dispatcher = new Dispatcher();
 *   $dispatcher->dispatch(new SendWelcomeEmail($userId));
 *
 * Or via helper:
 *   dispatch(new SendWelcomeEmail($userId));
 */
class Dispatcher
{
    private array $config;
    private string $driver;

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? (\config('queue') ?? []);
        $this->driver = $this->config['default'] ?? 'sync';
    }

    /**
     * Dispatch a job to the queue.
     *
     * @param Job $job The job instance to dispatch
     * @return string|null The job ID (database/redis driver) or null (sync driver)
     */
    public function dispatch(Job $job): ?string
    {
        if ($this->driver === 'sync') {
            return $this->dispatchSync($job);
        }

        if ($this->driver === 'redis') {
            return $this->dispatchToRedis($job);
        }

        $this->ensureTable();

        return $this->dispatchToDatabase($job);
    }

    /**
     * Push a job to the Redis queue.
     */
    private function dispatchToRedis(Job $job): ?string
    {
        $connConfig = $this->config['connections']['redis'] ?? [];
        try {
            $redisQueue = new RedisQueue($connConfig);
            return $redisQueue->push($job, $job->queue, $job->delay);
        } catch (\Throwable $e) {
            if (function_exists('logger')) {
                logger()->log_error('Redis queue dispatch failed [' . get_class($job) . ']: ' . $e->getMessage());
            }
            // Fallback to sync
            return $this->dispatchSync($job);
        }
    }

    /**
     * Execute a job immediately (sync driver).
     */
    private function dispatchSync(Job $job): null
    {
        try {
            $job->handle();
        } catch (\Throwable $e) {
            $job->failed($e);

            if (function_exists('logger')) {
                logger()->log_error('Sync job failed [' . get_class($job) . ']: ' . $e->getMessage());
            }

            throw $e;
        }

        return null;
    }

    /**
     * Push a job to the database queue table.
     */
    private function dispatchToDatabase(Job $job): ?string
    {
        $connConfig = $this->config['connections']['database'] ?? [];
        $table = $connConfig['table'] ?? 'system_jobs';

        $payload = $job->toPayload();
        $now = date('Y-m-d H:i:s');
        $availableAt = $job->delay > 0
            ? date('Y-m-d H:i:s', time() + $job->delay)
            : $now;

        $id = $this->generateId();

        try {
            db()->table($table)->insert([
                'id'           => $id,
                'queue'        => $payload['queue'],
                'payload'      => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'priority'     => $this->normalizePriority((int) ($payload['priority'] ?? $job->priority ?? Job::PRIORITY_NORMAL)),
                'attempts'     => 0,
                'reserved_at'  => null,
                'available_at' => $availableAt,
                'created_at'   => $now,
            ]);

            return $id;
        } catch (\Throwable $e) {
            if (function_exists('logger')) {
                logger()->log_error('Failed to dispatch job [' . get_class($job) . ']: ' . $e->getMessage());
            }

            return null;
        }
    }

    /**
     * Generate a unique job ID.
     */
    private function generateId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Ensure the jobs table exists (auto-migration).
     */
    public function ensureTable(): void
    {
        $connConfig = $this->config['connections']['database'] ?? [];
        $table = $connConfig['table'] ?? 'system_jobs';
        $failedTable = $connConfig['failed_table'] ?? 'system_failed_jobs';

        $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        $safeFailedTable = preg_replace('/[^a-zA-Z0-9_]/', '', $failedTable);

        db()->rawQuery("
            CREATE TABLE IF NOT EXISTS `{$safeTable}` (
                `id` VARCHAR(64) NOT NULL,
                `queue` VARCHAR(100) NOT NULL DEFAULT 'default',
                `payload` LONGTEXT NOT NULL,
                `priority` TINYINT UNSIGNED NOT NULL DEFAULT 5,
                `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `reserved_at` DATETIME NULL,
                `available_at` DATETIME NOT NULL,
                `created_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                INDEX `idx_queue_priority_available` (`queue`, `priority`, `available_at`),
                INDEX `idx_reserved` (`reserved_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        db()->rawQuery("
            CREATE TABLE IF NOT EXISTS `{$safeFailedTable}` (
                `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `queue` VARCHAR(100) NOT NULL DEFAULT 'default',
                `payload` LONGTEXT NOT NULL,
                `priority` TINYINT UNSIGNED NOT NULL DEFAULT 5,
                `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `error` TEXT NULL,
                `failed_at` DATETIME NOT NULL,
                INDEX `idx_queue` (`queue`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->ensureExistingPriorityColumns($safeTable, $safeFailedTable);
    }

    private function ensureExistingPriorityColumns(string $jobsTable, string $failedTable): void
    {
        try {
            if (Schema::hasTable($jobsTable) && !Schema::hasColumn($jobsTable, 'priority')) {
                db()->rawQuery("ALTER TABLE `{$jobsTable}` ADD COLUMN `priority` TINYINT UNSIGNED NOT NULL DEFAULT 5 AFTER `payload`");
            }

            if (Schema::hasTable($failedTable) && !Schema::hasColumn($failedTable, 'priority')) {
                db()->rawQuery("ALTER TABLE `{$failedTable}` ADD COLUMN `priority` TINYINT UNSIGNED NOT NULL DEFAULT 5 AFTER `payload`");
            }

            if (Schema::hasTable($jobsTable) && !$this->hasIndex($jobsTable, 'idx_queue_priority_available')) {
                db()->rawQuery("ALTER TABLE `{$jobsTable}` ADD INDEX `idx_queue_priority_available` (`queue`, `priority`, `available_at`)");
            }
        } catch (\Throwable $e) {
            if (function_exists('logger')) {
                logger()->log_error('Queue schema backfill failed: ' . $e->getMessage());
            }
        }
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        $statement = db()->getPdo()->prepare("SHOW INDEX FROM `{$table}`");
        if ($statement === false) {
            return false;
        }

        $statement->execute();
        $indexes = $statement->fetchAll(\PDO::FETCH_ASSOC);
        $statement->closeCursor();

        foreach ((array) $indexes as $index) {
            if (($index['Key_name'] ?? null) === $indexName) {
                return true;
            }
        }

        return false;
    }

    private function normalizePriority(int $priority): int
    {
        return max(Job::PRIORITY_CRITICAL, min(Job::PRIORITY_BULK, $priority));
    }
}
