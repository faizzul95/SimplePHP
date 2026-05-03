<?php

use Core\Database\Schema\Schema;
use Core\Database\Schema\Blueprint;
use Core\Database\Schema\Migration;

return new class extends Migration
{
    protected string $table = 'system_abilities';
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::create($this->table, function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_general_ci');

            $table->id();
            $table->string('abilities_name', 50)->nullable();
            $table->string('abilities_slug', 100)->nullable();
            $table->string('abilities_desc')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table);
    }
};
