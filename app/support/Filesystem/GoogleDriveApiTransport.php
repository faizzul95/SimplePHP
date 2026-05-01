<?php

namespace App\Support\Filesystem;

use Google\Client;
use Google\Http\MediaFileUpload;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use InvalidArgumentException;
use RuntimeException;

final class GoogleDriveApiTransport implements GoogleDriveTransportInterface
{
    private const FOLDER_MIME = 'application/vnd.google-apps.folder';
    private const ROOT_PROPERTY = 'myth_root';
    private const PATH_HASH_PROPERTY = 'myth_path_hash';
    private const ENTRY_TYPE_PROPERTY = 'myth_entry_type';
    private const CHUNK_ALIGNMENT_BYTES = 262144;

    private string $rootId;
    private array $config;
    private int $chunkSize;
    private bool $supportsAllDrives;
    private ?string $driveId;
    private array $folderIdCache = [];
    private ?Client $client = null;
    private ?Drive $drive = null;

    public function __construct(string $rootId, array $config = [])
    {
        $this->rootId = trim($rootId);
        $this->config = $config;
        $this->chunkSize = $this->normalizeChunkSize((int) ($config['chunk_size'] ?? 8388608));
        $this->supportsAllDrives = (bool) ($config['supports_all_drives'] ?? true);
        $this->driveId = $this->normalizeOptionalString($config['shared_drive_id'] ?? null);
        $this->folderIdCache[''] = $this->rootId;

        if ($this->rootId === '') {
            throw new InvalidArgumentException('Google Drive transport requires a non-empty root folder id.');
        }
    }

    public function writeStream(string $path, $stream): bool
    {
        if (!is_resource($stream)) {
            throw new InvalidArgumentException('Stream resource is required for Google Drive uploads.');
        }

        $directory = $this->directoryName($path);
        $parentId = $this->resolveDirectoryId($directory, true);
        $filename = basename($path);
        $existing = $this->findManagedFile($path);
        $mimeType = $this->guessMimeType($filename);
        $metadata = new DriveFile([
            'name' => $filename,
            'parents' => [$parentId],
            'appProperties' => $this->managedProperties($path, false),
        ]);

        $stat = @fstat($stream);
        $size = is_array($stat) && isset($stat['size']) ? (int) $stat['size'] : null;
        if ($size === 0) {
            return $this->uploadEmptyFile($existing['id'] ?? null, $metadata, $mimeType);
        }

        $client = $this->googleClient();
        $client->setDefer(true);

        try {
            $request = $existing === null
                ? $this->driveService()->files->create($metadata, $this->requestOptions([
                    'uploadType' => 'resumable',
                    'fields' => 'id',
                ]))
                : $this->driveService()->files->update($existing['id'], $metadata, $this->requestOptions([
                    'uploadType' => 'resumable',
                    'fields' => 'id',
                ]));

            $uploader = $this->createUploader($request, $mimeType);
            if ($size !== null && method_exists($uploader, 'setFileSize')) {
                $uploader->setFileSize($size);
            }

            $this->rewindStreamIfPossible($stream);
            $uploaded = null;

            while (!feof($stream)) {
                $chunk = fread($stream, $this->chunkSize);
                if ($chunk === false) {
                    throw new RuntimeException('Unable to read the upload stream for Google Drive.');
                }

                if ($chunk === '') {
                    continue;
                }

                $uploaded = $uploader->nextChunk($chunk);
            }

            return $uploaded !== false && $uploaded !== null;
        } finally {
            $client->setDefer(false);
        }
    }

    public function readStream(string $path)
    {
        $file = $this->findManagedFile($path);
        if ($file === null) {
            throw new RuntimeException('Google Drive file does not exist: ' . $path);
        }

        $stream = fopen('php://temp/maxmemory:2097152', 'r+b');
        if ($stream === false) {
            throw new RuntimeException('Unable to allocate a temporary stream for Google Drive downloads.');
        }

        $uri = 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($file['id']) . '?alt=media';
        if ($this->supportsAllDrives) {
            $uri .= '&supportsAllDrives=true';
        }

        $response = $this->authorizedHttpClient()->request('GET', $uri, [
            'sink' => $stream,
            'http_errors' => false,
        ]);

        if ($response->getStatusCode() >= 400) {
            fclose($stream);
            throw new RuntimeException('Google Drive download failed with status ' . $response->getStatusCode() . '.');
        }

        rewind($stream);

        return $stream;
    }

    public function delete(string|array $paths): bool
    {
        $paths = is_array($paths) ? $paths : [$paths];
        $deleted = true;

        foreach ($paths as $path) {
            $normalized = trim((string) $path, '/');
            if ($normalized === '') {
                $deleted = $this->deleteDirectory('') && $deleted;
                continue;
            }

            $file = $this->findManagedFile($normalized);
            if ($file !== null) {
                $this->driveService()->files->delete($file['id'], $this->requestOptions());
                continue;
            }

            $directoryId = $this->resolveDirectoryId($normalized, false);
            if ($directoryId !== null) {
                $deleted = $this->deleteDirectory($normalized) && $deleted;
            }
        }

        return $deleted;
    }

    public function exists(string $path): bool
    {
        if ($path === '') {
            return true;
        }

        return $this->findManagedFile($path) !== null || $this->resolveDirectoryId($path, false) !== null;
    }

    public function copy(string $from, string $to): bool
    {
        $source = $this->findManagedFile($from);
        if ($source === null) {
            return false;
        }

        if ($this->exists($to)) {
            $this->delete($to);
        }

        $targetDirectory = $this->directoryName($to);
        $targetParentId = $this->resolveDirectoryId($targetDirectory, true);
        $metadata = new DriveFile([
            'name' => basename($to),
            'parents' => [$targetParentId],
            'appProperties' => $this->managedProperties($to, false),
        ]);

        $copied = $this->driveService()->files->copy($source['id'], $metadata, $this->requestOptions([
            'fields' => 'id',
        ]));

        return $copied !== null;
    }

    public function move(string $from, string $to): bool
    {
        if ($from === $to) {
            return true;
        }

        $source = $this->findManagedFile($from);
        if ($source === null) {
            return false;
        }

        if ($this->exists($to)) {
            $this->delete($to);
        }

        $targetDirectory = $this->directoryName($to);
        $targetParentId = $this->resolveDirectoryId($targetDirectory, true);
        $metadata = new DriveFile([
            'name' => basename($to),
            'appProperties' => $this->managedProperties($to, false),
        ]);

        $options = $this->requestOptions([
            'fields' => 'id',
        ]);
        $currentParents = array_filter((array) ($source['parents'] ?? []), 'is_string');
        if (!in_array($targetParentId, $currentParents, true)) {
            $options['addParents'] = $targetParentId;
            if ($currentParents !== []) {
                $options['removeParents'] = implode(',', $currentParents);
            }
        }

        $updated = $this->driveService()->files->update($source['id'], $metadata, $options);

        return $updated !== null;
    }

    public function makeDirectory(string $directory): bool
    {
        return $this->resolveDirectoryId($directory, true) !== null;
    }

    public function deleteDirectory(string $directory): bool
    {
        $normalized = trim($directory, '/');
        $directoryId = $normalized === '' ? $this->rootId : $this->resolveDirectoryId($normalized, false);
        if ($directoryId === null) {
            return true;
        }

        $foldersToDelete = [];
        $stack = [[$directoryId, $normalized]];

        while ($stack !== []) {
            [$folderId, $prefix] = array_pop($stack);
            $children = $this->listChildren($folderId);

            foreach ($children['files'] as $file) {
                $this->driveService()->files->delete($file['id'], $this->requestOptions());
            }

            foreach ($children['folders'] as $folder) {
                $childPath = ltrim(($prefix !== '' ? $prefix . '/' : '') . $folder['name'], '/');
                $foldersToDelete[] = ['id' => $folder['id'], 'path' => $childPath];
                $stack[] = [$folder['id'], $childPath];
            }
        }

        usort($foldersToDelete, static fn(array $left, array $right): int => strlen($right['path']) <=> strlen($left['path']));

        foreach ($foldersToDelete as $folder) {
            $this->driveService()->files->delete($folder['id'], $this->requestOptions());
            unset($this->folderIdCache[$folder['path']]);
        }

        if ($normalized !== '') {
            $this->driveService()->files->delete($directoryId, $this->requestOptions());
            unset($this->folderIdCache[$normalized]);
        }

        return true;
    }

    public function files(string $directory = ''): array
    {
        $normalized = trim($directory, '/');
        $directoryId = $normalized === '' ? $this->rootId : $this->resolveDirectoryId($normalized, false);
        if ($directoryId === null) {
            return [];
        }

        $files = array_map(static function (array $file) use ($normalized): string {
            return ltrim(($normalized !== '' ? $normalized . '/' : '') . $file['name'], '/');
        }, $this->listChildren($directoryId)['files']);

        sort($files);

        return $files;
    }

    public function allFiles(string $directory = ''): array
    {
        $normalized = trim($directory, '/');
        $directoryId = $normalized === '' ? $this->rootId : $this->resolveDirectoryId($normalized, false);
        if ($directoryId === null) {
            return [];
        }

        $results = [];
        $stack = [[$directoryId, $normalized]];

        while ($stack !== []) {
            [$folderId, $prefix] = array_pop($stack);
            $children = $this->listChildren($folderId);

            foreach ($children['files'] as $file) {
                $results[] = ltrim(($prefix !== '' ? $prefix . '/' : '') . $file['name'], '/');
            }

            foreach ($children['folders'] as $folder) {
                $childPrefix = ltrim(($prefix !== '' ? $prefix . '/' : '') . $folder['name'], '/');
                $stack[] = [$folder['id'], $childPrefix];
            }
        }

        sort($results);

        return $results;
    }

    private function googleClient(): Client
    {
        if ($this->client instanceof Client) {
            return $this->client;
        }

        if (isset($this->config['client'])) {
            if (!$this->config['client'] instanceof Client) {
                throw new InvalidArgumentException('Google Drive client configuration must be an instance of Google\\Client.');
            }

            return $this->client = $this->config['client'];
        }

        if (!class_exists(Client::class)) {
            throw new RuntimeException('Google API client is not installed. Run composer require google/apiclient.');
        }

        $client = new Client();
        $client->setApplicationName((string) ($this->config['application_name'] ?? 'MythPHP Filesystem'));
        $client->setScopes([Drive::DRIVE]);

        $credentialsJson = $this->normalizeOptionalString($this->config['credentials_json'] ?? env('FILESYSTEM_GDRIVE_CREDENTIALS_JSON', ''));
        $credentialsPath = $this->normalizeOptionalString($this->config['credentials_path'] ?? env('FILESYSTEM_GDRIVE_CREDENTIALS_PATH', ''));

        if ($credentialsJson !== null) {
            $decoded = json_decode($credentialsJson, true);
            if (!is_array($decoded)) {
                throw new InvalidArgumentException('Google Drive credentials_json must contain valid JSON service-account credentials.');
            }

            $client->setAuthConfig($decoded);
        } elseif ($credentialsPath !== null) {
            $client->setAuthConfig($credentialsPath);
        } else {
            throw new InvalidArgumentException('Google Drive credentials are required. Configure credentials_path or credentials_json before using this disk.');
        }

        $subject = $this->normalizeOptionalString($this->config['subject'] ?? env('FILESYSTEM_GDRIVE_SUBJECT', ''));
        if ($subject !== null && method_exists($client, 'setSubject')) {
            $client->setSubject($subject);
        }

        return $this->client = $client;
    }

    private function driveService(): Drive
    {
        if ($this->drive instanceof Drive) {
            return $this->drive;
        }

        if (isset($this->config['drive_service'])) {
            if (!$this->config['drive_service'] instanceof Drive) {
                throw new InvalidArgumentException('Google Drive service configuration must be an instance of Google\\Service\\Drive.');
            }

            return $this->drive = $this->config['drive_service'];
        }

        return $this->drive = new Drive($this->googleClient());
    }

    private function authorizedHttpClient(): object
    {
        if (isset($this->config['http_client']) && is_object($this->config['http_client'])) {
            return $this->config['http_client'];
        }

        return $this->googleClient()->authorize();
    }

    private function createUploader($request, string $mimeType): object
    {
        if (isset($this->config['uploader_factory']) && is_callable($this->config['uploader_factory'])) {
            return ($this->config['uploader_factory'])($this->googleClient(), $request, $mimeType, $this->chunkSize);
        }

        return new MediaFileUpload($this->googleClient(), $request, $mimeType, null, true, $this->chunkSize);
    }

    private function uploadEmptyFile(?string $existingId, DriveFile $metadata, string $mimeType): bool
    {
        $options = $this->requestOptions([
            'data' => '',
            'mimeType' => $mimeType,
            'uploadType' => 'multipart',
            'fields' => 'id',
        ]);

        $result = $existingId === null
            ? $this->driveService()->files->create($metadata, $options)
            : $this->driveService()->files->update($existingId, $metadata, $options);

        return $result !== null;
    }

    private function findManagedFile(string $path): ?array
    {
        $query = sprintf(
            "trashed = false and mimeType != '%s' and appProperties has { key='%s' and value='%s' } and appProperties has { key='%s' and value='%s' }",
            self::FOLDER_MIME,
            self::ROOT_PROPERTY,
            $this->escapeDriveQueryLiteral($this->rootId),
            self::PATH_HASH_PROPERTY,
            $this->escapeDriveQueryLiteral($this->pathHash($path))
        );

        $files = $this->runListQuery($query, [
            'pageSize' => 1,
            'fields' => 'files(id, name, mimeType, parents, appProperties)',
        ]);

        return $files[0] ?? null;
    }

    private function resolveDirectoryId(string $directory, bool $create): ?string
    {
        $directory = trim($directory, '/');
        if (array_key_exists($directory, $this->folderIdCache)) {
            return $this->folderIdCache[$directory];
        }

        if ($directory === '') {
            return $this->folderIdCache[''];
        }

        $query = sprintf(
            "trashed = false and mimeType = '%s' and appProperties has { key='%s' and value='%s' } and appProperties has { key='%s' and value='%s' }",
            self::FOLDER_MIME,
            self::ROOT_PROPERTY,
            $this->escapeDriveQueryLiteral($this->rootId),
            self::PATH_HASH_PROPERTY,
            $this->escapeDriveQueryLiteral($this->pathHash($directory))
        );

        $files = $this->runListQuery($query, [
            'pageSize' => 1,
            'fields' => 'files(id, name, mimeType, parents, appProperties)',
        ]);
        if ($files !== []) {
            return $this->folderIdCache[$directory] = (string) $files[0]['id'];
        }

        if (!$create) {
            return null;
        }

        $parentDirectory = $this->directoryName($directory);
        $parentId = $this->resolveDirectoryId($parentDirectory, true);
        if ($parentId === null) {
            throw new RuntimeException('Unable to resolve Google Drive parent directory for path: ' . $directory);
        }

        $folder = new DriveFile([
            'name' => basename($directory),
            'mimeType' => self::FOLDER_MIME,
            'parents' => [$parentId],
            'appProperties' => $this->managedProperties($directory, true),
        ]);

        $created = $this->driveService()->files->create($folder, $this->requestOptions([
            'fields' => 'id',
        ]));
        if ($created === null || !method_exists($created, 'offsetGet') && !method_exists($created, 'getId')) {
            throw new RuntimeException('Unable to create Google Drive directory: ' . $directory);
        }

        $folderId = method_exists($created, 'getId') ? (string) $created->getId() : (string) $created['id'];
        $this->folderIdCache[$directory] = $folderId;

        return $folderId;
    }

    private function listChildren(string $folderId): array
    {
        $query = sprintf(
            "trashed = false and '%s' in parents and appProperties has { key='%s' and value='%s' }",
            $this->escapeDriveQueryLiteral($folderId),
            self::ROOT_PROPERTY,
            $this->escapeDriveQueryLiteral($this->rootId)
        );

        $children = $this->runListQuery($query, [
            'fields' => 'files(id, name, mimeType, parents, appProperties)',
        ]);

        $files = [];
        $folders = [];
        foreach ($children as $child) {
            if (($child['mimeType'] ?? '') === self::FOLDER_MIME) {
                $folders[] = $child;
                continue;
            }

            $files[] = $child;
        }

        usort($files, static fn(array $left, array $right): int => strcmp((string) $left['name'], (string) $right['name']));
        usort($folders, static fn(array $left, array $right): int => strcmp((string) $left['name'], (string) $right['name']));

        return [
            'files' => $files,
            'folders' => $folders,
        ];
    }

    private function runListQuery(string $query, array $options = []): array
    {
        $results = [];
        $pageToken = null;

        do {
            $params = array_merge($this->requestOptions([
                'q' => $query,
                'pageSize' => 1000,
                'fields' => 'nextPageToken, files(id, name, mimeType, parents, appProperties)',
            ]), $options);

            if ($pageToken !== null) {
                $params['pageToken'] = $pageToken;
            }

            $response = $this->driveService()->files->listFiles($params);
            foreach ((array) $response->getFiles() as $file) {
                $results[] = [
                    'id' => (string) $file->getId(),
                    'name' => (string) $file->getName(),
                    'mimeType' => (string) $file->getMimeType(),
                    'parents' => (array) $file->getParents(),
                    'appProperties' => (array) $file->getAppProperties(),
                ];
            }

            $pageToken = $response->getNextPageToken();
        } while ($pageToken !== null && $pageToken !== '');

        return $results;
    }

    private function requestOptions(array $extra = []): array
    {
        $options = $extra;
        if ($this->supportsAllDrives) {
            $options['supportsAllDrives'] = true;
            $options['includeItemsFromAllDrives'] = true;
        }

        if ($this->driveId !== null) {
            $options['driveId'] = $this->driveId;
            $options['corpora'] = 'drive';
        }

        return $options;
    }

    private function managedProperties(string $path, bool $directory): array
    {
        return [
            self::ROOT_PROPERTY => $this->rootId,
            self::PATH_HASH_PROPERTY => $this->pathHash($path),
            self::ENTRY_TYPE_PROPERTY => $directory ? 'directory' : 'file',
        ];
    }

    private function pathHash(string $path): string
    {
        return hash('sha256', trim($path, '/'));
    }

    private function directoryName(string $path): string
    {
        $directory = dirname($path);

        return $directory === '.' ? '' : trim(str_replace('\\', '/', $directory), '/');
    }

    private function normalizeChunkSize(int $chunkSize): int
    {
        $chunkSize = max(self::CHUNK_ALIGNMENT_BYTES, $chunkSize);
        $chunkSize = (int) (floor($chunkSize / self::CHUNK_ALIGNMENT_BYTES) * self::CHUNK_ALIGNMENT_BYTES);

        return max(self::CHUNK_ALIGNMENT_BYTES, $chunkSize);
    }

    private function normalizeOptionalString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function guessMimeType(string $path): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($extension) {
            'csv' => 'text/csv',
            'json' => 'application/json',
            'pdf' => 'application/pdf',
            'sql' => 'application/sql',
            'txt', 'log' => 'text/plain',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'zip' => 'application/zip',
            default => 'application/octet-stream',
        };
    }

    private function rewindStreamIfPossible($stream): void
    {
        $meta = stream_get_meta_data($stream);
        if (($meta['seekable'] ?? false) === true) {
            rewind($stream);
        }
    }

    private function escapeDriveQueryLiteral(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }
}