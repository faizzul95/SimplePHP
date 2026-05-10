<?php

declare(strict_types=1);

use Core\Console\Kernel;
use PHPUnit\Framework\TestCase;

final class SecurityAuditCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('ENVIRONMENT')) {
            define('ENVIRONMENT', 'production');
        }

        bootstrapTestFrameworkServices([
            'security' => [
                'csrf' => [
                    'csrf_protection' => true,
                    'csrf_secure_cookie' => true,
                    'csrf_origin_check' => true,
                ],
                'request_hardening' => [
                    'enabled' => true,
                ],
                'trusted' => [
                    'hosts' => ['app.example.test'],
                ],
                'csp' => [
                    'enabled' => true,
                    'script-src' => ["'self'"],
                ],
                'headers' => [
                    'hsts' => ['enabled' => true],
                    'x_content_type_options' => 'nosniff',
                ],
                'permissions_policy' => [
                    'geolocation' => '()'
                ],
            ],
            'api' => [
                'cors' => [
                    'allow_origin' => ['https://app.example.test'],
                    'allow_credentials' => false,
                ],
                'auth' => [
                    'required' => true,
                    'methods' => ['token'],
                ],
                'rate_limit' => [
                    'enabled' => true,
                ],
            ],
        ]);
    }

    public function testSecurityAuditCommandPrintsSuccessfulJsonSummary(): void
    {
        $kernel = new Kernel();
        $exitCode = $kernel->callSilently('security:audit', [
            'format' => 'json',
        ]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('"summary"', $kernel->output());
        self::assertStringContainsString('"fail": 0', $kernel->output());
        self::assertStringContainsString('"target": null', $kernel->output());
    }

    public function testSecurityAuditCommandFailsFastWhenSessionAuthCredentialsAreMissing(): void
    {
        $kernel = new Kernel();
        $exitCode = $kernel->callSilently('security:audit', [
            'format' => 'json',
            'url' => 'https://app.example.test/dashboard',
            'auth' => 'session',
        ]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('"auth": "session"', $kernel->output());
        self::assertStringContainsString('auth.session.credentials', $kernel->output());
    }
}