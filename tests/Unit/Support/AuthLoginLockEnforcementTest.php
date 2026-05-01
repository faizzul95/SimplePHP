<?php

declare(strict_types=1);

use Components\Auth;
use Core\Http\Request;
use PHPUnit\Framework\TestCase;

final class AuthLoginLockEnforcementProbe extends Auth
{
    public array $attemptBuckets = [];
    public array $cleanupCalls = [];
    public string $resolvedIpAddress = '127.0.0.1';

    public function exposeCanAttemptWithLoginPolicy(array $credentials): bool
    {
        return $this->canAttemptWithLoginPolicy($credentials);
    }

    public function exposeClearLoginFailures(array $credentials, ?int $userId = null): void
    {
        $this->clearLoginFailures($credentials, $userId);
    }

    protected function recentLoginAttemptBuckets(array $credentials, int $windowSeconds): array
    {
        return $this->attemptBuckets;
    }

    protected function tableAndColumnsExist(string $table, array $columns = []): bool
    {
        return true;
    }

    protected function deleteLoginFailureRecords(string $table, array $attemptColumns, ?int $userId, ?string $identifier, ?string $ipAddress): void
    {
        $this->cleanupCalls[] = [
            'table' => $table,
            'columns' => $attemptColumns,
            'user_id' => $userId,
            'identifier' => $identifier,
            'ip_address' => $ipAddress,
        ];
    }

    protected function clientIpAddress(): string
    {
        return $this->resolvedIpAddress;
    }
}

final class AuthLoginLockEnforcementTest extends TestCase
{
    public function testDeniesAttemptWhenIdentifierBucketIsLockedEvenIfIpBucketIsNot(): void
    {
        $auth = new AuthLoginLockEnforcementProbe([
            'systems_login_policy' => [
                'enabled' => true,
                'max_attempts' => 3,
                'decay_seconds' => 300,
                'lockout_seconds' => 600,
            ],
        ]);

        $now = time();
        $auth->attemptBuckets = [
            'identifier' => [$now - 30, $now - 20, $now - 10],
            'ip' => [$now - 1200],
        ];

        self::assertFalse($auth->exposeCanAttemptWithLoginPolicy(['email' => 'admin@example.com']));

        $status = $auth->lastAttemptStatus();
        self::assertSame('login_locked', $status['reason']);
        self::assertSame(423, $status['http_code']);
        self::assertSame('identifier', $status['context']['scope']);
        self::assertContains('identifier', $status['context']['scopes']);
    }

    public function testDeniesAttemptWhenIpBucketIsLockedEvenIfIdentifierBucketIsNot(): void
    {
        $auth = new AuthLoginLockEnforcementProbe([
            'systems_login_policy' => [
                'enabled' => true,
                'max_attempts' => 3,
                'decay_seconds' => 300,
                'lockout_seconds' => 600,
            ],
        ]);

        $now = time();
        $auth->attemptBuckets = [
            'identifier' => [$now - 1200],
            'ip' => [$now - 25, $now - 15, $now - 5],
        ];

        self::assertFalse($auth->exposeCanAttemptWithLoginPolicy(['username' => 'admin']));

        $status = $auth->lastAttemptStatus();
        self::assertSame('login_locked', $status['reason']);
        self::assertSame('ip', $status['context']['scope']);
        self::assertContains('ip', $status['context']['scopes']);
    }

    public function testLockContextUsesLongestRetryingScopeWhenMultipleScopesAreLocked(): void
    {
        $auth = new AuthLoginLockEnforcementProbe([
            'systems_login_policy' => [
                'enabled' => true,
                'max_attempts' => 3,
                'decay_seconds' => 300,
                'lockout_seconds' => 600,
            ],
        ]);

        $now = time();
        $auth->attemptBuckets = [
            'identifier' => [$now - 80, $now - 70, $now - 60],
            'ip' => [$now - 25, $now - 15, $now - 5],
        ];

        self::assertFalse($auth->exposeCanAttemptWithLoginPolicy(['email' => 'admin@example.com']));

        $status = $auth->lastAttemptStatus();
        self::assertSame('ip', $status['context']['scope']);
        self::assertContains('identifier', $status['context']['scopes']);
        self::assertContains('ip', $status['context']['scopes']);
        self::assertGreaterThan(0, $status['context']['retry_after']);
    }

    public function testClearLoginFailuresClearsBothIdentifierAndIpBucketsWhenBothTrackingModesAreEnabled(): void
    {
        $auth = new AuthLoginLockEnforcementProbe([
            'systems_login_policy' => [
                'enabled' => true,
                'track_by_identifier' => true,
                'track_by_ip' => true,
            ],
        ]);
        $auth->resolvedIpAddress = '203.0.113.44';

        $auth->exposeClearLoginFailures(['email' => 'admin@example.com'], 77);

        self::assertCount(1, $auth->cleanupCalls);
        self::assertSame('email:admin@example.com', $auth->cleanupCalls[0]['identifier']);
        self::assertSame('203.0.113.44', $auth->cleanupCalls[0]['ip_address']);
        self::assertSame(77, $auth->cleanupCalls[0]['user_id']);
    }

    public function testClearLoginFailuresLeavesIdentifierEmptyWhenCredentialIdentifierCannotBeDerived(): void
    {
        $auth = new AuthLoginLockEnforcementProbe([
            'systems_login_policy' => [
                'enabled' => true,
                'track_by_identifier' => true,
                'track_by_ip' => true,
            ],
        ]);
        $auth->resolvedIpAddress = '203.0.113.45';

        $auth->exposeClearLoginFailures(['remember' => '1'], null);

        self::assertCount(1, $auth->cleanupCalls);
        self::assertNull($auth->cleanupCalls[0]['identifier']);
        self::assertSame('203.0.113.45', $auth->cleanupCalls[0]['ip_address']);
    }
}