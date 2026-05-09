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

    private const UPLOAD_BASE = ROOT_DIR . 'storage/uploads/';

    /**
     * Validate and move an uploaded file to out-of-webroot storage.
     * Returns the relative storage path on success (subdir/randomhex.ext).
     *
     * @param array  $file   One entry from $_FILES (e.g. $_FILES['avatar'])
     * @param string $subdir Sub-directory under storage/uploads/ (e.g. 'avatars')
     * @return string        Relative path: 'avatars/a3f1b2c4d5e6f7a8.jpg'
     * @throws \RuntimeException on any validation failure
     */
    public static function store(array $file, string $subdir = 'general'): string
    {
        // 1. Validate upload array structure
        if (!isset($file['tmp_name'], $file['name'], $file['error'])) {
            throw new \RuntimeException('Invalid file upload array.');
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Upload error code: ' . $file['error']);
        }

        if (!is_uploaded_file($file['tmp_name'])) {
            throw new \RuntimeException('File is not a valid PHP upload.');
        }

        // 2. Strip null bytes from filename
        $originalName = str_replace("\x00", '', (string) $file['name']);

        // 3. Block dangerous extensions — including double-extension bypass (image.php.jpg)
        $parts = explode('.', $originalName);
        foreach ($parts as $part) {
            if (in_array(strtolower($part), self::BLOCKED_EXTENSIONS, true)) {
                throw new \RuntimeException("Blocked file extension detected: {$part}");
            }
        }

        // 4. Extract extension and check allowlist
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!array_key_exists($extension, self::ALLOWED_TYPES)) {
            throw new \RuntimeException("Extension not permitted: .{$extension}");
        }

        // 5. Server-side MIME re-validation via finfo (NEVER trust $_FILES['type'])
        $finfo    = new \finfo(FILEINFO_MIME_TYPE);
        $realMime = $finfo->file($file['tmp_name']);

        if ($realMime === false) {
            throw new \RuntimeException('Could not determine real MIME type.');
        }

        if (!in_array($realMime, self::ALLOWED_TYPES[$extension], true)) {
            throw new \RuntimeException(
                "MIME mismatch: extension=.{$extension} detected_mime={$realMime}"
            );
        }

        // 6. Generate a random, non-guessable, non-sequential filename
        $storedName = bin2hex(random_bytes(16)) . '.' . $extension;

        // 7. Resolve destination — MUST be outside web root
        $subdir  = ltrim(preg_replace('/[^a-zA-Z0-9_\-\/]/', '', $subdir), '/');
        $destDir = rtrim(self::UPLOAD_BASE . $subdir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        if (!is_dir($destDir) && !mkdir($destDir, 0750, true) && !is_dir($destDir)) {
            throw new \RuntimeException("Failed to create upload directory: {$destDir}");
        }

        // 8. Drop .htaccess deny-all in every upload directory (Apache safety net)
        $htaccess = $destDir . '.htaccess';
        if (!file_exists($htaccess)) {
            @file_put_contents($htaccess, "Deny from all\n", LOCK_EX);
        }

        $destPath = $destDir . $storedName;

        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            throw new \RuntimeException("Failed to move uploaded file to: {$destPath}");
        }

        return $subdir . '/' . $storedName;
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
            http_response_code(403);
            exit('Forbidden');
        }

        if (!is_file($realPath)) {
            http_response_code(404);
            exit('Not Found');
        }

        $finfo    = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($realPath) ?: 'application/octet-stream';

        // Force download for non-image/non-PDF types to prevent browser execution
        $inlineTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];
        $disposition = in_array($mimeType, $inlineTypes, true) ? 'inline' : 'attachment';

        $safeFilename = str_replace(["\r", "\n", "\0", '"'], '', basename($realPath));
        header('Content-Type: ' . $mimeType);
        header('Content-Length: ' . filesize($realPath));
        header('Content-Disposition: ' . $disposition . '; filename="' . $safeFilename . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=3600');

        readfile($realPath);
        exit;
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
