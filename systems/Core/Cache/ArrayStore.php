<?php

namespace Core\Cache;

/**
 * ArrayStore — In-memory cache driver (request-scoped only).
 *
 * Data is stored in a PHP array and lost once the request
 * or CLI process ends. Useful for testing or for short-lived
 * memoisation within a single request lifecycle.
 */
class ArrayStore
{
    /** @var array<string, array{value: mixed, expire: int}> */
    private array $storage = [];

    public function get(string $key, mixed $default = null): mixed
    {
        if (!isset($this->storage[$key])) {
            return $default;
        }

        $entry = $this->storage[$key];

        if ($entry['expire'] !== 0 && time() >= $entry['expire']) {
            unset($this->storage[$key]);
            return $default;
        }

        return $entry['value'];
    }

    public function put(string $key, mixed $value, int $seconds = 0): bool
    {
        $this->storage[$key] = [
            'value'  => $value,
            'expire' => $seconds > 0 ? time() + $seconds : 0,
        ];

        return true;
    }

    public function forever(string $key, mixed $value): bool
    {
        return $this->put($key, $value, 0);
    }

    public function has(string $key): bool
    {
        return $this->get($key, $this) !== $this;
    }

    public function increment(string $key, int $amount = 1): int
    {
        $current = (int) $this->get($key, 0);
        $new = $current + $amount;

        $expire = $this->storage[$key]['expire'] ?? 0;
        $remaining = $expire === 0 ? 0 : max(0, $expire - time());
        $this->put($key, $new, $remaining);

        return $new;
    }

    public function decrement(string $key, int $amount = 1): int
    {
        return $this->increment($key, -$amount);
    }

    public function forget(string $key): bool
    {
        unset($this->storage[$key]);
        return true;
    }

    public function flush(): bool
    {
        $this->storage = [];
        return true;
    }
}
