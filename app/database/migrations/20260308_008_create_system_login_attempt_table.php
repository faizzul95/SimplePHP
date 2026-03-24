<?php

use Core\Database\Schema\Schema;
use Core\Database\Schema\Blueprint;
use Core\Database\Schema\Migration;

return new class extends Migration
{
    protected string $table = 'system_login_attempt';
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::create($this->table, function (Blueprint $table) {
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_general_ci');

            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete()->comment('Refer table users');
            $table->string('identifier', 191)->nullable()->comment('Normalized login identifier such as email:foo@bar.com');
            $table->string('ip_address', 128)->nullable();
            $table->timestamp('time')->nullable();
            $table->string('user_agent', 200)->nullable();
            $table->timestamps();

            $table->index('identifier');
            $table->index('ip_address');
            $table->index('time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table);
    }
};
