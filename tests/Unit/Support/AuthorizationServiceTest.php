<?php

declare(strict_types=1);

use App\Support\Auth\AuthorizationService;
use PHPUnit\Framework\TestCase;

final class AuthorizationServiceTest extends TestCase
{
    public function testHasAbilityAllowsWildcardRequestAbilities(): void
    {
        $service = new AuthorizationService();

        self::assertTrue($service->hasAbility('reports.view', fn(): array => ['*']));
    }

    public function testPermissionsMergeNormalizedRequestAbilitiesForCurrentUser(): void
    {
        $service = new AuthorizationService([
            'rbac' => ['enabled' => false],
        ]);

        $permissions = $service->permissions(
            null,
            true,
            fn(?int $userId = null): int => 9,
            fn(int $userId, bool $includeRequestAbilities = false): string => $userId . ':' . ($includeRequestAbilities ? '1' : '0'),
            fn(string $column, string $fallback = 'id'): string => $column !== '' ? $column : $fallback,
            fn(string $table): string => $table,
            fn(array $items): array => array_values(array_unique(array_map(static fn($item) => strtolower(trim((string) $item)), $items))),
            fn(): array => [' Reports.View ', 'reports.view', 'reports.export']
        );

        self::assertSame(['reports.view', 'reports.export'], $permissions);
    }

    public function testPermissionsAreCachedUntilInvalidated(): void
    {
        $service = new AuthorizationService([
            'rbac' => ['enabled' => false],
        ]);
        $abilityCalls = 0;

        $resolver = fn(?int $userId = null): int => 21;
        $cacheKey = fn(int $userId, bool $includeRequestAbilities = false): string => $userId . ':' . ($includeRequestAbilities ? '1' : '0');
        $safeColumn = fn(string $column, string $fallback = 'id'): string => $column !== '' ? $column : $fallback;
        $safeTable = fn(string $table): string => $table;
        $normalize = fn(array $items): array => array_values(array_unique($items));
        $abilities = function () use (&$abilityCalls): array {
            $abilityCalls++;
            return ['audit.view'];
        };

        $first = $service->permissions(null, true, $resolver, $cacheKey, $safeColumn, $safeTable, $normalize, $abilities);
        $second = $service->permissions(null, true, $resolver, $cacheKey, $safeColumn, $safeTable, $normalize, $abilities);
        $service->invalidate(21);
        $third = $service->permissions(null, true, $resolver, $cacheKey, $safeColumn, $safeTable, $normalize, $abilities);

        self::assertSame(['audit.view'], $first);
        self::assertSame($first, $second);
        self::assertSame($first, $third);
        self::assertSame(2, $abilityCalls);
    }

    public function testHasPermissionUsesWildcardPermissionSet(): void
    {
        $service = new AuthorizationService([
            'rbac' => ['enabled' => false],
        ]);

        $allowed = $service->hasPermission(
            'system.manage',
            null,
            fn(?int $userId = null): int => 9,
            fn(int $userId, bool $includeRequestAbilities = false): string => $userId . ':' . ($includeRequestAbilities ? '1' : '0'),
            fn(string $column, string $fallback = 'id'): string => $column !== '' ? $column : $fallback,
            fn(string $table): string => $table,
            fn(array $items): array => array_values(array_unique($items)),
            fn(): array => ['*']
        );

        self::assertTrue($allowed);
    }
}