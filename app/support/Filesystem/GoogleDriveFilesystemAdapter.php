<?php

namespace App\Support\Filesystem;

use InvalidArgumentException;
use RuntimeException;

class GoogleDriveFilesystemAdapter extends ScaffoldedRemoteFilesystemAdapter
{
    private string $rootId;
    private ?string $baseUrl;
    private ?GoogleDriveTransportInterface $transport;

    public function __construct(array $config = [])
    {
        parent::__construct($config);

        $this->rootId = trim((string) ($config['root_id'] ?? $config['folder_id'] ?? ''));
        $this->baseUrl = isset($config['base_url']) ? rtrim((string) $config['base_url'], '/') : null;
        $this->transport = isset($config['transport']) && $config['transport'] instanceof GoogleDriveTransportInterface
            ? $config['transport']
            : null;

        if ($this->rootId === '') {
            throw new InvalidArgumentException('Google Drive filesystem adapter requires a non-empty root_id or folder_id configuration.');
        }
    }

    public function put(string $path, string $contents): bool
    {
        $stream = fopen('php://temp', 'r+b');
        if ($stream === false) {
            throw new RuntimeException('Unable to allocate a temporary stream for Google Drive uploads.');
        }

        try {
            fwrite($stream, $contents);
            rewind($stream);

            return $this->writeStream($path, $stream);
        } finally {
            fclose($stream);
        }
    }

    public function writeStream(string $path, $stream): bool
    {
        return $this->transport()->writeStream($this->normalizeRelativePath($path), $stream);
    }

    public function get(string $path): string
    {
        $stream = $this->readStream($path);

        try {
            $contents = stream_get_contents($stream);
        } finally {
            fclose($stream);
        }

        if ($contents === false) {
            throw new RuntimeException('Unable to read the Google Drive stream contents.');
        }

        return $contents;
    }

    public function readStream(string $path)
    {
        return $this->transport()->readStream($this->normalizeRelativePath($path));
    }

    public function delete(string|array $paths): bool
    {
        if (is_array($paths)) {
            $paths = array_map(fn(string $path): string => $this->normalizeRelativePath($path), $paths);
        } else {
            $paths = $this->normalizeRelativePath($paths);
        }

        return $this->transport()->delete($paths);
    }

    public function exists(string $path): bool
    {
        return $this->transport()->exists($this->normalizeRelativePath($path));
    }

    public function copy(string $from, string $to): bool
    {
        return $this->transport()->copy(
            $this->normalizeRelativePath($from),
            $this->normalizeRelativePath($to)
        );
    }

    public function move(string $from, string $to): bool
    {
        return $this->transport()->move(
            $this->normalizeRelativePath($from),
            $this->normalizeRelativePath($to)
        );
    }

    public function path(string $path): string
    {
        $relativePath = $this->normalizeRelativePath($path);

        return 'gdrive://' . $this->rootId . ($relativePath !== '' ? '/' . $relativePath : '');
    }

    public function url(string $path): string
    {
        $relativePath = $this->normalizeRelativePath($path);
        if ($this->baseUrl !== null && $this->baseUrl !== '') {
            return $this->baseUrl . ($relativePath !== '' ? '/' . $relativePath : '');
        }

        return 'https://drive.google.com/drive/folders/' . $this->rootId . ($relativePath !== '' ? '?path=' . rawurlencode($relativePath) : '');
    }

    public function makeDirectory(string $directory): bool
    {
        return $this->transport()->makeDirectory($this->normalizeRelativePath($directory));
    }

    public function deleteDirectory(string $directory): bool
    {
        return $this->transport()->deleteDirectory($this->normalizeRelativePath($directory));
    }

    public function files(string $directory = ''): array
    {
        return $this->transport()->files($this->normalizeRelativePath($directory));
    }

    public function allFiles(string $directory = ''): array
    {
        return $this->transport()->allFiles($this->normalizeRelativePath($directory));
    }

    private function transport(): GoogleDriveTransportInterface
    {
        return $this->transport ??= new GoogleDriveApiTransport($this->rootId, $this->config);
    }
}