<?php

use Core\Database\Schema\Schema;
use Core\Database\Schema\Blueprint;
use Core\Database\Schema\Migration;

return new class extends Migration
{
    protected string $table = 'master_email_templates';
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::create($this->table, function (Blueprint $table) {
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_general_ci');

            $table->id();
            $table->string('email_type')->nullable();
            $table->string('email_subject')->nullable();
            $table->string('email_header')->nullable();
            $table->longText('email_body')->nullable();
            $table->string('email_footer')->nullable();
            $table->longText('email_cc')->nullable();
            $table->longText('email_bcc')->nullable();
            $table->boolean('email_status')->nullable()->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table);
    }
};
