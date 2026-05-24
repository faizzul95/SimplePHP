<?php

declare(strict_types=1);

use Components\CSRF;
use PHPUnit\Framework\TestCase;

final class CsrfForceValidationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $_COOKIE = [];
        $_POST = [];
        $_SERVER = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/api/v1/profile/update',
            'SCRIPT_NAME' => '/index.php',
            'HTTP_HOST' => 'localhost',
        ];
    }

    public function testExcludedApiUriSkipsCsrfByDefault(): void
    {
        $csrf = new CSRF([
            'csrf_exclude_uris' => ['api/*'],
            'csrf_allow_missing_origin' => true,
        ]);

        self::assertTrue($csrf->validate('api/v1/profile/update'));
    }

    public function testForceModeValidatesExcludedApiUri(): void
    {
        $csrf = new CSRF([
            'csrf_exclude_uris' => ['api/*'],
            'csrf_allow_missing_origin' => true,
        ]);

        self::assertFalse($csrf->validate('api/v1/profile/update', true));
    }
}