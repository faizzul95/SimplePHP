<?php

use Core\Database\Schema\Blueprint;
use Core\Database\Schema\Migration;
use Core\Database\Schema\Schema;

return new class extends Migration
{
    protected string $connection = 'default';

    public function up(): void
    {
        $this->ensureIndex('users', 'deleted_at', 'users_deleted_at_index');
        $this->ensureIndex('users', 'user_status', 'users_user_status_index');
        $this->ensureIndex('users', 'user_gender', 'users_user_gender_index');
        $this->ensureIndex('users', 'user_dob', 'users_user_dob_index');
        $this->ensureIndex('users', ['deleted_at', 'name'], 'users_deleted_at_name_index');
        $this->ensureIndex('users', ['deleted_at', 'email'], 'users_deleted_at_email_index');
        $this->ensureIndex('users', ['deleted_at', 'user_contact_no'], 'users_deleted_at_contact_index');

        $this->ensureIndex('user_profile', 'user_id', 'user_profile_user_id_index');
        $this->ensureIndex('user_profile', ['role_id', 'user_id'], 'user_profile_role_user_index');
        $this->ensureIndex('user_profile', ['user_id', 'profile_status'], 'user_profile_user_status_index');

        $this->ensureIndex('entity_files', ['entity_type', 'entity_id'], 'entity_files_entity_type_entity_id_index');
        $this->ensureIndex('entity_files', ['entity_file_type', 'entity_id'], 'entity_files_file_type_entity_id_index');
    }

    public function down(): void
    {
        // This migration backfills indexes that already exist in baseline table-creation
        // migrations on fresh installs. Rolling it back must not remove those baseline
        // indexes because older schemas may rely on them for foreign-key support.
    }

    private function ensureIndex(string $table, string|array $columns, string $indexName): void
    {
        if ($this->hasIndex($table, $indexName)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $indexName) {
            $blueprint->index($columns, $indexName);
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

        if (!is_array($indexes)) {
            return false;
        }

        foreach ($indexes as $index) {
            if (($index['Key_name'] ?? null) === $indexName) {
                return true;
            }
        }

        return false;
    }
};