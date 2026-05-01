<?php

declare(strict_types=1);

use Components\Auth;
use PHPUnit\Framework\TestCase;

final class AuthSessionLifecycleProbe extends Auth
{
    public ?array $resolvedUser = null;
    public ?array $configuredUserRecord = null;
    public array $registry = [];
    public bool $registryOk = true;
    public bool $mutateOk = true;
    public ?string $resolvedSessionId = null;
    public array $logoutCalls = [];

    public function user(array|string|null $methods = null): ?array
    {
        if ($methods === 'session' || $methods === ['session'] || $methods === null) {
            return $this->resolvedUser;
        }

        return null;
    }

    public function logout(bool $destroySession = false): void
    {
        $this->logoutCalls[] = $destroySession;
    }

    protected function readSessionRegistry(int $userId, ?bool &$ok = null): array
    {
        $ok = $this->registryOk;
        return $this->registry;
    }

    protected function mutateSessionRegistry(int $userId, callable $callback, ?bool &$ok = null): bool
    {
        $ok = $this->registryOk;
        if (!$this->mutateOk || !$this->registryOk) {
            return false;
        }

        $this->registry = $callback($this->registry);
        return true;
    }

    protected function currentSessionIdentifier(): string
    {
        return (string) $this->resolvedSessionId;
    }

    protected function findConfiguredUserRecord(int $userId, string $selectColumns): ?array
    {
        return $this->configuredUserRecord;
    }
}

final class AuthSessionLifecycleTest extends TestCase
{
    public function testSessionsReturnsNormalizedSortedEntries(): void
    {
        $auth = new AuthSessionLifecycleProbe();
        $auth->resolvedUser = ['id' => 44];
        $auth->resolvedSessionId = 'current-session';
        $auth->registry = [
            'older-session' => [
                'ip' => '10.0.0.5',
                'ua' => 'Firefox',
                'issued_at' => 100,
                'last_seen_at' => 150,
            ],
            'current-session' => [
                'ip' => '10.0.0.8',
                'ua' => 'Chrome',
                'issued_at' => 200,
                'last_seen_at' => 300,
            ],
        ];

        $sessions = $auth->sessions();

        self::assertCount(2, $sessions);
        self::assertSame('current-session', $sessions[0]['session_id']);
        self::assertTrue($sessions[0]['current']);
        self::assertSame('older-session', $sessions[1]['session_id']);
        self::assertFalse($sessions[1]['current']);
    }

    public function testRevokeSessionRemovesAnotherSessionWithoutLoggingOutCurrentOne(): void
    {
        $auth = new AuthSessionLifecycleProbe();
        $auth->resolvedUser = ['id' => 44];
        $auth->resolvedSessionId = 'current-session';
        $auth->registry = [
            'current-session' => ['last_seen_at' => 300],
            'other-session' => ['last_seen_at' => 200],
        ];

        $revoked = $auth->revokeSession('other-session');

        self::assertTrue($revoked);
        self::assertArrayHasKey('current-session', $auth->registry);
        self::assertArrayNotHasKey('other-session', $auth->registry);
        self::assertSame([], $auth->logoutCalls);
    }

    public function testRevokeSessionLogsOutWhenCurrentSessionIsRevoked(): void
    {
        $auth = new AuthSessionLifecycleProbe();
        $auth->resolvedUser = ['id' => 44];
        $auth->resolvedSessionId = 'current-session';
        $auth->registry = [
            'current-session' => ['last_seen_at' => 300],
        ];

        $revoked = $auth->revokeSession('current-session');

        self::assertTrue($revoked);
        self::assertSame([true], $auth->logoutCalls);
    }

    public function testLogoutOtherDevicesKeepsCurrentSessionOnlyWhenPasswordMatches(): void
    {
        $auth = new AuthSessionLifecycleProbe();
        $auth->resolvedUser = ['id' => 44];
        $auth->resolvedSessionId = 'current-session';
        $auth->configuredUserRecord = [
            'id' => 44,
            'password' => password_hash('secret-pass', PASSWORD_DEFAULT),
        ];
        $auth->registry = [
            'current-session' => ['last_seen_at' => 300],
            'other-session' => ['last_seen_at' => 200],
        ];

        $result = $auth->logoutOtherDevices('secret-pass');

        self::assertTrue($result);
        self::assertSame(['current-session'], array_keys($auth->registry));
    }

    public function testLogoutOtherDevicesRejectsInvalidPassword(): void
    {
        $auth = new AuthSessionLifecycleProbe();
        $auth->resolvedUser = ['id' => 44];
        $auth->resolvedSessionId = 'current-session';
        $auth->configuredUserRecord = [
            'id' => 44,
            'password' => password_hash('secret-pass', PASSWORD_DEFAULT),
        ];
        $auth->registry = [
            'current-session' => ['last_seen_at' => 300],
            'other-session' => ['last_seen_at' => 200],
        ];

        $result = $auth->logoutOtherDevices('wrong-pass');

        self::assertFalse($result);
        self::assertCount(2, $auth->registry);
    }
}