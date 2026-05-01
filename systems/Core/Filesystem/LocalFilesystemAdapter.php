<?php

namespace Core\Filesystem;

use InvalidArgumentException;
use RuntimeException;

class LocalFilesystemAdapter implements FilesystemAdapterInterface
{
    private string $root;
    private ?string $url;

    public function __construct(array $config = [])
    {
        $root = (string) ($config['root'] ?? 'storage/app');
        $this->root = $this->resolveRootPath($root);
        $this->url = isset($config['url']) ? rtrim((string) $config['url'], '/') : null;
    }

    public function put(string $path, string $contents): bool
    {
        $absolutePath = $this->path($path);
        $this->ensureDirectory(dirname($absolutePath));

        return file_put_contents($absolutePath, $contents) !== false;
    }

    public function writeStream(string $path, $stream): bool
    {
        if (!is_resource($stream)) {
            throw new InvalidArgumentException('Stream resource is required.');
        }

        $absolutePath = $this->path($path);
        $this->ensureDirectory(dirname($absolutePath));

        $target = fopen($absolutePath, 'wb');
        if ($target === false) {
            throw new RuntimeException('Unable to open target file for writing.');
        }

        try {
            $copied = stream_copy_to_stream($stream, $target);
        } finally {
            fclose($target);
        }

        return $copied !== false;
    }

    public function get(string $path): string
    {
        $absolutePath = $this->path($path);
        if (!is_file($absolutePath)) {
            throw new RuntimeException('File does not exist: ' . $path);
        }

        $contents = file_get_contents($absolutePath);
        if ($contents === false) {
            throw new RuntimeException('Unable to read file: ' . $path);
        }

        return $contents;
    }

    public function readStream(string $path)
    {
        $absolutePath = $this->path($path);
        $stream = fopen($absolutePath, 'rb');

        if ($stream === false) {
            throw new RuntimeException('Unable to open file stream: ' . $path);
        }

        return $stream;
    }

    public function delete(string|array $paths): bool
    {
        $paths = is_array($paths) ? $paths : [$paths];
        $deleted = true;

        foreach ($paths as $path) {
            $absolutePath = $this->path((string) $path);
            if (!file_exists($absolutePath)) {
                continue;
            }

            if (is_dir($absolutePath)) {
                $deleted = $this->deleteDirectory((string) $path) && $deleted;
                continue;
            }

            $deleted = unlink($absolutePath) && $deleted;
        }

        return $deleted;
    }

    public function exists(string $path): bool
    {
        return file_exists($this->path($path));
    }

    public function copy(string $from, string $to): bool
    {
        $source = $this->path($from);
        $target = $this->path($to);
        $this->ensureDirectory(dirname($target));

        return copy($source, $target);
    }

    public function move(string $from, string $to): bool
    {
        $source = $this->path($from);
        $target = $this->path($to);
        $this->ensureDirectory(dirname($target));

        return rename($source, $target);
    }

    public function path(string $path): string
    {
        $relativePath = $this->normalizeRelativePath($path);
        if ($relativePath === '') {
            return $this->root;
        }

        return $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    }

    public function url(string $path): string
    {
        $relativePath = $this->normalizeRelativePath($path);
        if ($this->url === null || $this->url === '') {
            return '/' . ltrim(str_replace(DIRECTORY_SEPARATOR, '/', $relativePath), '/');
        }

        return $this->url . '/' . ltrim($relativePath, '/');
    }

    public function makeDirectory(string $directory): bool
    {
        $absolutePath = $this->path($directory);
        $this->ensureDirectory($absolutePath);

        return is_dir($absolutePath);
    }

    public function deleteDirectory(string $directory): bool
    {
        $absolutePath = $this->path($directory);
        if (!is_dir($absolutePath)) {
            return true;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($absolutePath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                if (!rmdir($item->getPathname())) {
                    return false;
                }
                continue;
            }

            if (!unlink($item->getPathname())) {
                return false;
            }
        }

        return rmdir($absolutePath);
    }

    public function files(string $directory = ''): array
    {
        $absolutePath = $this->path($directory);
        if (!is_dir($absolutePath)) {
            return [];
        }

        $files = [];
        foreach (scandir($absolutePath) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $pathname = $absolutePath . DIRECTORY_SEPARATOR . $entry;
            if (is_file($pathname)) {
                $prefix = $this->normalizeRelativePath($directory);
                $files[] = ltrim(($prefix !== '' ? $prefix . '/' : '') . $entry, '/');
            }
        }

        sort($files);

        return $files;
    }

    public function allFiles(string $directory = ''): array
    {
        $absolutePath = $this->path($directory);
        if (!is_dir($absolutePath)) {
            return [];
        }

        $relativePrefix = $this->normalizeRelativePath($directory);
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($absolutePath, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $item) {
            if (!$item->isFile()) {
                continue;
            }

            $relative = str_replace($absolutePath . DIRECTORY_SEPARATOR, '', $item->getPathname());
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
            $files[] = ltrim(($relativePrefix !== '' ? $relativePrefix . '/' : '') . $relative, '/');
        }

        sort($files);

        return $files;
    }

    private function resolveRootPath(string $root): string
    {
        $root = trim($root);
        if ($root === '') {
            throw new InvalidArgumentException('Filesystem root path cannot be empty.');
        }

        $isAbsolute = preg_match('/^[A-Za-z]:\\\\|^\\\\|^\//', $root) === 1;
        $resolved = $isAbsolute ? $root : ROOT_DIR . ltrim($root, '\\/');

        return rtrim($resolved, '\\/');
    }

    private function normalizeRelativePath(string $path): string
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

    private function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (!mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create directory: ' . $directory);
        }
    }
}