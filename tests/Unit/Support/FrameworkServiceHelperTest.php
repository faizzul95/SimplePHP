<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class FrameworkServiceHelperTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        bootstrapTestFrameworkServices([
            'auth' => [
                'methods' => ['session'],
            ],
            'framework' => [
                'view_path' => 'app/views',
                'view_cache_path' => 'storage/cache/views',
                'maintenance' => [],
            ],
            'features' => [
                'flags' => [
                    'runtime_service_registry' => true,
                ],
            ],
        ]);
    }

    public function testManagedHelpersReturnSameInstancesAcrossCalls(): void
    {
        self::assertSame(auth(), auth());
        self::assertSame(files(), files());
        self::assertSame(storage(), storage());
        self::assertSame(security(), security());
        self::assertSame(response(), response());
        self::assertSame(blade_engine(), blade_engine());
        self::assertSame(maintenance(), maintenance());
        self::assertSame(feature(), feature());
        self::assertSame(logger(), logger());
    }

    public function testFeatureValueHelperReturnsConfiguredFlagValue(): void
    {
        self::assertSame(true, feature_value('runtime_service_registry', false));
    }

    public function testResetFrameworkServiceDropsManagedInstance(): void
    {
        $first = auth();

        reset_framework_service('auth');
        bootstrapRegisterServiceProviders($GLOBALS['config'], ['runtime' => 'cli']);

        $second = auth();

        self::assertNotSame($first, $second);
    }
}