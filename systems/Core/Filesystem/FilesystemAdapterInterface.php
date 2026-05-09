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

    /**
     * Return the file size in bytes.
     *
     * @throws \RuntimeException if the path does not exist.
     */
    public function size(string $path): int;

    /**
     * Return the file's last-modified time as a Unix timestamp.
     *
     * @throws \RuntimeException if the path does not exist.
     */
    public function lastModified(string $path): int;

    /**
     * Generate a time-limited signed URL for temporary access to a private file.
     *
     * Local disk: embeds expiry + HMAC-SHA256 signature in the query string.
     * Remote drivers (S3, GDrive): generate a driver-native pre-signed URL.
     *
     * @param string             $path    Relative path within the disk.
     * @param \DateTimeInterface $expiry  Absolute expiry time.
     * @return string  Absolute URL with embedded expiry + signature.
     */
    public function temporaryUrl(string $path, \DateTimeInterface $expiry): string;

    /**
     * Return the visibility of a file: 'public' or 'private'.
     *
     * @throws \RuntimeException if the path does not exist.
     */
    public function visibility(string $path): string;

    /**
     * Set the visibility of a file.
     *
     * @param string $visibility 'public' (world-readable) or 'private' (owner only).
     * @throws \RuntimeException if the path does not exist or chmod fails.
     */
    public function setVisibility(string $path, string $visibility): bool;
}