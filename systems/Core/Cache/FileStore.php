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
            // 0750: owner r/w/x, group r/x, world none — cache may contain PII.
            @mkdir($this->directory, 0750, true);
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
        $value = json_decode($data, true);

        return ($value === null && $data !== 'null') ? $default : $value;
    }

    /**
     * Store an item in the cache.
     *
     * Writes atomically via a temp file + rename() so concurrent readers
     * never see a half-written payload (POSIX rename is atomic; on Windows
     * it is best-effort but still safer than an in-place overwrite).
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
            // 0750 — consistent with constructor; cache sub-dirs may hold PII.
            @mkdir($dir, 0750, true);
        }

        $expire  = $seconds > 0 ? time() + $seconds : 0;
        $payload = str_pad((string) $expire, 10, '0', STR_PAD_LEFT) . json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        // Atomic: write to a temp file then rename into place.
        // Use random bytes instead of PID — PIDs are reused by the OS after
        // worker restarts; two processes can share the same PID and race on
        // the same temp path on heavily-forked environments (e.g. PHP-FPM).
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $payload, LOCK_EX) === false) {
            return false;
        }

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }

        return true;
    }

    /**
     * Store an item forever.
     */
    public function forever(string $key, mixed $value): bool
    {
        return $this->put($key, $value, 0);
    }

    /**
     * Store an item only if the key does not already exist (atomic).
     *
     * Uses O_EXCL create semantics so two concurrent callers cannot both see
     * "missing" and both write — only one caller wins.
     */
    public function add(string $key, mixed $value, int $seconds = 0): bool
    {
        $path = $this->path($key);
        $dir  = dirname($path);

        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        // If an unexpired entry already exists, short-circuit without racing.
        // If the entry is expired, remove it so the exclusive create below
        // can succeed for the replacement.
        if (is_file($path)) {
            $existing = @file_get_contents($path);
            if ($existing !== false) {
                $expire = (int) substr($existing, 0, 10);
                if ($expire === 0 || time() < $expire) {
                    return false;
                }
            }
            @unlink($path);
        }

        $handle = @fopen($path, 'xb');
        if ($handle === false) {
            // File appeared between the check and fopen — someone else won.
            return false;
        }

        try {
            $expire = $seconds > 0 ? time() + $seconds : 0;
            $payload = str_pad((string) $expire, 10, '0', STR_PAD_LEFT) . json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            fwrite($handle, $payload);
            fflush($handle);
            return true;
        } finally {
            fclose($handle);
        }
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
     *
     * Uses an exclusive file lock across read+compute+write to keep concurrent
     * increments atomic (otherwise two workers can read the same value and
     * lose updates).
     */
    public function increment(string $key, int $amount = 1): int
    {
        $path = $this->path($key);
        $dir  = dirname($path);

        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        $handle = @fopen($path, 'c+b');
        if ($handle === false) {
            // Fall back to put with best-effort semantics if we cannot open the file.
            $new = ((int) $this->get($key, 0)) + $amount;
            $this->put($key, $new, 0);
            return $new;
        }

        try {
            flock($handle, LOCK_EX);

            $contents = '';
            while (!feof($handle)) {
                $chunk = fread($handle, 8192);
                if ($chunk === false) {
                    break;
                }
                $contents .= $chunk;
            }

            $expire = 0;
            $current = 0;
            if ($contents !== '') {
                $expire = (int) substr($contents, 0, 10);
                if ($expire === 0 || time() < $expire) {
                    $data = substr($contents, 10);
                    $decoded = json_decode($data, true);
                    if ($decoded !== null || $data === 'null') {
                        $current = (int) $decoded;
                    }
                }
            }

            $new = $current + $amount;
            $payload = str_pad((string) $expire, 10, '0', STR_PAD_LEFT) . json_encode($new, JSON_THROW_ON_ERROR);

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, $payload);
            fflush($handle);

            return $new;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
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

    /**
     * Return metadata for a cache key, including TTL remaining.
     * Used by RateLimiter::availableIn() to determine retry-after seconds.
     */
    public function getMetadata(string $key): array
    {
        $path = $this->path($key);
        if (!is_file($path)) {
            return [];
        }
        $contents = @file_get_contents($path);
        if ($contents === false) {
            return [];
        }
        $expire = (int) substr($contents, 0, 10);
        if ($expire === 0) {
            return ['expires_in' => 0];
        }
        return ['expires_in' => max(0, $expire - time())];
    }
}
