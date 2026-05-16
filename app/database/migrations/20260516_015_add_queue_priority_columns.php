<?php

use Core\Database\Schema\Blueprint;
use Core\Database\Schema\Migration;
use Core\Database\Schema\Schema;

return new class extends Migration
{
    protected string $connection = 'default';

    public function up(): void
    {
        $this->ensurePriorityColumn('system_jobs', 'idx_queue_priority_available');
        $this->ensureFailedPriorityColumn('system_failed_jobs');
    }

    public function down(): void
    {
        // Keep priority columns in place on rollback to avoid breaking queued data.
    }

    private function ensurePriorityColumn(string $table, string $indexName): void
    {
        if (!Schema::hasTable($table)) {
            return;
        }

        if (!Schema::hasColumn($table, 'priority')) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->tinyInteger('priority')->default(5)->after('payload');
            });
        }

        if (!$this->hasIndex($table, $indexName)) {
            Schema::table($table, function (Blueprint $blueprint) use ($indexName) {
                $blueprint->index(['queue', 'priority', 'available_at'], $indexName);
            });
        }
    }

    private function ensureFailedPriorityColumn(string $table): void
    {
        if (!Schema::hasTable($table) || Schema::hasColumn($table, 'priority')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->tinyInteger('priority')->default(5)->after('payload');
        });
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        $safeTable = str_replace('`', '``', $table);
        $statement = db()->getPdo()->prepare("SHOW INDEX FROM `{$safeTable}`");
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
};