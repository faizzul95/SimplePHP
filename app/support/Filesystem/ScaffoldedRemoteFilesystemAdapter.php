<?php

namespace App\Support\Filesystem;

use Core\Filesystem\FilesystemAdapterInterface;
use InvalidArgumentException;
use RuntimeException;

abstract class ScaffoldedRemoteFilesystemAdapter implements FilesystemAdapterInterface
{
    protected array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    public function put(string $path, string $contents): bool
    {
        throw $this->notImplemented();
    }

    public function writeStream(string $path, $stream): bool
    {
        throw $this->notImplemented();
    }

    public function get(string $path): string
    {
        throw $this->notImplemented();
    }

    public function readStream(string $path)
    {
        throw $this->notImplemented();
    }

    public function delete(string|array $paths): bool
    {
        throw $this->notImplemented();
    }

    public function exists(string $path): bool
    {
        throw $this->notImplemented();
    }

    public function copy(string $from, string $to): bool
    {
        throw $this->notImplemented();
    }

    public function move(string $from, string $to): bool
    {
        throw $this->notImplemented();
    }

    public function makeDirectory(string $directory): bool
    {
        return true;
    }

    public function deleteDirectory(string $directory): bool
    {
        return true;
    }

    public function files(string $directory = ''): array
    {
        throw $this->notImplemented();
    }

    public function allFiles(string $directory = ''): array
    {
        throw $this->notImplemented();
    }

    protected function normalizeRelativePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = trim($path, '/');

        if ($path === '') {
            return '';
        }

        $segments = array_values(array_filter(explode('/', $path), static function (string $segment): bool {
            return $segment !== '' && $segment !== '.';
        }));

        foreach ($segments as $segment) {
            if ($segment === '..') {
                throw new InvalidArgumentException('Directory traversal is not allowed in storage paths.');
            }
        }

        return implode('/', $segments);
    }

    protected function notImplemented(): RuntimeException
    {
        return new RuntimeException(static::class . ' is registered as a scaffold only. Install the provider SDK and implement remote operations before using this disk in production.');
    }
}