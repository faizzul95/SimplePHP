<?php

use Core\Database\Schema\Blueprint;
use Core\Database\Schema\Migration;
use Core\Database\Schema\Schema;

/**
 * Security Audit Log Table Migration
 *
 * Creates the security_audit_log table for structured dual-write audit events.
 * Run: php myth migrate
 *
 */
return new class extends Migration
{
    protected string $table = 'security_audit_log';
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::create($this->table, function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_unicode_ci');
            $table->comment('Structured security audit log — dual-write with storage/logs/audit.log');
            $table->ifNotExists();

            // Primary key
            $table->id();

            // Who
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('ip_address', 45)->nullable()->comment('IPv6-safe (45 chars)');
            $table->string('user_agent', 512)->nullable();
            $table->string('request_id', 32)->nullable()->comment('Correlates all log lines for one request');

            // What
            $table->string('event_type', 100)->comment('e.g. auth.login.success');
            $table->enum('severity', ['info', 'warning', 'error', 'critical'])->default('info');
            $table->string('resource_type', 100)->nullable()->comment('e.g. user, order, file');
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->unsignedBigInteger('owner_id')->nullable()->comment('Expected owner of the resource');
            $table->boolean('is_idor_suspect')->default(0);

            // Context
            $table->string('endpoint', 512)->nullable();
            $table->string('http_method', 10)->nullable();
            $table->json('context')->nullable()->comment('Free-form key-value pairs');

            // Outcome
            $table->boolean('blocked')->default(0);
            $table->string('block_reason', 255)->nullable();

            $table->timestamp('occurred_at')->useCurrent();

            // Single-column indexes
            $table->index('user_id',       'idx_sal_user_id');
            $table->index('ip_address',    'idx_sal_ip_address');
            $table->index('event_type',    'idx_sal_event_type');
            $table->index('severity',      'idx_sal_severity');
            $table->index('is_idor_suspect', 'idx_sal_is_idor');
            $table->index('occurred_at',   'idx_sal_occurred_at');

            // Composite indexes for common audit queries
            $table->index(['event_type',  'occurred_at'],                            'idx_sal_event_time');
            $table->index(['user_id',     'event_type',  'occurred_at'],             'idx_sal_user_event');
            $table->index(['ip_address',  'occurred_at'],                            'idx_sal_ip_time');
            $table->index(['resource_type', 'resource_id', 'occurred_at'],           'idx_sal_resource');
            $table->index(['is_idor_suspect', 'occurred_at'],                        'idx_sal_idor_time');
            $table->index(['severity',    'occurred_at'],                            'idx_sal_severity_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table);
    }
};
