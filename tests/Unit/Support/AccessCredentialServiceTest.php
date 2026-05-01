<?php

declare(strict_types=1);

use App\Support\Auth\AccessCredentialService;
use PHPUnit\Framework\TestCase;

final class AccessCredentialServiceTest extends TestCase
{
    public function testBasicUserRegistersFailureWhenNoAllowedUserMatches(): void
    {
        $failureCalls = [];
        $service = new AccessCredentialService([
            'basic' => [
                'enabled' => true,
                'identifier_columns' => ['username', 'email'],
            ],
            'user_columns' => [
                'id' => 'id',
                'password' => 'password',
            ],
        ]);

        $resolved = $service->basicUser(
            fn(): array => ['nadia', 'secret'],
            fn(array $credentials): bool => true,
            fn(string $identifier, array $candidateFields = ['username', 'email']): array => ['username' => $identifier],
            fn(string $safeColumn, string $identifier): ?array => [
                'id' => 12,
                'username' => 'nadia',
                'password' => password_hash('wrong-secret', PASSWORD_DEFAULT),
                'user_status' => 1,
            ],
            fn(array $user): bool => (($user['user_status'] ?? 0) === 1),
            function (array $credentials, ?int $userId = null) use (&$failureCalls): void {
                $failureCalls[] = ['credentials' => $credentials, 'user_id' => $userId];
            },
            function (array $credentials, ?int $userId = null): void {
            }
        );

        self::assertNull($resolved);
        self::assertCount(1, $failureCalls);
        self::assertSame(12, $failureCalls[0]['user_id']);
    }

    public function testBasicUserClearsFailuresAfterSuccessfulAuthentication(): void
    {
        $cleared = [];
        $service = new AccessCredentialService([
            'basic' => [
                'enabled' => true,
                'identifier_columns' => ['username'],
            ],
            'user_columns' => [
                'id' => 'id',
                'password' => 'password',
            ],
        ]);

        $resolved = $service->basicUser(
            fn(): array => ['rani', 'secret'],
            fn(array $credentials): bool => true,
            fn(string $identifier, array $candidateFields = ['username', 'email']): array => ['username' => $identifier],
            fn(string $safeColumn, string $identifier): ?array => [
                'id' => 7,
                'username' => 'rani',
                'password' => password_hash('secret', PASSWORD_DEFAULT),
                'user_status' => 1,
            ],
            fn(array $user): bool => (($user['user_status'] ?? 0) === 1),
            function (array $credentials, ?int $userId = null): void {
            },
            function (array $credentials, ?int $userId = null) use (&$cleared): void {
                $cleared[] = ['credentials' => $credentials, 'user_id' => $userId];
            }
        );

        self::assertSame('basic', $resolved['auth_type']);
        self::assertCount(1, $cleared);
        self::assertSame(7, $cleared[0]['user_id']);
        self::assertArrayNotHasKey('password', $resolved);
    }

    public function testDigestUserClearsFailuresAfterSuccessfulAuthentication(): void
    {
        $cleared = [];
        $service = new AccessCredentialService([
            'digest' => [
                'enabled' => true,
                'realm' => 'MythPHP API',
                'qop' => 'auth',
                'username_column' => 'username',
                'ha1_column' => 'digest_ha1',
            ],
            'user_columns' => [
                'id' => 'id',
                'username' => 'username',
                'digest_ha1' => 'digest_ha1',
            ],
        ]);

        $ha1 = md5('rani:MythPHP API:secret');
        $ha2 = md5('GET:/internal');
        $response = md5($ha1 . ':nonce123:00000001:cnonce123:auth:' . $ha2);

        $resolved = $service->digestUser(
            fn(): array => [
                'username' => 'rani',
                'nonce' => 'nonce123',
                'nc' => '00000001',
                'cnonce' => 'cnonce123',
                'qop' => 'auth',
                'realm' => 'MythPHP API',
                'uri' => '/internal',
                'opaque' => md5('MythPHP API'),
                'response' => $response,
            ],
            fn(array $credentials): bool => true,
            fn(string $identifier, array $candidateFields = ['username', 'email']): array => ['username' => $identifier],
            fn(string $nonce): bool => $nonce === 'nonce123',
            fn(string $uri): bool => $uri === '/internal',
            fn(string $username, string $nonce, string $nc): bool => $username === 'rani' && $nonce === 'nonce123' && $nc === '00000001',
            fn(string $safeColumn, string $username): ?array => [
                'id' => 7,
                'username' => $username,
                'digest_ha1' => $ha1,
                'user_status' => 1,
            ],
            fn(array $user): bool => (($user['user_status'] ?? 0) === 1),
            function (array $credentials, ?int $userId = null): void {
            },
            function (array $credentials, ?int $userId = null) use (&$cleared): void {
                $cleared[] = ['credentials' => $credentials, 'user_id' => $userId];
            },
            fn(): string => 'GET'
        );

        self::assertSame('digest', $resolved['auth_type']);
        self::assertSame('rani', $resolved['username']);
        self::assertArrayNotHasKey('digest_ha1', $resolved);
        self::assertCount(1, $cleared);
        self::assertSame(7, $cleared[0]['user_id']);
    }

    public function testDigestUserRejectsMalformedPayloadWithoutRecordingStateChange(): void
    {
        $failureCalls = [];
        $cleared = [];
        $service = new AccessCredentialService([
            'digest' => [
                'enabled' => true,
                'realm' => 'MythPHP API',
                'qop' => 'auth',
                'username_column' => '***',
                'ha1_column' => 'digest_ha1',
            ],
            'user_columns' => [
                'id' => 'id',
                'username' => 'username',
                'digest_ha1' => 'digest_ha1',
            ],
        ]);

        $resolved = $service->digestUser(
            fn(): array => [
                'username' => 'rani',
                'nonce' => 'nonce123',
                'nc' => '00000001',
                'cnonce' => '',
                'qop' => 'auth',
                'realm' => 'MythPHP API',
                'uri' => '/internal',
                'opaque' => md5('MythPHP API'),
                'response' => 'bad',
            ],
            fn(array $credentials): bool => true,
            fn(string $identifier, array $candidateFields = ['username', 'email']): array => ['username' => $identifier],
            fn(string $nonce): bool => true,
            fn(string $uri): bool => true,
            fn(string $username, string $nonce, string $nc): bool => true,
            fn(string $safeColumn, string $username): ?array => ['id' => 7, 'username' => $username, 'digest_ha1' => 'x', 'user_status' => 1],
            fn(array $user): bool => true,
            function (array $credentials, ?int $userId = null) use (&$failureCalls): void {
                $failureCalls[] = ['credentials' => $credentials, 'user_id' => $userId];
            },
            function (array $credentials, ?int $userId = null) use (&$cleared): void {
                $cleared[] = ['credentials' => $credentials, 'user_id' => $userId];
            },
            fn(): string => 'GET'
        );

        self::assertNull($resolved);
        self::assertSame([], $failureCalls);
        self::assertSame([], $cleared);
    }

    public function testJwtUserReturnsClaimsForAllowedUser(): void
    {
        $service = new AccessCredentialService([
            'jwt' => [
                'enabled' => true,
                'user_id_claim' => 'sub',
            ],
            'user_columns' => [
                'id' => 'id',
                'name' => 'name',
                'preferred_name' => 'preferred_name',
                'email' => 'email',
                'username' => 'username',
                'status' => 'user_status',
            ],
        ]);

        $resolved = $service->jwtUser(
            fn(): ?string => 'jwt-token',
            fn(string $jwt): ?array => ['sub' => 7, 'scope' => ['users.read']],
            fn(int $userId, string $selectColumns): ?array => [
                'id' => $userId,
                'name' => 'Alya',
                'preferred_name' => 'Alya',
                'email' => 'alya@example.com',
                'username' => 'alya',
                'user_status' => 1,
            ],
            fn(array $user): bool => (($user['user_status'] ?? 0) === 1)
        );

        self::assertSame('jwt', $resolved['auth_type']);
        self::assertSame(7, $resolved['jwt_claims']['sub']);
    }

    public function testJwtUserReturnsNullWhenResolvedUserIsRejected(): void
    {
        $service = new AccessCredentialService([
            'jwt' => [
                'enabled' => true,
                'user_id_claim' => 'sub',
            ],
            'user_columns' => [
                'id' => 'id',
                'name' => 'name',
                'preferred_name' => 'preferred_name',
                'email' => 'email',
                'username' => 'username',
                'status' => 'user_status',
            ],
        ]);

        $resolved = $service->jwtUser(
            fn(): ?string => 'jwt-token',
            fn(string $jwt): ?array => ['sub' => 9],
            fn(int $userId, string $selectColumns): ?array => [
                'id' => $userId,
                'name' => 'Nisa',
                'preferred_name' => 'Nisa',
                'email' => 'nisa@example.com',
                'username' => 'nisa',
                'user_status' => 0,
            ],
            fn(array $user): bool => (($user['user_status'] ?? 0) === 1)
        );

        self::assertNull($resolved);
    }

    public function testOauth2UserTouchesCredentialsOnlyAfterSuccessfulResolution(): void
    {
        $touches = [];
        $service = new AccessCredentialService([
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
            'user_columns' => [
                'id' => 'id',
                'name' => 'name',
                'preferred_name' => 'preferred_name',
                'email' => 'email',
                'username' => 'username',
                'status' => 'user_status',
            ],
        ]);

        $resolved = $service->oauth2User(
            fn(): ?string => 'oauth-token',
            fn(string $column, string $fallback = 'id'): string => $column,
            fn(string $table): string => $table,
            fn(string $table, string $tokenColumn, string $tokenLookup, string $selectColumns): ?array => [
                'id' => 11,
                'user_id' => 99,
                'name' => 'OAuth Device',
                'scopes' => '["exports.read"]',
                'revoked' => 0,
                'expires_at' => '2030-05-01 10:00:00',
            ],
            function (string $table, string $idColumn, mixed $tokenId, array $updates) use (&$touches): void {
                $touches[] = compact('table', 'idColumn', 'tokenId', 'updates');
            },
            fn(int $userId, string $selectColumns): ?array => [
                'id' => $userId,
                'name' => 'Rani',
                'preferred_name' => 'Rani',
                'email' => 'rani@example.com',
                'username' => 'rani',
                'user_status' => 1,
            ],
            fn(mixed $value): bool => true,
            fn(array $user): bool => (($user['user_status'] ?? 0) === 1),
            fn(mixed $rawScopes): array => ['exports.read'],
            fn(): string => '2030-05-01 09:00:00'
        );

        self::assertSame('oauth2', $resolved['auth_type']);
        self::assertCount(1, $touches);
    }

    public function testApiKeyUserSkipsTouchWhenUserIsRejected(): void
    {
        $touches = [];
        $service = new AccessCredentialService([
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
            'user_columns' => [
                'id' => 'id',
                'name' => 'name',
                'preferred_name' => 'preferred_name',
                'email' => 'email',
                'username' => 'username',
                'status' => 'user_status',
            ],
        ]);

        $resolved = $service->apiKeyUser(
            fn(): ?string => 'api-key',
            fn(string $column, string $fallback = 'id'): string => $column,
            fn(string $table): string => $table,
            fn(string $table, string $apiKeyColumn, string $hashedApiKey, string $isActiveColumn, string $selectColumns): ?array => [
                'id' => 12,
                'user_id' => 55,
                'name' => 'Server Key',
                'abilities' => '["reports.read"]',
                'expires_at' => '2030-05-01 10:00:00',
            ],
            function (string $table, string $idColumn, mixed $apiKeyId, array $updates) use (&$touches): void {
                $touches[] = compact('table', 'idColumn', 'apiKeyId', 'updates');
            },
            fn(int $userId, string $selectColumns): ?array => [
                'id' => $userId,
                'name' => 'Nadia',
                'preferred_name' => 'Nadia',
                'email' => 'nadia@example.com',
                'username' => 'nadia',
                'user_status' => 0,
            ],
            fn(mixed $value): bool => true,
            fn(array $user): bool => (($user['user_status'] ?? 0) === 1),
            fn(): string => '2030-05-01 09:00:00'
        );

        self::assertNull($resolved);
        self::assertSame([], $touches);
    }
}