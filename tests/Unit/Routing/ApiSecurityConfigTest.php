<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ApiSecurityConfigTest extends TestCase
{
    private function frameworkConfig(): array
    {
        $config = [];
        require __DIR__ . '/../../../app/config/framework.php';

        return (array) ($config['framework'] ?? []);
    }

    public function testApiMiddlewareGroupsEnforceSeparateWebAndMobileSecurityProfiles(): void
    {
        $framework = $this->frameworkConfig();
        $groups = (array) ($framework['middleware_groups'] ?? []);

        self::assertArrayHasKey('api.public.submit', $groups);
        self::assertContains('api', (array) $groups['api.public.submit']);
        self::assertContains('throttle:auth', (array) $groups['api.public.submit']);

        self::assertArrayHasKey('api.external.auth', $groups);
        self::assertContains('api', (array) $groups['api.external.auth']);
        self::assertContains('auth.api', (array) $groups['api.external.auth']);

        self::assertArrayHasKey('api.app', $groups);
        self::assertContains('api', (array) $groups['api.app']);
        self::assertContains('origin.policy:strict', (array) $groups['api.app']);
        self::assertContains('session.stateful:force', (array) $groups['api.app']);
        self::assertContains('auth.web', (array) $groups['api.app']);
        self::assertContains('csrf:force', (array) $groups['api.app']);
        self::assertNotContains('auth', (array) $groups['api.app']);
    }

    public function testOriginPolicyCanBeOverriddenForStrictBrowserApiProtection(): void
    {
        $framework = $this->frameworkConfig();
        $overrides = (array) ($framework['middleware_override_aliases'] ?? []);

        self::assertContains('origin.policy', $overrides);
    }

    public function testApiRuntimeRegistersFilesystemProviderForUploadEndpoints(): void
    {
        $framework = $this->frameworkConfig();
        $providers = (array) (($framework['providers'] ?? [])['api'] ?? []);

        self::assertContains(\App\Providers\FilesystemServiceProvider::class, $providers);
    }

    public function testThrottleRunsBeforePayloadInspectionInWebAndApiGroups(): void
    {
        $framework = $this->frameworkConfig();
        $groups = (array) ($framework['middleware_groups'] ?? []);

        $web = array_values((array) ($groups['web'] ?? []));
        $api = array_values((array) ($groups['api'] ?? []));

        self::assertLessThan(array_search('payload.limits', $web, true), array_search('throttle:web', $web, true));
        self::assertLessThan(array_search('payload.limits', $api, true), array_search('throttle:api', $api, true));
    }
}