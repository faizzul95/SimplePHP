<?php

declare(strict_types=1);

namespace Core\Security;

/**
 * Server-side file upload guard with MIME re-validation and out-of-webroot storage.
 *
 * Usage:
 *   $path = FileUploadGuard::store($_FILES['avatar'], 'avatars');
 *   // Returns: 'avatars/a3f1b2c4d5e6f7a8.jpg'
 *   // Stored at: ROOT_DIR/storage/uploads/avatars/a3f1b2c4d5e6f7a8.jpg
 *
 */
final class FileUploadGuard
{
    // Allowlist: extension => allowed real MIME types (detected by finfo — never $_FILES['type'])
    private const ALLOWED_TYPES = [
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'gif'  => ['image/gif'],
        'webp' => ['image/webp'],
        'pdf'  => ['application/pdf'],
        'csv'  => ['text/csv', 'text/plain', 'application/csv'],
        'txt'  => ['text/plain'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'xls'  => ['application/vnd.ms-excel'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'doc'  => ['application/msword'],
    ];

    // Extensions that must NEVER be stored regardless of claimed MIME
    private const BLOCKED_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar',
        'asp', 'aspx', 'jsp', 'cgi', 'pl', 'py', 'rb', 'sh', 'bash', 'zsh',
        'exe', 'bat', 'cmd', 'msi', 'dll', 'so', 'dylib',
        'htaccess', 'htpasswd', 'ini', 'config', 'conf',
        'svg', // SVG can embed JavaScript — disallow unless your CSP handles it
    ];

    /**
     * Extensions whose bytes must actually decode as the image they claim to be.
     *
     * finfo reads magic bytes at the head of the file, which a polyglot satisfies
     * while carrying something else in its tail. getimagesize() has to parse real
     * dimensions out of the container, which is a materially harder thing to fake.
     */
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    /** Default ceiling when the caller does not set one, in bytes (8 MB). */
    private const DEFAULT_MAX_BYTES = 8388608;

    /**
     * Decompressed pixel ceiling.
     *
     * A "decompression bomb" is a tiny file describing an enormous canvas: a
     * 50,000 x 50,000 PNG is a few KB on disk and roughly 10 GB once GD expands
     * it, which takes the process down before any size check on the file itself
     * would fire.
     */
    private const MAX_IMAGE_PIXELS = 24000000;

    private const UPLOAD_BASE = ROOT_DIR . 'storage/uploads/';

    /**
     * Validate and move an uploaded file to out-of-webroot storage.
     * Returns the relative storage path on success (subdir/randomhex.ext).
     *
     * @param array  $file      One entry from $_FILES (e.g. $_FILES['avatar'])
     * @param string $subdir    Sub-directory under storage/uploads/ (e.g. 'avatars')
     * @param int|null $maxBytes Size ceiling; null uses DEFAULT_MAX_BYTES
     * @return string           Relative path: 'avatars/a3f1b2c4d5e6f7a8.jpg'
     * @throws \RuntimeException on any validation failure
     */
    public static function store(array $file, string $subdir = 'general', ?int $maxBytes = null): string
    {
        // 1. Validate upload array structure
        if (!isset($file['tmp_name'], $file['name'], $file['error'])) {
            throw new \RuntimeException('Invalid file upload array.');
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new \RuntimeException(self::describeUploadError((int) $file['error']));
        }

        $tmpName = (string) $file['tmp_name'];

        if ($tmpName === '' || !is_file($tmpName) || !is_readable($tmpName)) {
            throw new \RuntimeException('Uploaded file is missing or unreadable.');
        }

        // Under a worker SAPI the request arrives as PSR-7 and PsrRequestBridge
        // spools each upload to a temp file, which is_uploaded_file() rejects
        // because the SAPI never registered it. Requiring it unconditionally would
        // make every worker-mode upload fail the same way it used to fail for a
        // different reason. The path is still constrained to a real temp directory
        // below, which is the property that actually matters.
        if (!self::isAcceptableUploadSource($tmpName)) {
            throw new \RuntimeException('File is not a valid upload source.');
        }

        // 2. Enforce a size ceiling before touching the contents. php.ini's
        //    upload_max_filesize is a server-wide backstop, not a per-route policy.
        $maxBytes = $maxBytes ?? self::DEFAULT_MAX_BYTES;
        $size = @filesize($tmpName);

        if ($size === false) {
            throw new \RuntimeException('Could not determine the uploaded file size.');
        }

        if ($size <= 0) {
            throw new \RuntimeException('Uploaded file is empty.');
        }

        if ($size > $maxBytes) {
            throw new \RuntimeException(sprintf(
                'File is %s; the limit is %s.',
                self::formatBytes($size),
                self::formatBytes($maxBytes)
            ));
        }

        // 3. Strip null bytes from filename
        $originalName = str_replace("\x00", '', (string) $file['name']);

        // 4. Block dangerous extensions — including double-extension bypass (image.php.jpg)
        $parts = explode('.', $originalName);
        foreach ($parts as $part) {
            if (in_array(strtolower($part), self::BLOCKED_EXTENSIONS, true)) {
                throw new \RuntimeException("Blocked file extension detected: {$part}");
            }
        }

        // 5. Extract extension and check allowlist
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!array_key_exists($extension, self::ALLOWED_TYPES)) {
            throw new \RuntimeException("Extension not permitted: .{$extension}");
        }

        // 6. Server-side MIME re-validation via finfo (NEVER trust $_FILES['type'])
        $finfo    = new \finfo(FILEINFO_MIME_TYPE);
        $realMime = $finfo->file($tmpName);

        if ($realMime === false) {
            throw new \RuntimeException('Could not determine real MIME type.');
        }

        if (!in_array($realMime, self::ALLOWED_TYPES[$extension], true)) {
            throw new \RuntimeException(
                "MIME mismatch: extension=.{$extension} detected_mime={$realMime}"
            );
        }

        // 7. For images, require the bytes to decode as an image of that type.
        //     finfo only reads the magic bytes at the head of the file, which a
        //     polyglot satisfies while carrying a payload in its tail.
        if (in_array($extension, self::IMAGE_EXTENSIONS, true)) {
            self::assertDecodableImage($tmpName, $extension);
        }

        // 8. Generate a random, non-guessable, non-sequential filename
        $storedName = bin2hex(random_bytes(16)) . '.' . $extension;

        // 9. Resolve destination — MUST be outside web root
        $subdir  = ltrim(preg_replace('/[^a-zA-Z0-9_\-\/]/', '', $subdir), '/');
        $destDir = rtrim(self::UPLOAD_BASE . $subdir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        if (!is_dir($destDir) && !mkdir($destDir, 0750, true) && !is_dir($destDir)) {
            throw new \RuntimeException("Failed to create upload directory: {$destDir}");
        }

        // 10. Drop .htaccess deny-all in every upload directory (Apache safety net)
        $htaccess = $destDir . '.htaccess';
        if (!file_exists($htaccess)) {
            @file_put_contents($htaccess, "Deny from all\n", LOCK_EX);
        }

        $destPath = $destDir . $storedName;

        if (!self::moveIntoStorage($tmpName, $destPath)) {
            throw new \RuntimeException("Failed to move uploaded file to: {$destPath}");
        }

        return $subdir . '/' . $storedName;
    }

    /**
     * Whether the temp path is somewhere an upload could legitimately have landed.
     *
     * Prefers move_uploaded_file()'s own registry when the SAPI populated it; falls
     * back to requiring the file to sit inside a known temp directory, which is
     * what a PSR-7 worker bridge produces. The point is to reject an arbitrary
     * caller-supplied path like /etc/passwd, not to insist on one specific
     * provenance.
     */
    private static function isAcceptableUploadSource(string $tmpName): bool
    {
        if (is_uploaded_file($tmpName)) {
            return true;
        }

        $real = realpath($tmpName);

        if ($real === false) {
            return false;
        }

        $candidates = [sys_get_temp_dir(), (string) ini_get('upload_tmp_dir')];
        $tempDirs = [];

        foreach ($candidates as $candidate) {
            // realpath('') returns the CURRENT WORKING DIRECTORY, so an unset
            // upload_tmp_dir would silently make the project root an accepted
            // upload source — turning a provenance check into no check at all.
            if (trim($candidate) === '') {
                continue;
            }

            $resolved = realpath($candidate);

            if ($resolved !== false) {
                $tempDirs[] = rtrim($resolved, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            }
        }

        foreach ($tempDirs as $dir) {
            if (str_starts_with($real, $dir)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Move the file, using the SAPI-aware call when it applies.
     *
     * move_uploaded_file() refuses a path it did not register, so a spooled
     * worker upload needs rename() instead. rename() fails across filesystems,
     * hence the copy fallback.
     */
    private static function moveIntoStorage(string $tmpName, string $destPath): bool
    {
        if (is_uploaded_file($tmpName)) {
            return move_uploaded_file($tmpName, $destPath);
        }

        if (@rename($tmpName, $destPath)) {
            return true;
        }

        if (@copy($tmpName, $destPath)) {
            @unlink($tmpName);

            return true;
        }

        return false;
    }

    /**
     * Reject anything that does not parse as a real image of the claimed type,
     * and anything whose canvas would exhaust memory on decode.
     */
    private static function assertDecodableImage(string $tmpName, string $extension): void
    {
        $info = @getimagesize($tmpName);

        if ($info === false) {
            throw new \RuntimeException('File claims to be an image but does not decode as one.');
        }

        [$width, $height] = $info;
        $detectedType = $info[2] ?? null;

        $expected = match ($extension) {
            'jpg', 'jpeg' => IMAGETYPE_JPEG,
            'png' => IMAGETYPE_PNG,
            'gif' => IMAGETYPE_GIF,
            'webp' => defined('IMAGETYPE_WEBP') ? IMAGETYPE_WEBP : null,
            default => null,
        };

        if ($expected !== null && $detectedType !== $expected) {
            throw new \RuntimeException('Image contents do not match the .' . $extension . ' extension.');
        }

        if ($width < 1 || $height < 1) {
            throw new \RuntimeException('Image reports zero dimensions.');
        }

        // Guard the decoded size, not the compressed one: a 50,000 x 50,000 PNG is
        // a few KB on disk and roughly 10 GB once expanded.
        if (($width * $height) > self::MAX_IMAGE_PIXELS) {
            throw new \RuntimeException(sprintf(
                'Image is %dx%d (%s pixels); the limit is %s.',
                $width,
                $height,
                number_format($width * $height),
                number_format(self::MAX_IMAGE_PIXELS)
            ));
        }
    }

    /** Turn a PHP upload error constant into something a user can act on. */
    private static function describeUploadError(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE => 'File exceeds the server upload_max_filesize limit.',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds the form MAX_FILE_SIZE limit.',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded; the connection may have dropped.',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server has no temporary directory configured for uploads.',
            UPLOAD_ERR_CANT_WRITE => 'Server could not write the uploaded file to disk.',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the upload.',
            default => 'Upload failed with error code ' . $code . '.',
        };
    }

    private static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }

        if ($bytes >= 1024) {
            return round($bytes / 1024) . ' KB';
        }

        return $bytes . ' bytes';
    }

    /**
     * Serve a stored private file via readfile() with correct Content-Type.
     * Use this instead of exposing the storage path in a public URL.
     *
     * Example route:
     *   Route::get('/files/{path}', fn($path) => FileUploadGuard::serve($path))
     *        ->middleware('auth')
     *        ->where('path', '.+');
     */
    public static function serve(string $relativePath): never
    {
        $fullPath = self::UPLOAD_BASE . $relativePath;
        $realPath = realpath($fullPath);
        $realBase = realpath(self::UPLOAD_BASE);

        // Prevent path traversal
        if ($realPath === false || $realBase === false || !str_starts_with($realPath, $realBase)) {
            \Core\Http\Abort::text('Forbidden', 403);
        }

        if (!is_file($realPath)) {
            \Core\Http\Abort::text('Not Found', 404);
        }

        $finfo    = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($realPath) ?: 'application/octet-stream';

        // Force download for anything other than common image types to prevent
        // active content execution inside browser document viewers.
        $inlineTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $disposition = in_array($mimeType, $inlineTypes, true) ? 'inline' : 'attachment';

        $safeFilename = str_replace(["\r", "\n", "\0", '"'], '', basename($realPath));

        $headers = [
            'Content-Type' => $mimeType,
            'Content-Disposition' => $disposition . '; filename="' . $safeFilename . '"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
        ];

        // filesize() returns int|false; guard against false to prevent a
        // malformed Content-Length header (e.g. file deleted mid-serve).
        $fileSize = filesize($realPath);
        if ($fileSize !== false) {
            $headers['Content-Length'] = (string) $fileSize;
        }

        // Streamed rather than read into memory, and thrown rather than exited so
        // the middleware stack unwinds and a worker survives the download.
        \Core\Http\Abort::response(new \Core\Http\StreamedResponse(
            static function () use ($realPath): void {
                readfile($realPath);
            },
            200,
            $headers
        ));
    }

    /**
     * Check whether a relative path is within the upload base (path traversal guard).
     * Returns false if the file does not exist (realpath requires the path to resolve).
     */
    public static function isSafePath(string $relativePath): bool
    {
        $fullPath = self::UPLOAD_BASE . $relativePath;
        $realPath = realpath($fullPath);
        $realBase = realpath(self::UPLOAD_BASE);

        return $realPath !== false && $realBase !== false && str_starts_with($realPath, $realBase);
    }

    /**
     * Delete a stored file. Returns true on success or if the file did not exist.
     *
     * @throws \RuntimeException on path traversal attempt
     */
    public static function delete(string $relativePath): bool
    {
        // Reject obvious traversal patterns before realpath (handles non-existent paths too)
        if (str_contains($relativePath, '..') || str_contains($relativePath, "\x00")) {
            throw new \RuntimeException('Path traversal detected.');
        }

        $fullPath = self::UPLOAD_BASE . ltrim($relativePath, '/');

        // If file exists, verify it resolves inside the upload base
        if (file_exists($fullPath) && !self::isSafePath($relativePath)) {
            throw new \RuntimeException('Path traversal detected.');
        }

        if (!file_exists($fullPath)) {
            return true;
        }

        return @unlink($fullPath);
    }
}
