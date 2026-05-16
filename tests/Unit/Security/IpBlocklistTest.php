<?php

declare(strict_types=1);

use Core\Http\Request;
use Core\Security\AuditLogger;
use Core\Security\IpBlocklist;
use PHPUnit\Framework\TestCase;

final class IpBlocklistTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        bootstrapTestFrameworkServices([
            'cache' => [
                'default' => 'array',
                'stores' => [
                    'array' => ['driver' => 'array'],
                ],
                'prefix' => 'test_',
            ],
        ]);

        cache()->flush();
        $_SERVER = [
            'REMOTE_ADDR' => '203.0.113.15',
        ];
        $GLOBALS['config']['security']['trusted'] = ['proxies' => []];
        $GLOBALS['config']['security']['trusted_proxies'] = [];
    }

    public function testDecisionForIpMatchesStaticIpAndCidrRules(): void
    {
        $service = new IpBlocklist([
            'enabled' => true,
            'ips' => ['203.0.113.15'],
            'cidrs' => ['198.51.100.0/24'],
        ]);

        self::assertSame('static-ip', $service->decisionForIp('203.0.113.15')['source']);
        self::assertSame('static-cidr', $service->decisionForIp('198.51.100.22')['source']);
        self::assertNull($service->decisionForIp('192.0.2.1'));
    }

    public function testResolveClientIpHonoursTrustedProxyRanges(): void
    {
        $GLOBALS['config']['security']['trusted']['proxies'] = ['10.0.0.0/24'];
        $GLOBALS['config']['security']['trusted_proxies'] = ['10.0.0.0/24'];
        $request = new Request([], [], [
            'REMOTE_ADDR' => '10.0.0.5',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.20, 10.0.0.5',
        ], []);

        $service = new IpBlocklist(['enabled' => true]);

        self::assertSame('198.51.100.20', $service->resolveClientIp($request));
    }

    public function testObserveAuditEventAutoAddsBlockWhenThresholdIsReached(): void
    {
        $service = new class extends IpBlocklist {
            public array $added = [];

            public function add(string $ip, string $reason, ?string $expiresAt = null, bool $autoAdded = false): bool
            {
                $this->added[] = compact('ip', 'reason', 'expiresAt', 'autoAdded');
                return true;
            }
        };

        $service->observeAuditEvent(AuditLogger::E_BRUTE_FORCE, '203.0.113.15');
        $service->observeAuditEvent(AuditLogger::E_BRUTE_FORCE, '203.0.113.15');
        self::assertCount(0, $service->added);

        $service->observeAuditEvent(AuditLogger::E_BRUTE_FORCE, '203.0.113.15');

        self::assertCount(1, $service->added);
        self::assertSame('203.0.113.15', $service->added[0]['ip']);
        self::assertTrue($service->added[0]['autoAdded']);
    }
}