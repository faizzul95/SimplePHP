<?php

declare(strict_types=1);

use App\Support\Auth\AuthMethodResolver;
use PHPUnit\Framework\TestCase;

final class AuthMethodResolverTest extends TestCase
{
    public function testNormalizeCollapsesAliasesAndFallsBackToDefault(): void
    {
        $resolver = new AuthMethodResolver();

        self::assertSame(['session', 'token', 'api_key'], $resolver->normalize(['web', 'api', 'apikey', 'unknown'], ['digest']));
        self::assertSame(['digest'], $resolver->normalize(['unknown'], ['digest']));
    }

    public function testResolveGuardMethodsUsesDefaultWebAndApiMappings(): void
    {
        $resolver = new AuthMethodResolver();

        self::assertSame(['session'], $resolver->resolveGuardMethods('web', ['session'], ['token', 'jwt']));
        self::assertSame(['token', 'jwt'], $resolver->resolveGuardMethods('api', ['session'], ['token', 'jwt']));
        self::assertSame(['digest'], $resolver->resolveGuardMethods('digest', ['session'], ['token']));
    }
}