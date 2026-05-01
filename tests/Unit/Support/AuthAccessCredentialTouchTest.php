<?php

declare(strict_types=1);

use Components\Auth;
use PHPUnit\Framework\TestCase;

final class AuthAccessCredentialTouchProbe extends Auth
{
    public ?array $oauth2Record = null;
    public ?array $apiKeyRecord = null;
    public ?array $resolvedUserRecord = null;
    public array $oauth2Touches = [];
    public array $apiKeyTouches = [];
    public ?string $resolvedBearerToken = 'oauth-token';
    public ?string $resolvedApiKey = 'api-key';

    public function bearerToken(): ?string
    {
        return $this->resolvedBearerToken;
    }

    protected function extractApiKey(): ?string
    {
        return $this->resolvedApiKey;
    }

    protected function findOAuth2TokenRecord(string $table, string $tokenColumn, string $tokenLookup, string $selectColumns): ?array
    {
        return $this->oauth2Record;
    }

    protected function touchOAuth2TokenRecord(string $table, string $idColumn, mixed $tokenId, array $updates): void
    {
        $this->oauth2Touches[] = [
            'table' => $table,
            'id_column' => $idColumn,
            'id' => $tokenId,
            'updates' => $updates,
        ];
    }

    protected function findApiKeyRecord(string $table, string $apiKeyColumn, string $hashedApiKey, string $isActiveColumn, string $selectColumns): ?array
    {
        return $this->apiKeyRecord;
    }

    protected function touchApiKeyRecord(string $table, string $idColumn, mixed $apiKeyId, array $updates): void
    {
        $this->apiKeyTouches[] = [
            'table' => $table,
            'id_column' => $idColumn,
            'id' => $apiKeyId,
            'updates' => $updates,
        ];
    }

    protected function findConfiguredUserRecord(int $userId, string $selectColumns): ?array
    {
        return $this->resolvedUserRecord;
    }
}

final class AuthAccessCredentialTouchTest extends TestCase
{
    public function testOauth2UserTouchesLastUsedAtOnlyAfterUserPassesStatusChecks(): void
    {
        $auth = new AuthAccessCredentialTouchProbe([
            'oauth2' => [
                'enabled' => true,
                'hash_tokens' => true,
                'columns' => [
                    'id' => 'id',
                    'user_id' => 'user_id',
                    'name' => 'name',
                    'token' => 'token',
                    'scopes' => 'scopes',
                    'revoked' => 'revoked',
                    'expires_at' => 'expires_at',
                    'last_used_at' => 'last_used_at',
                    'updated_at' => 'updated_at',
                ],
            ],
            'oauth2_table' => 'oauth2_access_tokens',
            'users_table' => 'users',
            'user_columns' => [
                'id' => 'id',
                'name' => 'name',
                'preferred_name' => 'preferred_name',
                'email' => 'email',
                'username' => 'username',
                'status' => 'user_status',
            ],
            'systems_login_policy' => [
                'enforce_user_status' => true,
                'allowed_user_status' => [1],
            ],
        ]);
        $auth->oauth2Record = [
            'id' => 11,
            'user_id' => 99,
            'name' => 'OAuth Device',
            'scopes' => '["exports.read"]',
            'revoked' => 0,
            'expires_at' => '2030-05-01 10:00:00',
        ];
        $auth->resolvedUserRecord = [
            'id' => 99,
            'name' => 'Rani',
            'preferred_name' => 'Rani',
            'email' => 'rani@example.com',
            'username' => 'rani',
            'user_status' => 0,
        ];

        self::assertNull($auth->oauth2User());
        self::assertSame([], $auth->oauth2Touches);

        $auth->resolvedUserRecord['user_status'] = 1;

        $resolved = $auth->oauth2User();

        self::assertSame('oauth2', $resolved['auth_type']);
        self::assertCount(1, $auth->oauth2Touches);
    }

    public function testApiKeyUserTouchesLastUsedAtOnlyAfterUserPassesStatusChecks(): void
    {
        $auth = new AuthAccessCredentialTouchProbe([
            'api_key' => [
                'enabled' => true,
                'columns' => [
                    'id' => 'id',
                    'user_id' => 'user_id',
                    'name' => 'name',
                    'api_key' => 'api_key',
                    'abilities' => 'abilities',
                    'is_active' => 'is_active',
                    'expires_at' => 'expires_at',
                    'last_used_at' => 'last_used_at',
                    'updated_at' => 'updated_at',
                ],
            ],
            'api_key_table' => 'users_api_keys',
            'users_table' => 'users',
            'user_columns' => [
                'id' => 'id',
                'name' => 'name',
                'preferred_name' => 'preferred_name',
                'email' => 'email',
                'username' => 'username',
                'status' => 'user_status',
            ],
            'systems_login_policy' => [
                'enforce_user_status' => true,
                'allowed_user_status' => [1],
            ],
        ]);
        $auth->apiKeyRecord = [
            'id' => 12,
            'user_id' => 55,
            'name' => 'Server Key',
            'abilities' => '["reports.read"]',
            'expires_at' => '2030-05-01 10:00:00',
        ];
        $auth->resolvedUserRecord = [
            'id' => 55,
            'name' => 'Nadia',
            'preferred_name' => 'Nadia',
            'email' => 'nadia@example.com',
            'username' => 'nadia',
            'user_status' => 0,
        ];

        self::assertNull($auth->apiKeyUser());
        self::assertSame([], $auth->apiKeyTouches);

        $auth->resolvedUserRecord['user_status'] = 1;

        $resolved = $auth->apiKeyUser();

        self::assertSame('api_key', $resolved['auth_type']);
        self::assertCount(1, $auth->apiKeyTouches);
    }
}