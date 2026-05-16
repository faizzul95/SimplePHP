<?php

use Core\Database\Schema\Blueprint;
use Core\Database\Schema\Migration;
use Core\Database\Schema\Schema;

return new class extends Migration
{
    protected string $table = 'ip_blocklist';
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::create($this->table, function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_unicode_ci');
            $table->comment('Dynamic IP blocklist with optional expiry');
            $table->ifNotExists();

            $table->string('ip_address', 45)->primary();
            $table->string('reason', 255);
            $table->timestamp('blocked_at')->useCurrent();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('auto_added')->default(0);

            $table->index('expires_at', 'idx_ip_blocklist_expires_at');
            $table->index('auto_added', 'idx_ip_blocklist_auto_added');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table);
    }
};