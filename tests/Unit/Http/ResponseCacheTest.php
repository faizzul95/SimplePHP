<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use Core\Http\Request;
use Core\Http\ResponseCache;
use PHPUnit\Framework\TestCase;

final class ResponseCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

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

    public function testResponseCacheCanStoreAndRecallPayloadByRequest(): void
    {
        $cache = new ResponseCache();
        $request = new Request(['page' => '1'], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/products?page=1'], []);

        $cache->put($request, ['ttl' => 300, 'scope' => 'public', 'tags' => ['products']], [
            'status' => 200,
            'headers' => [['name' => 'Content-Type', 'value' => 'text/html; charset=UTF-8']],
            'body' => '<html>cached</html>',
        ]);

        $payload = $cache->get($request, ['ttl' => 300, 'scope' => 'public', 'tags' => ['products']]);

        self::assertIsArray($payload);
        self::assertSame('<html>cached</html>', $payload['body']);
        self::assertSame(200, $payload['status']);
    }

    public function testForgetByPathRemovesIndexedEntries(): void
    {
        $cache = new ResponseCache();
        $request = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/products'], []);
        $options = ['ttl' => 300, 'scope' => 'public', 'tags' => ['products']];

        $cache->put($request, $options, [
            'status' => 200,
            'headers' => [],
            'body' => 'cached-body',
        ]);

        self::assertSame(1, $cache->forget('/products'));
        self::assertNull($cache->get($request, $options));
    }

    public function testForgetByTagRemovesTaggedEntries(): void
    {
        $cache = new ResponseCache();
        $request = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/products'], []);
        $options = ['ttl' => 300, 'scope' => 'public', 'tags' => ['catalog']];

        $cache->put($request, $options, [
            'status' => 200,
            'headers' => [],
            'body' => 'cached-body',
        ]);

        self::assertSame(1, $cache->forgetByTag('catalog'));
        self::assertNull($cache->get($request, $options));
    }
}