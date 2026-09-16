<?php

declare(strict_types=1);

use Core\Auth\TokenService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Records what the limiter did, without touching a database.
 */
final class DeviceLimitTokenService extends TokenService
{
    /** @var list<array<string, mixed>> Existing tokens, least recently used first. */
    public array $existingTokens = [];

    /** @var list<int> Ids passed to deleteTokenById(), in order. */
    public array $deleted = [];

    public array $inserted = [];
    public mixed $insertResult = ['code' => 200, 'id' => 99];

    protected function generatePlainToken(): string
    {
        return 'plain-token-value';
    }

    protected function now(): string
    {
        return '2026-08-25 12:00:00';
    }

    protected function ensureTokenTable(callable $safeTable): void
    {
        // No schema in a unit test.
    }

    protected function activeTokensForLimit(
        string $tokenTable,
        string $userIdColumn,
        int $userId,
        string $idColumn,
        string $lastUsedColumn,
        string $createdColumn
    ): array {
        return $this->existingTokens;
    }

    protected function deleteTokenById(string $tokenTable, string $idColumn, int $tokenId): void
    {
        $this->deleted[] = $tokenId;
    }

    protected function insertTokenRecord(string $tokenTable, array $payload): mixed
    {
        $this->inserted[] = $payload;

        return $this->insertResult;
    }
}

/**
 * auth.session_concurrency caps how many browsers a user may be signed in from,
 * but said nothing about API tokens. "Single-device login" therefore held for the
 * web while the same user could accumulate unlimited tokens from a mobile app —
 * the surface where a device limit actually matters.
 */
final class TokenDeviceLimitTest extends TestCase
{
    /** @param array<string, mixed> $tokenConfig */
    private function service(array $tokenConfig, int $existing = 0): DeviceLimitTokenService
    {
        $service = new DeviceLimitTokenService([
            'token_table' => 'users_access_tokens',
            'token' => $tokenConfig,
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

        // Ordered least-recently-used first, as the query returns them.
        for ($i = 1; $i <= $existing; $i++) {
            $service->existingTokens[] = ['id' => $i, 'last_used_at' => null, 'created_at' => '2026-01-0' . $i];
        }

        return $service;
    }

    private function issue(DeviceLimitTokenService $service): ?string
    {
        return $service->createToken(
            7,
            'Mobile',
            null,
            ['*'],
            fn(string $column, string $fallback = 'id'): string => $column !== '' ? $column : $fallback,
            fn(string $table): string => $table
        );
    }

    // ─── Default: unlimited ──────────────────────────────────────────

    public function testUnlimitedByDefault(): void
    {
        $service = $this->service([], existing: 25);

        self::assertSame('99|plain-token-value', $this->issue($service));
        self::assertSame([], $service->deleted, 'A zero limit must never revoke anything.');
    }

    public function testAnExplicitZeroIsAlsoUnlimited(): void
    {
        $service = $this->service(['max_active_per_user' => 0], existing: 10);

        $this->issue($service);

        self::assertSame([], $service->deleted);
    }

    // ─── Single device ───────────────────────────────────────────────

    public function testSingleDeviceRetiresThePreviousToken(): void
    {
        $service = $this->service(['max_active_per_user' => 1], existing: 1);

        self::assertNotNull($this->issue($service));
        self::assertSame([1], $service->deleted, 'Signing in on a new phone must sign the old one out.');
        self::assertCount(1, $service->inserted);
    }

    public function testTheFirstLoginOnASingleDeviceAccountRevokesNothing(): void
    {
        $service = $this->service(['max_active_per_user' => 1], existing: 0);

        self::assertNotNull($this->issue($service));
        self::assertSame([], $service->deleted);
    }

    // ─── N devices ───────────────────────────────────────────────────

    public function testBelowTheCapNothingIsRevoked(): void
    {
        $service = $this->service(['max_active_per_user' => 3], existing: 2);

        $this->issue($service);

        self::assertSame([], $service->deleted);
    }

    public function testAtTheCapTheLeastRecentlyUsedTokenIsRetired(): void
    {
        $service = $this->service(['max_active_per_user' => 3], existing: 3);

        $this->issue($service);

        self::assertSame([1], $service->deleted, 'Only one slot needs freeing.');
    }

    public function testAnAccountOverTheCapIsBroughtBackDownInOneGo(): void
    {
        // Lowering the configured limit leaves existing accounts over it.
        $service = $this->service(['max_active_per_user' => 2], existing: 5);

        $this->issue($service);

        self::assertSame([1, 2, 3, 4], $service->deleted, 'Four retired leaves one slot for the new token.');
    }

    /**
     * Oldest means least recently *used*, not first created: the device someone
     * actually stopped using is the one to retire.
     */
    public function testTheOrderComesFromTheQueryNotFromCreation(): void
    {
        $service = $this->service(['max_active_per_user' => 1]);
        $service->existingTokens = [
            ['id' => 42, 'last_used_at' => '2026-01-01', 'created_at' => '2026-06-01'],
            ['id' => 7, 'last_used_at' => '2026-08-01', 'created_at' => '2026-01-01'],
        ];

        $this->issue($service);

        self::assertSame([42, 7], $service->deleted);
    }

    // ─── Deny policy ─────────────────────────────────────────────────

    public function testDenyRefusesTheNewLogin(): void
    {
        $service = $this->service(['max_active_per_user' => 1, 'on_limit' => 'deny'], existing: 1);

        try {
            $this->issue($service);
            self::fail('The login should have been refused.');
        } catch (RuntimeException $e) {
            self::assertSame('token_device_limit_reached', $e->getMessage());
        }

        self::assertSame([], $service->deleted, 'Deny must not revoke the existing device.');
        self::assertSame([], $service->inserted, 'Deny must not issue a token.');
    }

    public function testDenyStillAllowsTheFirstLogin(): void
    {
        $service = $this->service(['max_active_per_user' => 1, 'on_limit' => 'deny'], existing: 0);

        self::assertNotNull($this->issue($service));
    }

    /** @return list<array{0:string}> */
    public static function revokePolicyProvider(): array
    {
        return [['revoke_oldest'], ['REVOKE_OLDEST'], ['anything-else'], ['']];
    }

    #[DataProvider('revokePolicyProvider')]
    public function testAnythingButDenyMeansRevoke(string $policy): void
    {
        $service = $this->service(['max_active_per_user' => 1, 'on_limit' => $policy], existing: 1);

        self::assertNotNull($this->issue($service), 'Only an explicit "deny" may block a login.');
        self::assertSame([1], $service->deleted);
    }

    public function testDenyIsCaseInsensitive(): void
    {
        $service = $this->service(['max_active_per_user' => 1, 'on_limit' => 'DENY'], existing: 1);

        $this->expectException(RuntimeException::class);
        $this->issue($service);
    }
}
