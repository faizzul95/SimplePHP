<?php

use Core\Database\Schema\Schema;
use Core\Database\Schema\Blueprint;
use Core\Database\Schema\Migration;

return new class extends Migration
{
    protected string $table = 'user_profile';
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::create($this->table, function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_general_ci');

            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete()->comment('Refer table users');
            $table->foreignId('role_id')->nullable()->constrained('master_roles')->cascadeOnDelete()->comment('Refer table master_roles');
            $table->boolean('is_main')->nullable()->comment('0-No, 1-Yes');
            $table->boolean('profile_status')->nullable()->comment('0-Inactive, 1-Active');
            $table->timestamps();
            $table->softDeletes();

            $table->index('user_id', 'user_profile_user_id_index');
            $table->index(['role_id', 'user_id'], 'user_profile_role_user_index');
            $table->index(['user_id', 'profile_status'], 'user_profile_user_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table);
    }
};
