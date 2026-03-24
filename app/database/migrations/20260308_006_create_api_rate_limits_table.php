<?php

use Core\Database\Schema\Schema;
use Core\Database\Schema\Blueprint;
use Core\Database\Schema\Migration;

return new class extends Migration
{
    protected string $table = 'api_rate_limits';
    protected string $connection = 'default';

    public function up(): void
    {
        $rateLimit = (array) (config('api.rate_limit') ?? []);
        if (($rateLimit['enabled'] ?? false) !== true) {
            return;
        }

        Schema::create($this->table, function (Blueprint $table) {
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_general_ci');

            $table->id();
            $table->string('ip_address', 45)->comment('IPv4 or IPv6 address');
            $table->integer('requests_count')->default(1);
            $table->dateTime('window_start')->useCurrent();

            $table->index(['ip_address', 'window_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table);
    }
};
