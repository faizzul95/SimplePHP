<?php

declare(strict_types=1);

use Components\Maintenance;
use PHPUnit\Framework\TestCase;

final class MaintenanceCompatibilityTest extends TestCase
{
    private string $downFile;

    protected function setUp(): void
    {
        parent::setUp();

        $frameworkDir = ROOT_DIR . 'storage/framework';
        if (!is_dir($frameworkDir)) {
            mkdir($frameworkDir, 0777, true);
        }

        $this->downFile = ROOT_DIR . 'storage/framework/down';
        if (is_file($this->downFile)) {
            unlink($this->downFile);
        }

        $_SERVER = [
            'REQUEST_URI' => '/secret-bypass',
            'HTTP_HOST' => 'localhost',
        ];
        $_COOKIE = [];
        putenv('APP_KEY=test-app-key');
    }

    protected function tearDown(): void
    {
        if (is_file($this->downFile)) {
            unlink($this->downFile);
        }

        parent::tearDown();
    }

    public function testMaintenanceLoadsDownPayloadFromFrameworkStorage(): void
    {
        file_put_contents($this->downFile, json_encode([
            'message' => 'Planned maintenance',
            'retry' => 120,
            'secret' => 'secret-bypass',
        ], JSON_THROW_ON_ERROR));

        $maintenance = new Maintenance([
            'secret' => '',
            'bypass_cookie' => ['name' => 'myth_maintenance', 'ttl' => 60, 'same_site' => 'Lax'],
        ]);

        self::assertTrue($maintenance->active());
        self::assertSame('Planned maintenance', $maintenance->payload()['message']);
        self::assertSame(120, $maintenance->payload()['retry']);
    }

    public function testMaintenanceBypassCookieValidationUsesDownPayloadSecret(): void
    {
        file_put_contents($this->downFile, json_encode([
            'secret' => 'secret-bypass',
        ], JSON_THROW_ON_ERROR));

        $maintenance = new Maintenance([
            'secret' => '',
            'bypass_cookie' => ['name' => 'myth_maintenance', 'ttl' => 60, 'same_site' => 'Lax'],
        ]);

        $cookieValueMethod = new ReflectionMethod(Maintenance::class, 'bypassCookieValue');
        $cookieValueMethod->setAccessible(true);
        $_COOKIE['myth_maintenance'] = $cookieValueMethod->invoke($maintenance, 'secret-bypass');

        $hasValidBypassCookie = new ReflectionMethod(Maintenance::class, 'hasValidBypassCookie');
        $hasValidBypassCookie->setAccessible(true);

        self::assertTrue($hasValidBypassCookie->invoke($maintenance, ['secret' => 'secret-bypass']));
    }
}