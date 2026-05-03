<?php

use Core\Database\Schema\Schema;
use Core\Database\Schema\Blueprint;
use Core\Database\Schema\Migration;

return new class extends Migration
{
    protected string $table = 'users';
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::create($this->table, function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_general_ci');

            $table->id();
            $table->string('name')->nullable();
            $table->string('user_preferred_name', 20)->nullable();
            $table->string('email')->nullable();
            $table->tinyInteger('user_gender')->nullable()->comment('1-Male, 2-Female');
            $table->date('user_dob')->nullable();
            $table->string('user_contact_no', 15)->nullable();
            $table->string('username')->nullable();
            $table->string('password')->nullable();
            $table->timestamp('password_changed_at')->nullable();
            $table->boolean('force_password_change')->nullable()->default(0);
            $table->tinyInteger('user_status')->nullable()->default(4)->comment('0-Inactive, 1-Active, 2-Suspended, 3-Deleted, 4-Unverified');
            $table->rememberToken();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('deleted_at', 'users_deleted_at_index');
            $table->index('user_status', 'users_user_status_index');
            $table->index('user_gender', 'users_user_gender_index');
            $table->index('user_dob', 'users_user_dob_index');
            $table->index(['deleted_at', 'name'], 'users_deleted_at_name_index');
            $table->index(['deleted_at', 'email'], 'users_deleted_at_email_index');
            $table->index(['deleted_at', 'user_contact_no'], 'users_deleted_at_contact_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table);
    }
};
