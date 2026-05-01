<?php

namespace App\Support\Filesystem;

use InvalidArgumentException;

class S3FilesystemAdapter extends ScaffoldedRemoteFilesystemAdapter
{
    private string $bucket;
    private string $region;
    private ?string $baseUrl;

    public function __construct(array $config = [])
    {
        parent::__construct($config);

        $this->bucket = trim((string) ($config['bucket'] ?? ''));
        $this->region = trim((string) ($config['region'] ?? ''));
        $this->baseUrl = isset($config['base_url']) ? rtrim((string) $config['base_url'], '/') : null;

        if ($this->bucket === '') {
            throw new InvalidArgumentException('S3 filesystem adapter requires a non-empty bucket configuration.');
        }

        if ($this->region === '') {
            throw new InvalidArgumentException('S3 filesystem adapter requires a non-empty region configuration.');
        }
    }

    public function path(string $path): string
    {
        $relativePath = $this->normalizeRelativePath($path);

        return 's3://' . $this->bucket . ($relativePath !== '' ? '/' . $relativePath : '');
    }

    public function url(string $path): string
    {
        $relativePath = $this->normalizeRelativePath($path);
        if ($this->baseUrl !== null && $this->baseUrl !== '') {
            return $this->baseUrl . ($relativePath !== '' ? '/' . $relativePath : '');
        }

        return 'https://' . $this->bucket . '.s3.' . $this->region . '.amazonaws.com' . ($relativePath !== '' ? '/' . $relativePath : '');
    }
}