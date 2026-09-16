<?php

declare(strict_types=1);

namespace Core\Server;

/**
 * Translate a PSR-7 server request into PHP superglobals.
 *
 * MythPHP reads $_SERVER/$_GET/$_POST/$_FILES/$_COOKIE directly, so a PSR-7 based
 * worker has to populate them. Kept out of the worker script itself so the mapping
 * can be unit-tested without RoadRunner installed.
 */
final class PsrRequestBridge
{
    /**
     * Headers that must never be taken from the request body of a PSR-7 message
     * because PHP derives them itself and a client-supplied value would be
     * mistaken for a real one.
     */
    private const RESERVED_SERVER_KEYS = [
        'HTTP_CONTENT_LENGTH',
        'HTTP_CONTENT_TYPE',
    ];

    /**
     * Build the $_SERVER entries for a request.
     *
     * @param  array<string, list<string>> $headers
     * @param  array<string, mixed>        $serverParams
     * @return array<string, mixed>
     */
    public static function serverParams(
        string $method,
        string $scheme,
        string $host,
        ?int $port,
        string $path,
        string $query,
        array $headers,
        array $serverParams = []
    ): array {
        $isHttps = strtolower($scheme) === 'https';

        $server = [
            'REQUEST_METHOD' => strtoupper($method),
            'REQUEST_URI' => $path . ($query !== '' ? '?' . $query : ''),
            'QUERY_STRING' => $query,
            'HTTP_HOST' => $host,
            'SERVER_NAME' => $host,
            'SERVER_PORT' => (string) ($port ?? ($isHttps ? 443 : 80)),
            'HTTPS' => $isHttps ? 'on' : '',
            'REMOTE_ADDR' => (string) ($serverParams['REMOTE_ADDR'] ?? '127.0.0.1'),
            'REQUEST_TIME' => time(),
            'REQUEST_TIME_FLOAT' => microtime(true),
        ];

        foreach ($headers as $name => $values) {
            if (!is_string($name) || $name === '') {
                continue;
            }

            $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

            if (in_array($key, self::RESERVED_SERVER_KEYS, true)) {
                // PHP exposes these without the HTTP_ prefix; mirroring them keeps
                // Content-Type checks working the way they do under php-fpm.
                $server[substr($key, 5)] = implode(', ', (array) $values);
                continue;
            }

            $server[$key] = implode(', ', (array) $values);
        }

        return $server;
    }

    /**
     * Convert PSR-7 uploaded files into the native $_FILES shape.
     *
     * The previous bridge assigned the PSR-7 objects straight to $_FILES, so every
     * consumer — Components\Files, FileUploadGuard, custom_upload_helper — received
     * objects where it expected ['name', 'type', 'tmp_name', 'error', 'size'] and
     * uploads silently failed.
     *
     * @param  array<string, mixed> $uploadedFiles PSR-7 UploadedFileInterface tree
     * @param  callable(object):string $spool Writes a stream to a temp path
     * @return array<string, mixed>
     */
    public static function files(array $uploadedFiles, callable $spool): array
    {
        $normalized = [];

        foreach ($uploadedFiles as $field => $file) {
            if (is_array($file)) {
                $normalized[$field] = self::nestedFiles($file, $spool);
                continue;
            }

            $entry = self::singleFile($file, $spool);

            if ($entry !== null) {
                $normalized[$field] = $entry;
            }
        }

        return $normalized;
    }

    /**
     * PHP represents `name="docs[]"` as parallel arrays keyed by property, not as a
     * list of file entries. Rebuild that shape so array uploads behave natively.
     *
     * @param  array<array-key, mixed> $files
     * @return array<string, array<array-key, mixed>>
     */
    private static function nestedFiles(array $files, callable $spool): array
    {
        $result = [
            'name' => [],
            'type' => [],
            'tmp_name' => [],
            'error' => [],
            'size' => [],
        ];

        foreach ($files as $key => $file) {
            if (is_array($file)) {
                $child = self::nestedFiles($file, $spool);

                foreach ($result as $property => $_) {
                    $result[$property][$key] = $child[$property];
                }

                continue;
            }

            $entry = self::singleFile($file, $spool);

            if ($entry === null) {
                continue;
            }

            foreach ($result as $property => $_) {
                $result[$property][$key] = $entry[$property];
            }
        }

        return $result;
    }

    /**
     * @return array{name:string,type:string,tmp_name:string,error:int,size:int}|null
     */
    private static function singleFile(mixed $file, callable $spool): ?array
    {
        if (!is_object($file) || !method_exists($file, 'getError')) {
            return null;
        }

        $error = (int) $file->getError();

        // Only a successful upload has a readable stream to spool.
        $tmpName = '';
        if ($error === UPLOAD_ERR_OK) {
            try {
                $tmpName = (string) $spool($file);
            } catch (\Throwable) {
                $error = UPLOAD_ERR_CANT_WRITE;
                $tmpName = '';
            }
        }

        return [
            'name' => (string) (method_exists($file, 'getClientFilename') ? ($file->getClientFilename() ?? '') : ''),
            'type' => (string) (method_exists($file, 'getClientMediaType') ? ($file->getClientMediaType() ?? '') : ''),
            'tmp_name' => $tmpName,
            'error' => $error,
            'size' => (int) (method_exists($file, 'getSize') ? ($file->getSize() ?? 0) : 0),
        ];
    }
}
