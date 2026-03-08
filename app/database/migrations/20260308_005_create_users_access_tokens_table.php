<?php

use Core\Database\Schema\Schema;
use Core\Database\Schema\Blueprint;
use Core\Database\Schema\Migration;

return new class extends Migration
{
    protected string $table = 'users_access_tokens';
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::create($this->table, function (Blueprint $table) {
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_general_ci');

            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete()->comment('Refer table users');
            $table->string('name')->comment('Token name/label');
            $table->string('token')->unique()->comment('SHA-256 hashed token');
            $table->text('abilities')->nullable()->comment('JSON array of token abilities');
            $table->dateTime('expires_at')->nullable()->comment('Token expiration datetime');
            $table->dateTime('last_used_at')->nullable()->comment('Last usage datetime');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table);
    }
};
