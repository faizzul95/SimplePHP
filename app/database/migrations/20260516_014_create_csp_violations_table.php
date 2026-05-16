<?php

use Core\Database\Schema\Blueprint;
use Core\Database\Schema\Migration;
use Core\Database\Schema\Schema;

return new class extends Migration
{
    protected string $table = 'csp_violations';
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::create($this->table, function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_unicode_ci');
            $table->comment('Content Security Policy violation reports');
            $table->ifNotExists();

            $table->id();
            $table->string('document_uri', 2048)->nullable();
            $table->string('violated_directive', 255)->nullable();
            $table->string('effective_directive', 255)->nullable();
            $table->string('blocked_uri', 2048)->nullable();
            $table->string('source_file', 2048)->nullable();
            $table->integer('line_number')->nullable();
            $table->integer('column_number')->nullable();
            $table->integer('status_code')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->longText('sample')->nullable();
            $table->longText('original_policy')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('created_at', 'idx_csp_violations_created_at');
            $table->index('effective_directive', 'idx_csp_violations_effective_directive');
            $table->index('violated_directive', 'idx_csp_violations_violated_directive');
            $table->index('status_code', 'idx_csp_violations_status_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table);
    }
};