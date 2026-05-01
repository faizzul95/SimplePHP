<?php

declare(strict_types=1);

use Components\Auth;
use Components\Logger;
use PHPUnit\Framework\TestCase;

final class AuthAuditLoggingProbe extends Auth
{
    public array $auditEntries = [];

    protected function dispatchAuthAuditLog(string $message, array $context, string $level): void
    {
        $this->auditEntries[] = [
            'message' => $message,
            'context' => $context,
            'level' => $level,
        ];
    }
}

final class AuthAuditLoggingTest extends TestCase
{
    public function testAttemptLogsStructuredFailureAuditWithoutPasswordLeakage(): void
    {
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit/Failure';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.10';

        $auth = new AuthAuditLoggingProbe([
            'systems_login_policy' => [
                'audit_logging' => [
                    'enabled' => true,
                    'level' => 'DEBUG',
                    'include_user_agent' => true,
                ],
            ],
        ]);

        self::assertFalse($auth->attempt([
            'email' => 'admin@example.com',
            'password' => '',
        ]));

        self::assertCount(1, $auth->auditEntries);
        self::assertSame('[AuthAudit] auth.login.failed', $auth->auditEntries[0]['message']);
        self::assertSame(Logger::LOG_LEVEL_DEBUG, $auth->auditEntries[0]['level']);
        self::assertSame('invalid_credentials', $auth->auditEntries[0]['context']['reason']);
        self::assertSame('email:admin@example.com', $auth->auditEntries[0]['context']['identifier']);
        self::assertSame('127.0.0.10', $auth->auditEntries[0]['context']['ip_address']);
        self::assertSame('PHPUnit/Failure', $auth->auditEntries[0]['context']['user_agent']);
        self::assertArrayNotHasKey('password', $auth->auditEntries[0]['context']);
    }

    public function testRecordLoginHistoryLogsSuccessAuditEvenWhenDbHistoryIsDisabled(): void
    {
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit/Success';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.11';

        $auth = new AuthAuditLoggingProbe([
            'systems_login_policy' => [
                'record_history' => false,
                'audit_logging' => [
                    'enabled' => true,
                    'include_user_agent' => false,
                ],
            ],
        ]);

        $auth->recordLoginHistory(42, 1);

        self::assertCount(1, $auth->auditEntries);
        self::assertSame('[AuthAudit] auth.login.success', $auth->auditEntries[0]['message']);
        self::assertSame('auth.login.success', $auth->auditEntries[0]['context']['event']);
        self::assertSame(42, $auth->auditEntries[0]['context']['user_id']);
        self::assertSame(1, $auth->auditEntries[0]['context']['login_type']);
        self::assertSame('session', $auth->auditEntries[0]['context']['auth_method']);
        self::assertArrayNotHasKey('user_agent', $auth->auditEntries[0]['context']);
    }

    public function testAuditLoggingCanBeDisabled(): void
    {
        $auth = new AuthAuditLoggingProbe([
            'systems_login_policy' => [
                'audit_logging' => [
                    'enabled' => false,
                ],
            ],
        ]);

        self::assertFalse($auth->attempt([
            'email' => 'admin@example.com',
            'password' => '',
        ]));

        self::assertSame([], $auth->auditEntries);
    }
}