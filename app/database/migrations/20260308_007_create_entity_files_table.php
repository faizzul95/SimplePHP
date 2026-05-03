<?php

use Core\Database\Schema\Schema;
use Core\Database\Schema\Blueprint;
use Core\Database\Schema\Migration;

return new class extends Migration
{
    protected string $table = 'entity_files';
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::create($this->table, function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_general_ci');

            $table->id();
            $table->string('files_name')->nullable();
            $table->string('files_original_name')->nullable();
            $table->string('files_type', 50)->nullable();
            $table->string('files_mime', 50)->nullable();
            $table->string('files_extension', 10)->nullable();
            $table->integer('files_size')->nullable()->default(0);
            $table->boolean('files_compression')->nullable();
            $table->string('files_folder')->nullable();
            $table->string('files_path')->nullable();
            $table->string('files_disk_storage', 20)->nullable()->default('public');
            $table->boolean('files_path_is_url')->nullable()->default(0);
            $table->text('files_description')->nullable();
            $table->string('entity_type')->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('entity_file_type')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete()->comment('Refer table users');
            $table->timestamps();

            $table->index(['entity_type', 'entity_id'], 'entity_files_entity_type_entity_id_index');
            $table->index(['entity_file_type', 'entity_id'], 'entity_files_file_type_entity_id_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table);
    }
};
