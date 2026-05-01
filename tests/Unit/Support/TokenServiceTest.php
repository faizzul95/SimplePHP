<?php

declare(strict_types=1);

use App\Support\Auth\TokenService;
use PHPUnit\Framework\TestCase;

final class InspectableTokenService extends TokenService
{
    public array $inserted = [];
    public array $deleted = [];
    public array $deletedByUser = [];
    public array $touched = [];
    public array $queries = [];
    public ?array $tokenRecord = null;
    public array $tokenRows = [];
    public ?array $userRecord = null;
    public string $generatedToken = 'plain-token-value';
    public string $fixedNow = '2026-04-19 12:00:00';
    public mixed $insertResult = ['code' => 200];
    public mixed $deleteResult = ['code' => 200, 'affected_rows' => 1];
    public mixed $deleteByIdResult = ['code' => 200, 'affected_rows' => 1];
    public array $findByIdCalls = [];
    public array $deleteByIdCalls = [];

    protected function ensureTokenTable(callable $safeTable): void
    {
        parent::ensureTokenTable($safeTable);
    }

    protected function generatePlainToken(): string
    {
        return $this->generatedToken;
    }

    protected function now(): string
    {
        return $this->fixedNow;
    }

    protected function findTokenRecord(string $tokenTable, string $tokenColumn, string $hashedToken, string $selectColumns): ?array
    {
        return $this->tokenRecord;
    }

    protected function findTokenRecordByIdAndHash(string $tokenTable, string $tokenIdColumn, string $tokenColumn, int $tokenId, string $hashedToken, string $selectColumns): ?array
    {
        $this->findByIdCalls[] = [
            'table' => $tokenTable,
            'id_column' => $tokenIdColumn,
            'token_column' => $tokenColumn,
            'id' => $tokenId,
            'hash' => $hashedToken,
        ];

        return $this->tokenRecord;
    }

    protected function touchTokenRecord(string $tokenTable, string $tokenIdColumn, mixed $tokenId, array $updates): void
    {
        $this->touched[] = [
            'table' => $tokenTable,
            'id_column' => $tokenIdColumn,
            'id' => $tokenId,
            'updates' => $updates,
        ];
    }

    protected function findUserRecord(string $usersTable, string $userIdColumn, int $userId, string $selectColumns): ?array
    {
        return $this->userRecord;
    }

    protected function findTokensForUserRecord(string $tokenTable, string $tokenUserIdColumn, int $userId, string $selectColumns, string $orderByColumn): array
    {
        return $this->tokenRows;
    }

    protected function insertTokenRecord(string $tokenTable, array $payload): mixed
    {
        $this->inserted[] = [
            'table' => $tokenTable,
            'payload' => $payload,
        ];

        return $this->insertResult;
    }

    protected function deleteTokenRecord(string $tokenTable, string $tokenColumn, string $hashedToken): mixed
    {
        $this->deleted[] = [
            'table' => $tokenTable,
            'column' => $tokenColumn,
            'hash' => $hashedToken,
        ];

        return $this->deleteResult;
    }

    protected function deleteTokenRecordByIdAndHash(string $tokenTable, string $tokenIdColumn, string $tokenColumn, int $tokenId, string $hashedToken): mixed
    {
        $this->deleteByIdCalls[] = [
            'table' => $tokenTable,
            'id_column' => $tokenIdColumn,
            'column' => $tokenColumn,
            'id' => $tokenId,
            'hash' => $hashedToken,
        ];

        return $this->deleteByIdResult;
    }

    protected function deleteTokensForUser(string $tokenTable, string $userIdColumn, int $userId): void
    {
        $this->deletedByUser[] = [
            'table' => $tokenTable,
            'column' => $userIdColumn,
            'user_id' => $userId,
        ];
    }

    protected function runQuery(string $sql): void
    {
        $this->queries[] = $sql;
    }
}

final class TokenServiceTest extends TestCase
{
    public function testCreateTokenHashesStoredTokenAndReturnsPlainToken(): void
    {
        $service = new InspectableTokenService([
            'token_table' => 'users_access_tokens',
            'token_columns' => [
                'id' => 'id',
                'user_id' => 'user_id',
                'name' => 'name',
                'token' => 'token',
                'abilities' => 'abilities',
                'expires_at' => 'expires_at',
                'last_used_at' => 'last_used_at',
                'created_at' => 'created_at',
                'updated_at' => 'updated_at',
            ],
        ]);
        $service->insertResult = ['code' => 200, 'id' => 91];

        $plainToken = $service->createToken(
            44,
            'CLI',
            strtotime('2026-05-01 10:00:00'),
            ['reports.read'],
            fn(string $column, string $fallback = 'id'): string => $column !== '' ? $column : $fallback,
            fn(string $table): string => $table
        );

        self::assertSame('91|plain-token-value', $plainToken);
        self::assertCount(1, $service->queries);
        self::assertCount(1, $service->inserted);
        self::assertSame(hash('sha256', 'plain-token-value'), $service->inserted[0]['payload']['token']);
        self::assertSame('["reports.read"]', $service->inserted[0]['payload']['abilities']);
    }

    public function testCreateTokenFallsBackToLegacyPlainTokenWhenInsertIdIsUnavailable(): void
    {
        $service = new InspectableTokenService([
            'token_table' => 'users_access_tokens',
            'token_columns' => [
                'id' => 'id',
                'user_id' => 'user_id',
                'name' => 'name',
                'token' => 'token',
                'abilities' => 'abilities',
                'expires_at' => 'expires_at',
                'last_used_at' => 'last_used_at',
                'created_at' => 'created_at',
                'updated_at' => 'updated_at',
            ],
        ]);

        $plainToken = $service->createToken(
            44,
            'CLI',
            null,
            ['reports.read'],
            fn(string $column, string $fallback = 'id'): string => $column !== '' ? $column : $fallback,
            fn(string $table): string => $table
        );

        self::assertSame('plain-token-value', $plainToken);
    }

    public function testTokenUserTouchesLastUsedAtAndReturnsResolvedUser(): void
    {
        $service = new InspectableTokenService([
            'token_table' => 'users_access_tokens',
            'users_table' => 'users',
            'token_columns' => [
                'id' => 'id',
                'user_id' => 'user_id',
                'name' => 'name',
                'token' => 'token',
                'abilities' => 'abilities',
                'expires_at' => 'expires_at',
                'last_used_at' => 'last_used_at',
                'updated_at' => 'updated_at',
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
        $service->tokenRecord = [
            'id' => 9,
            'user_id' => 88,
            'name' => 'Mobile',
            'abilities' => '["exports.read"]',
            'expires_at' => '2026-05-01 10:00:00',
        ];
        $service->userRecord = [
            'id' => 88,
            'name' => 'Alya',
            'preferred_name' => 'Alya',
            'email' => 'alya@example.com',
            'username' => 'alya',
            'user_status' => 1,
        ];

        $user = $service->tokenUser(
            fn(): ?string => '9|bearer-token',
            fn(string $column, string $fallback = 'id'): string => $column !== '' ? $column : $fallback,
            fn(string $table): string => $table,
            fn(mixed $value): bool => true,
            fn(array $row): bool => ((int) ($row['user_status'] ?? 0)) === 1
        );

        self::assertSame('token', $user['auth_type']);
        self::assertSame(9, $user['token_id']);
        self::assertSame(['exports.read'], $user['abilities']);
        self::assertSame(9, $service->findByIdCalls[0]['id']);
        self::assertSame(hash('sha256', 'bearer-token'), $service->findByIdCalls[0]['hash']);
        self::assertCount(1, $service->touched);
        self::assertSame('2026-04-19 12:00:00', $service->touched[0]['updates']['last_used_at']);
    }

    public function testTokenUserDoesNotTouchLastUsedAtWhenResolvedUserIsRejected(): void
    {
        $service = new InspectableTokenService([
            'token_table' => 'users_access_tokens',
            'users_table' => 'users',
            'token_columns' => [
                'id' => 'id',
                'user_id' => 'user_id',
                'name' => 'name',
                'token' => 'token',
                'abilities' => 'abilities',
                'expires_at' => 'expires_at',
                'last_used_at' => 'last_used_at',
                'updated_at' => 'updated_at',
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
        $service->tokenRecord = [
            'id' => 9,
            'user_id' => 88,
            'name' => 'Mobile',
            'abilities' => '[]',
            'expires_at' => '2026-05-01 10:00:00',
        ];
        $service->userRecord = [
            'id' => 88,
            'name' => 'Alya',
            'preferred_name' => 'Alya',
            'email' => 'alya@example.com',
            'username' => 'alya',
            'user_status' => 0,
        ];

        $user = $service->tokenUser(
            fn(): ?string => 'bearer-token',
            fn(string $column, string $fallback = 'id'): string => $column !== '' ? $column : $fallback,
            fn(string $table): string => $table,
            fn(mixed $value): bool => true,
            fn(array $row): bool => ((int) ($row['user_status'] ?? 0)) === 1
        );

        self::assertNull($user);
        self::assertSame([], $service->touched);
    }

    public function testRevokeTokenDeletesHashedTokenValue(): void
    {
        $service = new InspectableTokenService([
            'token_table' => 'users_access_tokens',
            'token_columns' => [
                'token' => 'token',
            ],
        ]);

        $revoked = $service->revokeToken(
            '9|plain-token-value',
            fn(string $column, string $fallback = 'id'): string => $column !== '' ? $column : $fallback,
            fn(string $table): string => $table
        );

        self::assertTrue($revoked);
        self::assertSame(9, $service->deleteByIdCalls[0]['id']);
        self::assertSame(hash('sha256', 'plain-token-value'), $service->deleteByIdCalls[0]['hash']);
        self::assertSame([], $service->deleted);
    }

    public function testRevokeTokenFallsBackToLegacyHashDeletionWhenIdTargetedDeleteMisses(): void
    {
        $service = new InspectableTokenService([
            'token_table' => 'users_access_tokens',
            'token_columns' => [
                'id' => 'id',
                'token' => 'token',
            ],
        ]);
        $service->deleteByIdResult = ['code' => 200, 'affected_rows' => 0];

        $revoked = $service->revokeToken(
            '9|plain-token-value',
            fn(string $column, string $fallback = 'id'): string => $column !== '' ? $column : $fallback,
            fn(string $table): string => $table
        );

        self::assertTrue($revoked);
        self::assertSame(hash('sha256', '9|plain-token-value'), $service->deleted[0]['hash']);
    }

    public function testTokensForUserReturnsNormalizedTokenRows(): void
    {
        $service = new InspectableTokenService([
            'token_table' => 'users_access_tokens',
            'token_columns' => [
                'id' => 'id',
                'user_id' => 'user_id',
                'name' => 'name',
                'abilities' => 'abilities',
                'expires_at' => 'expires_at',
                'last_used_at' => 'last_used_at',
                'created_at' => 'created_at',
            ],
        ]);
        $service->tokenRows = [
            [
                'id' => 10,
                'name' => 'CLI',
                'abilities' => '["reports.read"]',
                'expires_at' => '2030-05-01 10:00:00',
                'last_used_at' => '2030-04-01 10:00:00',
                'created_at' => '2030-03-01 10:00:00',
            ],
        ];

        $tokens = $service->tokensForUser(
            44,
            fn(string $column, string $fallback = 'id'): string => $column !== '' ? $column : $fallback,
            fn(string $table): string => $table
        );

        self::assertCount(1, $tokens);
        self::assertSame(10, $tokens[0]['id']);
        self::assertSame('CLI', $tokens[0]['name']);
        self::assertSame(['reports.read'], $tokens[0]['abilities']);
    }

    public function testCurrentTokenReturnsResolvedTokenMetadata(): void
    {
        $service = new InspectableTokenService([
            'token_table' => 'users_access_tokens',
            'token_columns' => [
                'id' => 'id',
                'user_id' => 'user_id',
                'name' => 'name',
                'token' => 'token',
                'abilities' => 'abilities',
                'expires_at' => 'expires_at',
                'last_used_at' => 'last_used_at',
                'created_at' => 'created_at',
            ],
        ]);
        $service->tokenRecord = [
            'id' => 9,
            'user_id' => 88,
            'name' => 'Mobile',
            'abilities' => '["exports.read"]',
            'expires_at' => '2030-05-01 10:00:00',
            'last_used_at' => '2030-04-01 10:00:00',
            'created_at' => '2030-03-01 10:00:00',
        ];

        $token = $service->currentToken(
            '9|bearer-token',
            fn(string $column, string $fallback = 'id'): string => $column !== '' ? $column : $fallback,
            fn(string $table): string => $table
        );

        self::assertSame(9, $token['id']);
        self::assertSame(88, $token['user_id']);
        self::assertSame('Mobile', $token['name']);
        self::assertSame(['exports.read'], $token['abilities']);
    }

    public function testRotateTokenRevokesOldTokenAndReturnsReplacement(): void
    {
        $service = new InspectableTokenService([
            'token_table' => 'users_access_tokens',
            'token_columns' => [
                'id' => 'id',
                'user_id' => 'user_id',
                'name' => 'name',
                'token' => 'token',
                'abilities' => 'abilities',
                'expires_at' => 'expires_at',
                'last_used_at' => 'last_used_at',
                'created_at' => 'created_at',
                'updated_at' => 'updated_at',
            ],
        ]);
        $service->tokenRecord = [
            'id' => 9,
            'user_id' => 88,
            'name' => 'Mobile',
            'abilities' => '["exports.read"]',
            'expires_at' => '2030-05-01 10:00:00',
            'last_used_at' => '2030-04-01 10:00:00',
            'created_at' => '2030-03-01 10:00:00',
        ];
        $service->insertResult = ['code' => 200, 'id' => 91];

        $replacement = $service->rotateToken(
            '9|bearer-token',
            '',
            null,
            [],
            fn(string $column, string $fallback = 'id'): string => $column !== '' ? $column : $fallback,
            fn(string $table): string => $table
        );

        self::assertSame('91|plain-token-value', $replacement);
        self::assertCount(1, $service->deleteByIdCalls);
        self::assertCount(1, $service->inserted);
        self::assertSame(88, $service->inserted[0]['payload']['user_id']);
        self::assertSame('Mobile', $service->inserted[0]['payload']['name']);
        self::assertSame('["exports.read"]', $service->inserted[0]['payload']['abilities']);
    }
}