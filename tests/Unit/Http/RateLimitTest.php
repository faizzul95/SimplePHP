<?php

declare(strict_types=1);

use App\Http\Middleware\RateLimit;
use PHPUnit\Framework\TestCase;

final class RateLimitTest extends TestCase
{
    public function testFileFallbackPersistsAtomicIncrementState(): void
    {
        $middleware = new RateLimit();
        $path = ROOT_DIR . 'storage/cache/rate_limit/phpunit-rate-limit.json';
        $directory = dirname($path);

        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        if (is_file($path)) {
            unlink($path);
        }

        file_put_contents($path, json_encode(['attempts' => 4, 'reset_at' => time() + 60]));

        $method = new ReflectionMethod(RateLimit::class, 'incrementFileStateAtomically');
        $method->setAccessible(true);

        $first = $method->invoke($middleware, $path, time());
        $second = $method->invoke($middleware, $path, time());

        self::assertSame(5, $first['attempts']);
        self::assertSame(6, $second['attempts']);

        $saved = json_decode((string) file_get_contents($path), true);
        self::assertSame(6, $saved['attempts']);

        unlink($path);
    }
}