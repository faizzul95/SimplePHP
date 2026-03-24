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

        $authConfig = (array) (config('auth') ?? []);

        if (($authConfig['api_key']['enabled'] ?? false) === true) {
            Schema::create('users_api_keys', function (Blueprint $table) {
                $table->charset('utf8mb4');
                $table->collation('utf8mb4_general_ci');

                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete()->comment('Refer table users');
                $table->string('name')->comment('API key label');
                $table->string('api_key')->unique()->comment('SHA-256 hashed API key');
                $table->text('abilities')->nullable()->comment('JSON array of key abilities');
                $table->boolean('is_active')->default(1);
                $table->dateTime('expires_at')->nullable();
                $table->dateTime('last_used_at')->nullable();
                $table->timestamps();

                $table->index(['user_id'], 'idx_users_api_keys_user');
                $table->index(['is_active'], 'idx_users_api_keys_active');
                $table->index(['expires_at'], 'idx_users_api_keys_expiry');
            });
        }

        if (($authConfig['oauth2']['enabled'] ?? false) === true) {
            Schema::create('oauth2_access_tokens', function (Blueprint $table) {
                $table->charset('utf8mb4');
                $table->collation('utf8mb4_general_ci');

                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete()->comment('Refer table users');
                $table->string('name')->nullable();
                $table->string('token')->unique()->comment('SHA-256 hashed OAuth2 token (when hash_tokens=true)');
                $table->text('scopes')->nullable()->comment('JSON array of oauth scopes');
                $table->boolean('revoked')->default(0);
                $table->dateTime('expires_at')->nullable();
                $table->dateTime('last_used_at')->nullable();
                $table->timestamps();

                $table->index(['user_id'], 'idx_oauth2_tokens_user');
                $table->index(['revoked'], 'idx_oauth2_tokens_revoked');
                $table->index(['expires_at'], 'idx_oauth2_tokens_expiry');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth2_access_tokens');
        Schema::dropIfExists('users_api_keys');
        Schema::dropIfExists($this->table);
    }
};
