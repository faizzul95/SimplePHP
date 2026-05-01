<?php

namespace Core\Filesystem;

interface FilesystemAdapterInterface
{
    public function put(string $path, string $contents): bool;

    public function writeStream(string $path, $stream): bool;

    public function get(string $path): string;

    public function readStream(string $path);

    public function delete(string|array $paths): bool;

    public function exists(string $path): bool;

    public function copy(string $from, string $to): bool;

    public function move(string $from, string $to): bool;

    public function path(string $path): string;

    public function url(string $path): string;

    public function makeDirectory(string $directory): bool;

    public function deleteDirectory(string $directory): bool;

    public function files(string $directory = ''): array;

    public function allFiles(string $directory = ''): array;
}