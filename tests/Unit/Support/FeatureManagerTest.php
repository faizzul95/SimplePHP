<?php

declare(strict_types=1);

use Components\FeatureManager;
use PHPUnit\Framework\TestCase;

final class FeatureManagerTest extends TestCase
{
    public function testBooleanFlagsResolveDirectly(): void
    {
        $manager = new FeatureManager([
            'exports.async' => true,
            'beta.audit' => false,
        ]);

        self::assertTrue($manager->enabled('exports.async'));
        self::assertTrue($manager->disabled('beta.audit'));
    }

    public function testEnvironmentRestrictedFlagsOnlyEnableInAllowedEnvironment(): void
    {
        $manager = new FeatureManager([
            'rbac.role-maintenance' => [
                'enabled' => true,
                'environments' => ['development', 'staging'],
            ],
        ]);

        self::assertTrue($manager->enabled('rbac.role-maintenance', false, ['environment' => 'development']));
        self::assertFalse($manager->enabled('rbac.role-maintenance', false, ['environment' => 'production']));
    }

    public function testActorRestrictedFlagsFailClosedWhenActorDoesNotMatch(): void
    {
        $manager = new FeatureManager([
            'maintenance.bypass' => [
                'enabled' => true,
                'actors' => [10, 20],
            ],
        ]);

        self::assertTrue($manager->enabled('maintenance.bypass', false, ['actor' => 10]));
        self::assertFalse($manager->enabled('maintenance.bypass', false, ['actor' => 99]));
    }

    public function testPercentageFlagsHandleZeroAndFullRollouts(): void
    {
        $manager = new FeatureManager([
            'exports.zero' => ['enabled' => true, 'percentage' => 0],
            'exports.full' => ['enabled' => true, 'percentage' => 100],
        ]);

        self::assertFalse($manager->enabled('exports.zero', false, ['actor' => 123]));
        self::assertTrue($manager->enabled('exports.full', false, ['actor' => 123]));
    }

    public function testOverridesTakePriorityOverConfiguredDefinition(): void
    {
        $manager = new FeatureManager([
            'exports.async' => false,
        ]);

        $manager->override('exports.async', true);
        self::assertTrue($manager->enabled('exports.async'));

        $manager->clearOverride('exports.async');
        self::assertFalse($manager->enabled('exports.async'));
    }

    public function testRoleAndAbilityRestrictedFlagsSupportAuthLikeContext(): void
    {
        $manager = new FeatureManager([
            'rbac.bulk-assign' => [
                'enabled' => true,
                'roles' => ['admin'],
                'abilities' => ['users.assign'],
            ],
        ]);

        self::assertTrue($manager->enabled('rbac.bulk-assign', false, [
            'user' => [
                'id' => 10,
                'roles' => ['admin'],
                'abilities' => ['users.assign', 'users.read'],
            ],
        ]));

        self::assertFalse($manager->enabled('rbac.bulk-assign', false, [
            'user' => [
                'id' => 11,
                'roles' => ['staff'],
                'abilities' => ['users.read'],
            ],
        ]));
    }

    public function testDateWindowAndVariantValueCanBeResolved(): void
    {
        $manager = new FeatureManager([
            'exports.driver' => [
                'enabled' => true,
                'value' => 'queue',
                'starts_at' => '2025-01-01 00:00:00',
                'ends_at' => '2025-12-31 23:59:59',
            ],
        ]);

        self::assertTrue($manager->enabled('exports.driver', false, ['now' => '2025-07-01 12:00:00']));
        self::assertSame('queue', $manager->value('exports.driver', 'sync', ['now' => '2025-07-01 12:00:00']));
        self::assertFalse($manager->enabled('exports.driver', false, ['now' => '2026-01-01 00:00:00']));
        self::assertSame('sync', $manager->value('exports.driver', 'sync', ['now' => '2026-01-01 00:00:00']));
    }
}