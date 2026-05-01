<?php

declare(strict_types=1);

use App\Support\Auth\LoginPolicy;
use PHPUnit\Framework\TestCase;

final class LoginPolicyTest extends TestCase
{
    public function testIdentifierPrefersConfiguredCredentialFields(): void
    {
        $policy = new LoginPolicy([
            'systems_login_policy' => [
                'identifier_fields' => ['username', 'email'],
            ],
        ]);

        self::assertSame('username:admin', $policy->identifier(['username' => 'Admin', 'email' => 'admin@example.com']));
    }

    public function testDenyLockedAttemptStoresRetryContext(): void
    {
        $policy = new LoginPolicy();

        self::assertFalse($policy->denyLockedAttempt([
            'locked_until_ts' => strtotime('+5 minutes'),
            'retry_after' => 300,
        ]));

        self::assertSame('login_locked', $policy->lastAttemptStatus()['reason']);
        self::assertSame(423, $policy->lastAttemptStatus()['http_code']);
        self::assertSame(300, $policy->lastAttemptStatus()['context']['retry_after']);
    }

    public function testPasswordRotationPolicyRequiresChangedAtWhenConfigured(): void
    {
        $policy = new LoginPolicy([
            'users_table' => 'users',
            'user_columns' => [
                'password_changed_at' => 'password_changed_at',
                'force_password_change' => 'force_password_change',
            ],
            'systems_login_policy' => [
                'password_rotation' => [
                    'enabled' => true,
                    'require_password_changed_at' => true,
                    'max_age_days' => 90,
                ],
            ],
        ]);

        $allowed = $policy->passesPasswordRotationPolicy(
            ['id' => 1],
            fn(string $table): string => $table,
            fn(string $column, string $fallback = 'id'): string => $column !== '' ? $column : $fallback,
            fn(string $table, array $columns): bool => in_array('password_changed_at', $columns, true)
        );

        self::assertFalse($allowed);
        self::assertSame('password_change_required', $policy->lastAttemptStatus()['reason']);
    }
}