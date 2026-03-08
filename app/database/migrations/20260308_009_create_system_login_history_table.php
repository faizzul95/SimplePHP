<?php

use Core\Database\Schema\Schema;
use Core\Database\Schema\Blueprint;
use Core\Database\Schema\Migration;

return new class extends Migration
{
    protected string $table = 'system_login_history';
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::create($this->table, function (Blueprint $table) {
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_general_ci');

            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete()->comment('Refer table users');
            $table->string('ip_address', 128)->nullable();
            $table->boolean('login_type')->nullable()->default(1)->comment('1-CREDENTIAL, 2-SOCIALITE, 3-TOKEN');
            $table->string('operating_system', 50)->nullable();
            $table->string('browsers', 50)->nullable();
            $table->timestamp('time')->nullable();
            $table->string('user_agent', 200)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table);
    }
};
