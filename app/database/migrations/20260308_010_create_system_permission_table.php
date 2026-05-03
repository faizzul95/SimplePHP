<?php

use Core\Database\Schema\Schema;
use Core\Database\Schema\Blueprint;
use Core\Database\Schema\Migration;

return new class extends Migration
{
    protected string $table = 'system_permission';
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::create($this->table, function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_general_ci');

            $table->id();
            $table->foreignId('role_id')->nullable()->constrained('master_roles')->cascadeOnDelete()->comment('Refer to master_roles');
            $table->foreignId('abilities_id')->nullable()->constrained('system_abilities')->cascadeOnDelete()->comment('Refer to system_abilities');
            $table->boolean('access_device_type')->nullable()->default(1)->comment('1 - Web, 2 - Mobile');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table);
    }
};
