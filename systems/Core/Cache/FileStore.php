<?php

namespace Core\Cache;

/**
 * FileStore — File-based cache driver.
 *
 * Each key is stored as a separate file inside the configured
 * directory.  The first line of the file contains the UNIX
 * expiration timestamp; the rest is the serialised payload.
 */
class FileStore
{
    private string $directory;

    public function __construct(string $directory)
    {
        $this->directory = rtrim($directory, '/\\');

        if (!is_dir($this->directory)) {
            @mkdir($this->directory, 0755, true);
        }
    }

    /**
     * Retrieve an item from cache.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $path = $this->path($key);

        if (!is_file($path)) {
            return $default;
        }

        $contents = @file_get_contents($path);
        if ($contents === false) {
            return $default;
        }

        $expire = (int) substr($contents, 0, 10);

        // 0 = no expiration
        if ($expire !== 0 && time() >= $expire) {
            $this->forget($key);
            return $default;
        }

        $data = substr($contents, 10);
        $value = @unserialize($data, ['allowed_classes' => false]);

        return $value === false && $data !== serialize(false) ? $default : $value;
    }

    /**
     * Store an item in the cache.
     *
     * @param string $key
     * @param mixed  $value
     * @param int    $seconds  0 = forever
     */
    public function put(string $key, mixed $value, int $seconds = 0): bool
    {
        $path = $this->path($key);
        $dir  = dirname($path);

        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $expire = $seconds > 0 ? time() + $seconds : 0;
        $payload = str_pad((string) $expire, 10, '0', STR_PAD_LEFT) . serialize($value);

        return @file_put_contents($path, $payload, LOCK_EX) !== false;
    }

    /**
     * Store an item forever.
     */
    public function forever(string $key, mixed $value): bool
    {
        return $this->put($key, $value, 0);
    }

    /**
     * Determine if an item exists in the cache.
     */
    public function has(string $key): bool
    {
        return $this->get($key, $this) !== $this;
    }

    /**
     * Increment a numeric value.
     */
    public function increment(string $key, int $amount = 1): int
    {
        $current = (int) $this->get($key, 0);
        $new = $current + $amount;

        // Preserve the existing TTL
        $path = $this->path($key);
        $expire = 0;
        if (is_file($path)) {
            $raw = @file_get_contents($path);
            if ($raw !== false) {
                $expire = (int) substr($raw, 0, 10);
            }
        }

        $remaining = $expire === 0 ? 0 : max(0, $expire - time());
        $this->put($key, $new, $remaining);

        return $new;
    }

    /**
     * Decrement a numeric value.
     */
    public function decrement(string $key, int $amount = 1): int
    {
        return $this->increment($key, -$amount);
    }

    /**
     * Remove an item from the cache.
     */
    public function forget(string $key): bool
    {
        $path = $this->path($key);

        if (is_file($path)) {
            return @unlink($path);
        }

        return false;
    }

    /**
     * Remove all items from this store.
     */
    public function flush(): bool
    {
        if (!is_dir($this->directory)) {
            return true;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        $ok = true;
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                $ok = @rmdir($item->getPathname()) && $ok;
            } else {
                $ok = @unlink($item->getPathname()) && $ok;
            }
        }

        return $ok;
    }

    /**
     * Build the file path for a given key.
     */
    private function path(string $key): string
    {
        $hash = sha1($key);
        // Two-level directory to avoid filesystem limits
        return $this->directory
            . DIRECTORY_SEPARATOR . substr($hash, 0, 2)
            . DIRECTORY_SEPARATOR . substr($hash, 2);
    }
}
