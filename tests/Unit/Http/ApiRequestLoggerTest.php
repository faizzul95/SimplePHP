<?php

declare(strict_types=1);

use App\Http\Middleware\ApiRequestLogger;
use PHPUnit\Framework\TestCase;

final class ApiRequestLoggerTest extends TestCase
{
    public function testMaskSensitiveFieldsUsesSubstringMatching(): void
    {
        $logger = new ApiRequestLogger();
        $method = new ReflectionMethod(ApiRequestLogger::class, 'maskSensitiveFields');
        $method->setAccessible(true);

        $masked = $method->invoke($logger, [
            'password' => 'secret',
            'authorization' => 'Bearer abc',
            'current_password' => 'old',
            'refresh_token' => 'refresh',
            'profile' => [
                'private_key' => 'pem',
                'name' => 'safe',
            ],
        ]);

        self::assertSame('***', $masked['password']);
        self::assertSame('***', $masked['authorization']);
        self::assertSame('***', $masked['current_password']);
        self::assertSame('***', $masked['refresh_token']);
        self::assertSame('***', $masked['profile']['private_key']);
        self::assertSame('safe', $masked['profile']['name']);
    }

    public function testEncodeParamsForLogTruncatesLargePayloads(): void
    {
        $logger = new ApiRequestLogger();
        $method = new ReflectionMethod(ApiRequestLogger::class, 'encodeParamsForLog');
        $method->setAccessible(true);

        $encoded = $method->invoke($logger, [
            'payload' => str_repeat('a', 5000),
        ]);

        self::assertStringContainsString('(truncated)', $encoded);
        self::assertLessThanOrEqual(2062, strlen($encoded));
    }
}