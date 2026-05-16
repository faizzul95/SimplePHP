<?php

declare(strict_types=1);

use Core\Session\RedisSessionHandler;
use PHPUnit\Framework\TestCase;

final class RedisSessionHandlerTest extends TestCase
{
    public function testReadReturnsEmptyStringWhenSessionDoesNotExist(): void
    {
        $handler = new RedisSessionHandler(['lifetime' => 120, 'redis' => ['prefix' => 'sess:']], new FakeRedisSessionClient());

        self::assertSame('', $handler->read('missing'));
        self::assertFalse($handler->validateId('missing'));
    }

    public function testWriteStoresPayloadWithTtlAndCanBeReadBack(): void
    {
        $redis = new FakeRedisSessionClient();
        $handler = new RedisSessionHandler([
            'lifetime' => 5,
            'redis' => ['prefix' => 'sess:', 'lock_wait_ms' => 0],
        ], $redis);

        self::assertTrue($handler->write('abc', 'payload-data'));
        self::assertSame('payload-data', $handler->read('abc'));
        self::assertSame(300, $redis->ttls['sess:data:abc'] ?? null);
    }

    public function testUpdateTimestampRefreshesExistingSessionExpiry(): void
    {
        $redis = new FakeRedisSessionClient();
        $handler = new RedisSessionHandler([
            'lifetime' => 3,
            'redis' => ['prefix' => 'sess:', 'lock_wait_ms' => 0],
        ], $redis);

        $handler->write('abc', 'payload-data');
        $redis->ttls['sess:data:abc'] = 1;

        self::assertTrue($handler->updateTimestamp('abc', 'payload-data'));
        self::assertSame(180, $redis->ttls['sess:data:abc'] ?? null);
    }

    public function testDestroyRemovesStoredSessionAndLock(): void
    {
        $redis = new FakeRedisSessionClient();
        $handler = new RedisSessionHandler(['lifetime' => 5, 'redis' => ['prefix' => 'sess:', 'lock_wait_ms' => 0]], $redis);

        $handler->write('abc', 'payload-data');
        $redis->data['sess:lock:abc'] = '1';

        self::assertTrue($handler->destroy('abc'));
        self::assertArrayNotHasKey('sess:data:abc', $redis->data);
        self::assertArrayNotHasKey('sess:lock:abc', $redis->data);
    }

    public function testWriteFailsWhenLockCannotBeAcquired(): void
    {
        $redis = new FakeRedisSessionClient();
        $redis->failNextLock = true;

        $handler = new RedisSessionHandler([
            'lifetime' => 5,
            'redis' => ['prefix' => 'sess:', 'lock_wait_ms' => 0],
        ], $redis);

        self::assertFalse($handler->write('abc', 'payload-data'));
        self::assertArrayNotHasKey('sess:data:abc', $redis->data);
    }
}

final class FakeRedisSessionClient
{
    public array $data = [];
    public array $ttls = [];
    public bool $failNextLock = false;

    public function get(string $key): string|false
    {
        return $this->data[$key] ?? false;
    }

    public function setex(string $key, int $ttl, string $value): bool
    {
        $this->data[$key] = $value;
        $this->ttls[$key] = $ttl;

        return true;
    }

    public function set(string $key, string $value, array $options = []): bool
    {
        if (in_array('nx', $options, true) && array_key_exists($key, $this->data)) {
            return false;
        }

        if ($this->failNextLock && str_contains($key, 'lock:')) {
            $this->failNextLock = false;
            return false;
        }

        $this->data[$key] = $value;
        if (isset($options['ex'])) {
            $this->ttls[$key] = (int) $options['ex'];
        }

        return true;
    }

    public function del(string ...$keys): int
    {
        $deleted = 0;
        foreach ($keys as $key) {
            if (array_key_exists($key, $this->data)) {
                unset($this->data[$key], $this->ttls[$key]);
                $deleted++;
            }
        }

        return $deleted;
    }

    public function exists(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function expire(string $key, int $ttl): bool
    {
        if (!array_key_exists($key, $this->data)) {
            return false;
        }

        $this->ttls[$key] = $ttl;

        return true;
    }
}