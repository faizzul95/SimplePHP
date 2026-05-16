<?php

declare(strict_types=1);

use Core\Console\Kernel;
use Core\Http\Request;
use Core\Http\ResponseCache;
use PHPUnit\Framework\TestCase;

final class ResponseCacheClearCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('ENVIRONMENT')) {
            define('ENVIRONMENT', 'testing');
        }

        bootstrapTestFrameworkServices([
            'cache' => [
                'default' => 'array',
                'stores' => [
                    'array' => ['driver' => 'array'],
                ],
                'prefix' => 'test_',
            ],
        ]);

        cache()->flush();
    }

    public function testCommandClearsEntriesByTag(): void
    {
        $cache = new ResponseCache();
        $request = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/products'], []);
        $cache->put($request, ['ttl' => 300, 'scope' => 'public', 'tags' => ['products']], [
            'status' => 200,
            'headers' => [],
            'body' => 'cached',
        ]);

        $kernel = new Kernel();
        $exitCode = $kernel->callSilently('cache:response:clear', [
            'tag' => 'products',
        ]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Response cache cleared for tag: products', $kernel->output());
        self::assertNull($cache->get($request, ['ttl' => 300, 'scope' => 'public', 'tags' => ['products']]));
    }
}