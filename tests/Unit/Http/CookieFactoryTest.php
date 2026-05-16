<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use Core\Http\CookieFactory;
use PHPUnit\Framework\TestCase;

class CookieFactoryTest extends TestCase
{
    public function testBuildHeaderSupportsPartitionedCookies(): void
    {
        $header = CookieFactory::buildHeader('session', 'abc123', 300, '/', '', true, true, 'None', true);

        self::assertStringContainsString('Set-Cookie: session=abc123', $header);
        self::assertStringContainsString('Secure', $header);
        self::assertStringContainsString('HttpOnly', $header);
        self::assertStringContainsString('SameSite=None', $header);
        self::assertStringContainsString('Partitioned', $header);
    }

    public function testBuildHeaderRejectsInvalidPartitionedCombination(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CookieFactory::buildHeader('session', 'abc123', 300, '/', '', true, true, 'Lax', true);
    }

    public function testApplyPrefixUsesHostPrefixWhenEligible(): void
    {
        self::assertSame('__Host-myth_session', CookieFactory::applyPrefix('myth_session', true, '/', '', true));
        self::assertSame('__Secure-myth_session', CookieFactory::applyPrefix('myth_session', true, '/app', '', true));
    }
}