<?php

namespace Components;

class Files
{
    private string $path;

    private Security $security;

    private ?string $storageDisk = null;

    private string $uploadDir = 'public/upload';

    private int $directoryPermission = 0775;

    private int $maxFileSize = 4;

    private string $allowedMimeTypes = 'image/jpeg, image/png, application/pdf';

    private int $maxImageWidth = 6000;

    private int $maxImageHeight = 6000;

    private int $maxImagePixels = 24000000;

    private array $mimeToExtension = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
        'text/plain' => 'txt',
        'text/csv' => 'csv',
        'application/json' => 'json',
        'application/zip' => 'zip',
    ];

    /**
     * Bootstrap the upload component against the project root.
     */
    public function __construct()
    {
        $root = defined('ROOT_DIR') ? ROOT_DIR : dirname(__DIR__, 2);
        $this->path = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $root), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $this->security = new Security();
    }

    /**
     * Configure the project-relative upload directory and ensure it exists.
     */
    public function setUploadDir(string $uploadDir, ?int $permission = 0775): void
    {
        $this->uploadDir = $this->normalizeRelativePath($uploadDir);
        $this->directoryPermission = $permission ?? 0775;
        $this->createFolder($this->directoryPermission);
    }

    /**
     * Route final persistence through a managed storage disk instead of direct project paths.
     */
    public function setStorageDisk(?string $disk, ?string $prefix = null): void
    {
        $disk = $disk !== null ? trim($disk) : null;
        $this->storageDisk = $disk !== '' ? $disk : null;

        if ($prefix !== null) {
            $this->uploadDir = $this->normalizeRelativePath($prefix);
        }

        $this->createFolder($this->directoryPermission);
    }

    /**
     * Set the maximum accepted file size in megabytes.
     */
    public function setMaxFileSize(int $maxFileSize): void
    {
        if ($maxFileSize < 1) {
            throw new \InvalidArgumentException('Maximum file size must be at least 1 MB.');
        }

        $this->maxFileSize = $maxFileSize;
    }

    /**
     * Set the allowed MIME types as a comma-separated list or '*'.
     */
    public function setAllowedMimeTypes(string $allowedMimeTypes): void
    {
        if (trim($allowedMimeTypes) === '') {
            throw new \InvalidArgumentException('Allowed MIME types must not be empty.');
        }

        $this->allowedMimeTypes = $allowedMimeTypes;
    }

    /**
     * Guard image uploads by maximum width, height, and total pixels.
     */
    public function setImageLimits(int $maxWidth, int $maxHeight, int $maxPixels = 24000000): void
    {
        if ($maxWidth < 1 || $maxHeight < 1 || $maxPixels < 1) {
            throw new \InvalidArgumentException('Image limits must be positive integers.');
        }

        $this->maxImageWidth = $maxWidth;
        $this->maxImageHeight = $maxHeight;
        $this->maxImagePixels = $maxPixels;
    }

    /**
     * Upload a single file or delegate a browser multi-file payload to bulk processing.
     *
     * @param array<string, mixed> $file
     * @param array<string, mixed> $options
     */
    public function upload(array $file, array $options = []): array
    {
        if ($this->isMultiUploadPayload($file)) {
            return $this->uploadMany($file, $options);
        }

        return $this->uploadOne($file, $options);
    }

    /**
     * Upload many files sequentially to keep memory usage bounded.
     *
     * Accepts either a raw multi-file $_FILES payload or a list of normalized file arrays.
     *
     * @param array<string, mixed>|array<int, array<string, mixed>> $files
     * @param array<string, mixed> $options
     */
    public function uploadMany(array $files, array $options = []): array
    {
        $items = [];
        $successCount = 0;
        $failureCount = 0;

        foreach ($this->normalizeUploadBatch($files) as $file) {
            $result = $this->uploadOne($file, $options);
            $items[] = $result;

            if (($result['isUpload'] ?? false) === true) {
                $successCount++;
            } else {
                $failureCount++;
            }

            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }

        $total = $successCount + $failureCount;
        $code = $successCount === 0 ? 400 : ($failureCount === 0 ? 200 : 207);

        return [
            'code' => $code,
            'message' => "Processed {$total} file(s): {$successCount} uploaded, {$failureCount} failed.",
            'files' => [],
            'items' => $items,
            'success_count' => $successCount,
            'failure_count' => $failureCount,
            'isUpload' => $successCount > 0,
        ];
    }

    /**
     * Upload a single normalized file array.
     *
     * @param array<string, mixed> $file
     * @param array<string, mixed> $options
     */
    private function uploadOne(array $file, array $options = []): array
    {
        $response = $this->createResponse(is_string($file['name'] ?? null) ? (string) $file['name'] : '');

        try {
            $this->createFolder();
            $this->assertValidUploadArray($file);
            $this->assertUploadedFile((string) $file['tmp_name']);

            if (($file['size'] ?? 0) > $this->maxFileSizeInBytes()) {
                return $this->errorResponse(
                    "Sorry, your file exceeds the maximum file size of {$this->maxFileSize}MB.",
                    $response['files']
                );
            }

            $analysis = $this->analyzeFile((string) $file['tmp_name'], (string) $file['name'], false, null, $options);
            $stored = $this->storeAnalyzedFile((string) $file['tmp_name'], $analysis, $options, true);

            return $this->successResponse($stored);
        } catch (\Throwable $e) {
            return $this->errorResponse($e->getMessage(), $response['files']);
        }
    }

    /**
     * Upload a base64 data-URL image safely.
     *
     * @param array<string, mixed> $options
     */
    public function uploadBase64Image(string $base64Image, array $options = []): array
    {
        return $this->uploadBase64ImageInternal($base64Image, $options);
    }

    /**
     * Upload many base64 data-URL images sequentially.
     *
     * @param array<int, string> $images
     * @param array<string, mixed> $options
     */
    public function uploadBase64Images(array $images, array $options = []): array
    {
        $items = [];
        $successCount = 0;
        $failureCount = 0;

        foreach (array_values($images) as $index => $image) {
            $imageOptions = $options;
            if (!isset($imageOptions['original_name']) || $imageOptions['original_name'] === '') {
                $imageOptions['original_name'] = 'base64-image-' . ($index + 1);
            }

            $result = $this->uploadBase64ImageInternal((string) $image, $imageOptions);
            $items[] = $result;

            if (($result['isUpload'] ?? false) === true) {
                $successCount++;
            } else {
                $failureCount++;
            }

            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }

        $total = $successCount + $failureCount;
        $code = $successCount === 0 ? 400 : ($failureCount === 0 ? 200 : 207);

        return [
            'code' => $code,
            'message' => "Processed {$total} base64 image(s): {$successCount} uploaded, {$failureCount} failed.",
            'files' => [],
            'items' => $items,
            'success_count' => $successCount,
            'failure_count' => $failureCount,
            'isUpload' => $successCount > 0,
        ];
    }

    /**
     * Store an existing local file through the same validation and storage pipeline.
     *
     * @param array<string, mixed> $options
     */
    public function storeFile(string $sourcePath, string $originalName, array $options = []): array
    {
        $response = $this->createResponse($originalName);

        try {
            $this->createFolder();

            if (!$this->security->canReadPath($sourcePath)) {
                throw new \RuntimeException('Source file is not readable.');
            }

            $analysis = $this->analyzeFile($sourcePath, $originalName, false, null, $options);
            $workingPath = $sourcePath;

            if (($options['preserve_source'] ?? false) === true) {
                $temporaryPath = $this->createTemporaryFile();
                if (!@copy($sourcePath, $temporaryPath)) {
                    @unlink($temporaryPath);
                    throw new \RuntimeException('Failed to copy source file for storage.');
                }

                $workingPath = $temporaryPath;
            }

            try {
                $stored = $this->storeAnalyzedFile($workingPath, $analysis, $options, false);
            } finally {
                if ($workingPath !== $sourcePath && is_file($workingPath)) {
                    @unlink($workingPath);
                }
            }

            return $this->successResponse($stored);
        } catch (\Throwable $e) {
            return $this->errorResponse($e->getMessage(), $response['files']);
        }
    }

    /**
     * Decode a generic base64 data URL without storing it.
     *
     * @return array{status: bool, data: ?string, mime_type: ?string, extension: ?string, error: ?string}
     */
    public function decodeBase64DataUrl(string $base64DataUrl): array
    {
        try {
            $base64DataUrl = trim($base64DataUrl);
            if (!str_starts_with($base64DataUrl, 'data:')) {
                throw new \RuntimeException('Invalid file format');
            }

            $separatorPos = strpos($base64DataUrl, ',');
            if ($separatorPos === false) {
                throw new \RuntimeException('Invalid file format');
            }

            $metadata = substr($base64DataUrl, 5, $separatorPos - 5);
            if (!preg_match('/^([a-zA-Z0-9.+\/-]+);base64$/', $metadata, $matches)) {
                throw new \RuntimeException('Invalid file format');
            }

            $mimeType = strtolower(trim($matches[1]));
            if ($this->security->isBlockedUploadMimeType($mimeType)) {
                throw new \RuntimeException('This file type is not allowed for decoding.');
            }

            $payload = substr($base64DataUrl, $separatorPos + 1);
            $payload = preg_replace('/\s+/', '', $payload) ?? '';
            if ($payload === '' || preg_match('/[^A-Za-z0-9+\/=]/', $payload)) {
                throw new \RuntimeException('Failed to decode file');
            }

            $decodedData = base64_decode($payload, true);
            if ($decodedData === false) {
                throw new \RuntimeException('Failed to decode file');
            }

            return [
                'status' => true,
                'data' => $decodedData,
                'mime_type' => $mimeType,
                'extension' => $this->mimeToExtension[$mimeType] ?? $this->extensionFromMimeType($mimeType),
                'error' => null,
            ];
        } catch (\Throwable $e) {
            return [
                'status' => false,
                'data' => null,
                'mime_type' => null,
                'extension' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Internal single base64 image upload handler used by both single and bulk flows.
     *
     * @param array<string, mixed> $options
     */
    private function uploadBase64ImageInternal(string $base64Image, array $options = []): array
    {
        $response = $this->createResponse($options['original_name'] ?? 'base64-image');
        $tempFile = null;

        try {
            $this->createFolder();
            $parsed = $this->parseBase64Image($base64Image);
            $this->assertMimeAllowed($parsed['mime']);

            $tempFile = $this->createTemporaryFile();
            $this->decodeBase64ToFile($parsed['payload'], $tempFile);

            $analysis = $this->analyzeFile(
                $tempFile,
                $options['original_name'] ?? ('base64-image.' . $parsed['extension']),
                true,
                $parsed['mime'],
                $options
            );

            $stored = $this->storeAnalyzedFile($tempFile, $analysis, $options, false);

            return $this->successResponse($stored);
        } catch (\Throwable $e) {
            return $this->errorResponse($e->getMessage(), $response['files']);
        } finally {
            if (is_string($tempFile) && $tempFile !== '' && file_exists($tempFile)) {
                @unlink($tempFile);
            }
        }
    }

    /**
     * Determine whether the payload is a browser multi-file upload structure.
     *
     * @param array<string, mixed> $file
     */
    private function isMultiUploadPayload(array $file): bool
    {
        return isset($file['name']) && is_array($file['name']);
    }

    /**
     * Normalize bulk upload input into a flat list of single upload arrays.
     *
     * @param array<string, mixed>|array<int, array<string, mixed>> $files
     * @return array<int, array<string, mixed>>
     */
    private function normalizeUploadBatch(array $files): array
    {
        if (isset($files['name'])) {
            return $this->flattenUploadEntries($files);
        }

        $normalized = [];
        foreach ($files as $file) {
            if (!is_array($file)) {
                throw new \InvalidArgumentException('Bulk upload payload contains an invalid file entry.');
            }

            $normalized[] = $file;
        }

        return $normalized;
    }

    /**
     * Flatten a nested $_FILES structure into normalized single-file entries.
     *
     * @param array<string, mixed> $file
     * @return array<int, array<string, mixed>>
     */
    private function flattenUploadEntries(array $file): array
    {
        if (!isset($file['name']) || !is_array($file['name'])) {
            return [$file];
        }

        $entries = [];
        foreach (array_keys($file['name']) as $index) {
            $entry = [
                'name' => $file['name'][$index] ?? null,
                'type' => $file['type'][$index] ?? null,
                'tmp_name' => $file['tmp_name'][$index] ?? null,
                'error' => $file['error'][$index] ?? null,
                'size' => $file['size'][$index] ?? null,
            ];

            if (is_array($entry['name'])) {
                $entries = array_merge($entries, $this->flattenUploadEntries($entry));
                continue;
            }

            $entries[] = $entry;
        }

        return $entries;
    }

    /**
     * Persist an analyzed file into the configured upload directory.
     *
     * @param array<string, mixed> $analysis
     * @param array<string, mixed> $options
     */
    private function storeAnalyzedFile(string $sourcePath, array $analysis, array $options, bool $isUploadedFile): array
    {
        $storage = $this->configuredStorageAdapter();
        if ($storage !== null) {
            return $this->storeAnalyzedFileToStorage($sourcePath, $analysis, $options, $storage);
        }

        $compressionMode = $this->normalizeCompressionMode((int) ($options['file_compression'] ?? 1));
        $shouldCompress = (bool) ($options['compress'] ?? false);

        $targetDir = $this->absoluteUploadDir();
        $relativeFolder = $this->uploadDir;
        [$baseName, $fileName, $relativePath, $targetFile] = $this->reserveTargetPath($targetDir, $relativeFolder, (string) $analysis['extension']);

        try {
            if ($analysis['is_image']) {
                $this->storeImageVariants($sourcePath, $targetDir, $baseName, $analysis['mime'], $shouldCompress, $compressionMode);
            } else {
                if ($isUploadedFile) {
                    if (!move_uploaded_file($sourcePath, $targetFile)) {
                        throw new \RuntimeException('Sorry, there was an error uploading your file.');
                    }
                } elseif (!@rename($sourcePath, $targetFile)) {
                    if (!@copy($sourcePath, $targetFile)) {
                        throw new \RuntimeException('Failed to move decoded file to storage.');
                    }

                    @unlink($sourcePath);
                }

                @chmod($targetFile, 0644);
            }
        } catch (\Throwable $e) {
            $this->deleteStoredArtifacts($targetDir, $baseName, $analysis['extension']);
            throw $e;
        }

        clearstatcache(true, $targetFile);
        $effectiveCompression = $analysis['is_image'] && $shouldCompress ? $compressionMode : 1;

        return [
            'original_name' => htmlspecialchars($analysis['original_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            'name' => htmlspecialchars($fileName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            'size' => $this->calculateStoredSize($targetDir, $baseName, $analysis['extension'], $effectiveCompression),
            'path' => $targetFile,
            'relative_path' => $relativePath,
            'folder' => $targetDir,
            'relative_folder' => $relativeFolder,
            'disk' => $this->storageDisk,
            'url' => null,
            'mime' => $analysis['mime'],
            'extension' => $analysis['extension'],
            'compression' => $effectiveCompression,
            'width' => $analysis['width'],
            'height' => $analysis['height'],
            'content_scan' => $analysis['content_scan'],
        ];
    }

    private function storeAnalyzedFileToStorage(string $sourcePath, array $analysis, array $options, \Core\Filesystem\FilesystemAdapterInterface $storage): array
    {
        $compressionMode = $this->normalizeCompressionMode((int) ($options['file_compression'] ?? 1));
        $shouldCompress = (bool) ($options['compress'] ?? false);
        $relativeFolder = $this->uploadDir;
        [$baseName, $fileName, $relativePath] = $this->reserveStoragePath($storage, $relativeFolder, (string) $analysis['extension']);

        try {
            if ($analysis['is_image']) {
                $storedSize = $this->storeImageVariantsToStorage($sourcePath, $storage, $relativeFolder, $baseName, (string) $analysis['mime'], $shouldCompress, $compressionMode);
            } else {
                $storedSize = $this->writePathToStorage($storage, $relativePath, $sourcePath);
            }
        } catch (\Throwable $e) {
            $this->deleteStorageArtifacts($storage, $relativeFolder, $baseName, (string) $analysis['extension']);
            throw $e;
        }

        $effectiveCompression = $analysis['is_image'] && $shouldCompress ? $compressionMode : 1;

        return [
            'original_name' => htmlspecialchars($analysis['original_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            'name' => htmlspecialchars($fileName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            'size' => $storedSize,
            'path' => $storage->path($relativePath),
            'relative_path' => $relativePath,
            'folder' => $storage->path($relativeFolder),
            'relative_folder' => $relativeFolder,
            'disk' => $this->storageDisk,
            'url' => $storage->url($relativePath),
            'mime' => $analysis['mime'],
            'extension' => $analysis['extension'],
            'compression' => $effectiveCompression,
            'width' => $analysis['width'],
            'height' => $analysis['height'],
            'content_scan' => $analysis['content_scan'],
        ];
    }

    /**
     * Build the default upload response payload.
     */
    private function createResponse(string $originalName = ''): array
    {
        return [
            'code' => 400,
            'message' => '',
            'files' => [
                'original_name' => htmlspecialchars($originalName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                'name' => '',
                'size' => null,
                'path' => '',
                'relative_path' => '',
                'folder' => $this->absoluteUploadDir(),
                'relative_folder' => $this->uploadDir,
                'disk' => $this->storageDisk,
                'url' => null,
                'mime' => null,
                'extension' => null,
                'compression' => 1,
                'content_scan' => null,
            ],
            'isUpload' => false,
        ];
    }

    /**
     * Build a failed upload response using the framework upload response shape.
     *
     * @param array<string, mixed> $files
     */
    private function errorResponse(string $message, array $files): array
    {
        return [
            'code' => 400,
            'message' => $message,
            'files' => $files,
            'isUpload' => false,
        ];
    }

    /**
     * Build a successful upload response using the framework upload response shape.
     *
     * @param array<string, mixed> $files
     */
    private function successResponse(array $files): array
    {
        return [
            'code' => 200,
            'message' => 'The file has been uploaded',
            'files' => $files,
            'isUpload' => true,
        ];
    }

    /**
     * Validate the required keys and PHP upload status for a normalized upload array.
     *
     * @param array<string, mixed> $file
     */
    private function assertValidUploadArray(array $file): void
    {
        foreach (['name', 'tmp_name', 'size', 'error'] as $key) {
            if (!array_key_exists($key, $file)) {
                throw new \InvalidArgumentException("Invalid upload payload: missing {$key}.");
            }
        }

        if ((int) $file['error'] !== UPLOAD_ERR_OK) {
            if ((int) $file['error'] === UPLOAD_ERR_INI_SIZE) {
                throw new \RuntimeException('The uploaded file exceeds the maximum file size allowed by PHP.');
            }

            throw new \RuntimeException('File upload error: ' . $this->fileUploadErrorMessage((int) $file['error']));
        }
    }

    /**
     * Ensure the upload source is a real PHP-uploaded temporary file.
     */
    private function assertUploadedFile(string $tmpName): void
    {
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            throw new \RuntimeException('Invalid uploaded file source.');
        }
    }

    /**
     * Analyze file metadata and optionally stream-scan text-like documents for unsafe content.
     *
     * @param array<string, mixed> $options
     */
    private function analyzeFile(string $path, string $originalName, bool $imageOnly = false, ?string $declaredMime = null, array $options = []): array
    {
        if (!is_file($path) || !$this->security->canReadPath($path)) {
            throw new \RuntimeException('Uploaded file is not readable.');
        }

        $normalizedOriginalName = basename($originalName);
        if (!$this->security->isSafeUploadFilename($normalizedOriginalName)) {
            throw new \RuntimeException('Uploaded file name is not allowed.');
        }

        if (is_link($path)) {
            throw new \RuntimeException('Linked file inputs are not allowed.');
        }

        $size = filesize($path);
        if ($size === false || $size < 1) {
            throw new \RuntimeException('Uploaded file is empty or unreadable.');
        }

        if ($size > $this->maxFileSizeInBytes()) {
            throw new \RuntimeException("Sorry, your file exceeds the maximum file size of {$this->maxFileSize}MB.");
        }

        $mime = $this->detectMimeType($path);
        if ($declaredMime !== null && !$this->mimeMatches($declaredMime, $mime)) {
            throw new \RuntimeException('Declared file type does not match the actual file content.');
        }

        if ($this->security->isBlockedUploadMimeType($mime)) {
            throw new \RuntimeException('This file type is not allowed for upload storage.');
        }

        $this->assertMimeAllowed($mime);

        $extension = $this->mimeToExtension[$mime] ?? null;
        if ($extension === null) {
            throw new \RuntimeException('Unsupported file type.');
        }

        if ($this->security->isBlockedUploadExtension($extension)) {
            throw new \RuntimeException("Sorry, files with the .{$extension} extension are not allowed.");
        }

        $isImage = str_starts_with($mime, 'image/');
        if ($imageOnly && !$isImage) {
            throw new \RuntimeException('Only image uploads are allowed for this operation.');
        }

        [$width, $height] = [null, null];
        if ($isImage) {
            [$width, $height] = $this->assertValidImage($path, $mime);
        }

        $validateContent = array_key_exists('validate_content', $options)
            ? (bool) $options['validate_content']
            : true;

        $contentScan = null;
        if (!$isImage && $validateContent) {
            $contentScan = $this->inspectDocumentContent($path, $mime, $options['content_validation'] ?? []);

            if (($contentScan['issue_count'] ?? 0) > 0 && (($options['reject_unsafe_content'] ?? true) === true)) {
                throw new \RuntimeException('Uploaded document contains unsafe content and was rejected.');
            }
        }

        return [
            'original_name' => $normalizedOriginalName,
            'mime' => $mime,
            'extension' => $extension,
            'size' => $size,
            'is_image' => $isImage,
            'width' => $width,
            'height' => $height,
            'content_scan' => $contentScan,
        ];
    }

    /**
     * Stream-scan supported document types line by line to avoid large in-memory buffers.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function inspectDocumentContent(string $path, string $mime, array $options = []): array
    {
        return $this->security->inspectDocument($path, $mime, $options);
    }

    private function assertValidImage(string $path, string $mime): array
    {
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
            throw new \RuntimeException('Unsupported image format.');
        }

        $imageInfo = @getimagesize($path);
        if ($imageInfo === false || !isset($imageInfo[0], $imageInfo[1])) {
            throw new \RuntimeException('Image payload is invalid or corrupted.');
        }

        $width = (int) $imageInfo[0];
        $height = (int) $imageInfo[1];
        $pixels = $width * $height;

        if ($width < 1 || $height < 1) {
            throw new \RuntimeException('Image dimensions are invalid.');
        }

        if ($width > $this->maxImageWidth || $height > $this->maxImageHeight || $pixels > $this->maxImagePixels) {
            throw new \RuntimeException('Image dimensions exceed the allowed upload limits.');
        }

        return [$width, $height];
    }

    private function detectMimeType(string $path): string
    {
        $mime = '';

        if (class_exists('finfo')) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $detected = $finfo->file($path);
            if (is_string($detected)) {
                $mime = $detected;
            }
        }

        if ($mime === '' && function_exists('mime_content_type')) {
            $mime = mime_content_type($path) ?: '';
        }

        if ($mime === '') {
            throw new \RuntimeException('Unable to determine file type.');
        }

        return strtolower(trim($mime));
    }

    private function assertMimeAllowed(string $mime): void
    {
        $allowed = $this->allowedMimeTypeList();
        if ($allowed === ['*']) {
            return;
        }

        if (!in_array($mime, $allowed, true)) {
            throw new \RuntimeException('Sorry, only files of type(s) ' . implode(', ', $allowed) . ' are allowed.');
        }
    }

    private function allowedMimeTypeList(): array
    {
        if (trim($this->allowedMimeTypes) === '*') {
            return ['*'];
        }

        return array_values(array_filter(array_map('trim', explode(',', $this->allowedMimeTypes)), static fn($value) => $value !== ''));
    }

    private function createTemporaryFile(): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'sp_upload_');
        if ($tempFile === false) {
            throw new \RuntimeException('Failed to allocate a temporary file for upload processing.');
        }

        return $tempFile;
    }

    /**
     * Reserve a unique target path for a stored file.
     *
     * @return array{0: string, 1: string, 2: string, 3: string}
     */
    private function reserveTargetPath(string $targetDir, string $relativeFolder, string $extension): array
    {
        do {
            $baseName = bin2hex(random_bytes(16));
            $fileName = $baseName . '.' . $extension;
            $relativePath = $relativeFolder . '/' . $fileName;
            $targetFile = $targetDir . DIRECTORY_SEPARATOR . $fileName;
        } while (file_exists($targetFile));

        return [$baseName, $fileName, $relativePath, $targetFile];
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function reserveStoragePath(\Core\Filesystem\FilesystemAdapterInterface $storage, string $relativeFolder, string $extension): array
    {
        do {
            $baseName = bin2hex(random_bytes(16));
            $fileName = $baseName . '.' . $extension;
            $relativePath = ltrim(($relativeFolder !== '' ? $relativeFolder . '/' : '') . $fileName, '/');
        } while ($storage->exists($relativePath));

        return [$baseName, $fileName, $relativePath];
    }

    private function parseBase64Image(string $base64Image): array
    {
        $base64Image = trim($base64Image);
        if (!str_starts_with($base64Image, 'data:')) {
            throw new \RuntimeException('Invalid base64 image format.');
        }

        $separatorPos = strpos($base64Image, ',');
        if ($separatorPos === false) {
            throw new \RuntimeException('Invalid base64 image format.');
        }

        $metadata = substr($base64Image, 5, $separatorPos - 5);
        if (!preg_match('/^([a-zA-Z0-9.+\/-]+);base64$/', $metadata, $matches)) {
            throw new \RuntimeException('Invalid base64 image format.');
        }

        $mime = strtolower(trim($matches[1]));
        $base64Data = substr($base64Image, $separatorPos + 1);
        if ($base64Data === false || $base64Data === '') {
            throw new \RuntimeException('Failed to decode base64 image data.');
        }

        if (preg_match('/[^A-Za-z0-9+\/=\r\n]/', $base64Data)) {
            throw new \RuntimeException('Invalid base64 image format.');
        }

        $base64Data = preg_replace('/\s+/', '', $base64Data) ?? '';

        if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
            throw new \RuntimeException('Only JPEG and PNG base64 images are supported.');
        }

        if ($this->estimateDecodedBase64Size($base64Data) > $this->maxFileSizeInBytes()) {
            throw new \RuntimeException("Sorry, your file exceeds the maximum file size of {$this->maxFileSize}MB.");
        }

        $extension = $this->mimeToExtension[$mime] ?? null;
        if ($extension === null) {
            throw new \RuntimeException('Unsupported base64 image type.');
        }

        return [
            'mime' => $mime,
            'extension' => $extension,
            'payload' => $base64Data,
        ];
    }

    private function extensionFromMimeType(string $mimeType): ?string
    {
        return match ($mimeType) {
            'image/jpg' => 'jpg',
            'image/bmp' => 'bmp',
            'image/svg+xml' => 'svg',
            'image/tiff' => 'tiff',
            'image/x-icon', 'image/vnd.microsoft.icon' => 'ico',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'application/vnd.ms-powerpoint' => 'ppt',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
            'application/rtf' => 'rtf',
            'application/x-rar-compressed' => 'rar',
            'application/x-tar' => 'tar',
            'application/gzip' => 'gz',
            'audio/mpeg' => 'mp3',
            'audio/wav' => 'wav',
            'audio/ogg' => 'ogg',
            'audio/mp4' => 'm4a',
            'video/mp4' => 'mp4',
            'video/mpeg' => 'mpeg',
            'video/quicktime' => 'mov',
            'video/x-msvideo' => 'avi',
            'video/x-ms-wmv' => 'wmv',
            'text/html' => 'html',
            'text/css' => 'css',
            'application/javascript' => 'js',
            default => null,
        };
    }

    /**
     * Decode base64 content directly to disk in chunks to avoid large duplicate buffers.
     */
    private function decodeBase64ToFile(string $base64Data, string $destination): void
    {
        $handle = fopen($destination, 'wb');
        if ($handle === false) {
            throw new \RuntimeException('Failed to open temporary upload file for writing.');
        }

        $carry = '';
        $chunkSize = 16384;

        try {
            $length = strlen($base64Data);
            for ($offset = 0; $offset < $length; $offset += $chunkSize) {
                $buffer = $carry . substr($base64Data, $offset, $chunkSize);
                $isLastChunk = ($offset + $chunkSize) >= $length;

                if ($isLastChunk) {
                    $carry = '';
                    $chunk = $buffer;
                } else {
                    $remainder = strlen($buffer) % 4;
                    $carry = $remainder === 0 ? '' : substr($buffer, -$remainder);
                    $chunk = $remainder === 0 ? $buffer : substr($buffer, 0, -$remainder);
                }

                if ($chunk === '') {
                    continue;
                }

                $decoded = base64_decode($chunk, true);
                if ($decoded === false) {
                    throw new \RuntimeException('Failed to decode base64 image data.');
                }

                if (fwrite($handle, $decoded) === false) {
                    throw new \RuntimeException('Failed to persist decoded image data.');
                }
            }

            if ($carry !== '') {
                $decoded = base64_decode($carry, true);
                if ($decoded === false || fwrite($handle, $decoded) === false) {
                    throw new \RuntimeException('Failed to persist decoded image data.');
                }
            }
        } finally {
            fclose($handle);
        }

        clearstatcache(true, $destination);
        $size = filesize($destination);
        if ($size === false || $size < 1) {
            throw new \RuntimeException('Failed to decode base64 image data.');
        }
    }

    private function estimateDecodedBase64Size(string $base64Data): int
    {
        $padding = 0;
        $length = strlen($base64Data);

        if ($length >= 2 && substr($base64Data, -2) === '==') {
            $padding = 2;
        } elseif ($length >= 1 && substr($base64Data, -1) === '=') {
            $padding = 1;
        }

        return (int) floor(($length * 3) / 4) - $padding;
    }

    private function storeImageVariants(string $sourcePath, string $targetDir, string $baseName, string $mime, bool $compress, int $compressionMode): void
    {
        if (!extension_loaded('gd')) {
            throw new \RuntimeException('GD extension is required for secure image processing.');
        }

        $resource = $this->createImageResource($sourcePath, $mime);
        if ($resource === false) {
            throw new \RuntimeException('Failed to process image payload.');
        }

        $extension = $this->mimeToExtension[$mime];
        $primaryPath = $targetDir . DIRECTORY_SEPARATOR . $baseName . '.' . $extension;
        $this->writeImageResource($resource, $primaryPath, $mime, 90);

        if ($compress && $compressionMode >= 2) {
            $compressedPath = $targetDir . DIRECTORY_SEPARATOR . $baseName . '_compress.' . $extension;
            $this->writeImageResource($resource, $compressedPath, $mime, 60);
        }

        if ($compress && $compressionMode >= 3) {
            $thumbnailPath = $targetDir . DIRECTORY_SEPARATOR . $baseName . '_thumbnail.' . $extension;
            $thumbnail = $this->resizeImageResource($resource, 320, 320, $mime);
            $this->writeImageResource($thumbnail, $thumbnailPath, $mime, 45);
            $this->destroyImage($thumbnail);
        }

        $this->destroyImage($resource);
    }

    private function storeImageVariantsToStorage(string $sourcePath, \Core\Filesystem\FilesystemAdapterInterface $storage, string $relativeFolder, string $baseName, string $mime, bool $compress, int $compressionMode): int
    {
        if (!extension_loaded('gd')) {
            throw new \RuntimeException('GD extension is required for secure image processing.');
        }

        $resource = $this->createImageResource($sourcePath, $mime);
        if ($resource === false) {
            throw new \RuntimeException('Failed to process image payload.');
        }

        $extension = $this->mimeToExtension[$mime];
        $variants = [
            ['suffix' => '', 'quality' => 90, 'resource' => $resource],
        ];

        if ($compress && $compressionMode >= 2) {
            $variants[] = ['suffix' => '_compress', 'quality' => 60, 'resource' => $resource];
        }

        $thumbnail = null;
        if ($compress && $compressionMode >= 3) {
            $thumbnail = $this->resizeImageResource($resource, 320, 320, $mime);
            $variants[] = ['suffix' => '_thumbnail', 'quality' => 45, 'resource' => $thumbnail];
        }

        $size = 0;

        try {
            foreach ($variants as $variant) {
                $tempPath = $this->createTemporaryFile();

                try {
                    $this->writeImageResource($variant['resource'], $tempPath, $mime, (int) $variant['quality']);
                    $variantPath = ltrim(($relativeFolder !== '' ? $relativeFolder . '/' : '') . $baseName . (string) $variant['suffix'] . '.' . $extension, '/');
                    $size += $this->writePathToStorage($storage, $variantPath, $tempPath);
                } finally {
                    if (is_file($tempPath)) {
                        @unlink($tempPath);
                    }
                }
            }
        } finally {
            if ($thumbnail !== null) {
                $this->destroyImage($thumbnail);
            }

            $this->destroyImage($resource);
        }

        return $size;
    }

    private function writePathToStorage(\Core\Filesystem\FilesystemAdapterInterface $storage, string $relativePath, string $sourcePath): int
    {
        $stream = fopen($sourcePath, 'rb');
        if ($stream === false) {
            throw new \RuntimeException('Failed to open source file for storage upload.');
        }

        try {
            if (!$storage->writeStream($relativePath, $stream)) {
                throw new \RuntimeException('Failed to persist file to storage disk.');
            }
        } finally {
            fclose($stream);
        }

        clearstatcache(true, $sourcePath);
        $size = filesize($sourcePath);

        return $size === false ? 0 : (int) $size;
    }

    private function deleteStorageArtifacts(\Core\Filesystem\FilesystemAdapterInterface $storage, string $relativeFolder, string $baseName, string $extension): void
    {
        $paths = [
            ltrim(($relativeFolder !== '' ? $relativeFolder . '/' : '') . $baseName . '.' . $extension, '/'),
            ltrim(($relativeFolder !== '' ? $relativeFolder . '/' : '') . $baseName . '_compress.' . $extension, '/'),
            ltrim(($relativeFolder !== '' ? $relativeFolder . '/' : '') . $baseName . '_thumbnail.' . $extension, '/'),
        ];

        try {
            $storage->delete($paths);
        } catch (\Throwable $e) {
        }
    }

    private function createImageResource(string $path, string $mime)
    {
        return match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/gif' => @imagecreatefromgif($path),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default => false,
        };
    }

    private function writeImageResource($image, string $destination, string $mime, int $quality): void
    {
        $result = match ($mime) {
            'image/jpeg' => imagejpeg($image, $destination, $this->normalizeQuality($quality)),
            'image/png' => imagepng($image, $destination, $this->pngCompressionFromQuality($quality)),
            'image/gif' => imagegif($image, $destination),
            'image/webp' => function_exists('imagewebp') ? imagewebp($image, $destination, $this->normalizeQuality($quality)) : false,
            default => false,
        };

        if ($result === false) {
            throw new \RuntimeException('Failed to write processed image to disk.');
        }

        @chmod($destination, 0644);
    }

    private function resizeImageResource($image, int $maxWidth, int $maxHeight, string $mime)
    {
        $width = imagesx($image);
        $height = imagesy($image);

        if ($width <= $maxWidth && $height <= $maxHeight) {
            return $this->cloneImageResource($image, $mime);
        }

        $ratio = min($maxWidth / $width, $maxHeight / $height);
        $newWidth = max(1, (int) floor($width * $ratio));
        $newHeight = max(1, (int) floor($height * $ratio));

        $resized = imagecreatetruecolor($newWidth, $newHeight);
        $this->prepareTransparency($resized, $mime);

        if (!imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height)) {
            imagedestroy($resized);
            throw new \RuntimeException('Failed to generate resized image.');
        }

        return $resized;
    }

    private function cloneImageResource($image, string $mime)
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $clone = imagecreatetruecolor($width, $height);
        $this->prepareTransparency($clone, $mime);
        imagecopy($clone, $image, 0, 0, 0, 0, $width, $height);

        return $clone;
    }

    private function prepareTransparency($image, string $mime): void
    {
        if ($mime === 'image/png' || $mime === 'image/webp') {
            imagealphablending($image, false);
            imagesavealpha($image, true);
            $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
            imagefilledrectangle($image, 0, 0, imagesx($image), imagesy($image), $transparent);
            return;
        }

        if ($mime === 'image/gif') {
            $transparent = imagecolorallocate($image, 0, 0, 0);
            imagecolortransparent($image, $transparent);
            imagefilledrectangle($image, 0, 0, imagesx($image), imagesy($image), $transparent);
        }
    }

    private function calculateStoredSize(string $targetDir, string $baseName, string $extension, int $compressionMode): int
    {
        $paths = [$targetDir . DIRECTORY_SEPARATOR . $baseName . '.' . $extension];

        if ($compressionMode >= 2) {
            $paths[] = $targetDir . DIRECTORY_SEPARATOR . $baseName . '_compress.' . $extension;
        }

        if ($compressionMode >= 3) {
            $paths[] = $targetDir . DIRECTORY_SEPARATOR . $baseName . '_thumbnail.' . $extension;
        }

        $size = 0;
        foreach ($paths as $path) {
            if (is_file($path)) {
                $fileSize = filesize($path);
                if ($fileSize !== false) {
                    $size += $fileSize;
                }
            }
        }

        return $size;
    }

    private function deleteStoredArtifacts(string $targetDir, string $baseName, string $extension): void
    {
        $paths = [
            $targetDir . DIRECTORY_SEPARATOR . $baseName . '.' . $extension,
            $targetDir . DIRECTORY_SEPARATOR . $baseName . '_compress.' . $extension,
            $targetDir . DIRECTORY_SEPARATOR . $baseName . '_thumbnail.' . $extension,
        ];

        foreach ($paths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    private function normalizeCompressionMode(int $compressionMode): int
    {
        return match (true) {
            $compressionMode >= 3 => 3,
            $compressionMode === 2 => 2,
            default => 1,
        };
    }

    private function normalizeQuality(int $quality): int
    {
        return max(0, min(100, $quality));
    }

    private function pngCompressionFromQuality(int $quality): int
    {
        $quality = $this->normalizeQuality($quality);
        return (int) round((100 - $quality) * 9 / 100);
    }

    private function mimeMatches(string $declaredMime, string $actualMime): bool
    {
        if ($declaredMime === $actualMime) {
            return true;
        }

        return in_array($declaredMime, ['image/jpg', 'image/jpeg'], true)
            && in_array($actualMime, ['image/jpg', 'image/jpeg'], true);
    }

    private function destroyImage($image): void
    {
        if ($image instanceof \GdImage || is_resource($image)) {
            imagedestroy($image);
        }
    }

    private function absoluteUploadDir(): string
    {
        return $this->path . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $this->uploadDir);
    }

    private function maxFileSizeInBytes(): int
    {
        return $this->maxFileSize * 1024 * 1024;
    }

    private function normalizeRelativePath(string $path): string
    {
        return $this->security->normalizeRelativeProjectPath($path);
    }

    private function createFolder(?int $permission = null): void
    {
        $storage = $this->configuredStorageAdapter();
        if ($storage !== null) {
            $storage->makeDirectory($this->uploadDir);
            return;
        }

        $permission = $permission ?? $this->directoryPermission;
        $folderPath = $this->absoluteUploadDir();

        if (!is_dir($folderPath)) {
            $this->security->assertWritablePath($folderPath, 'Upload directory parent');
        }

        if (!is_dir($folderPath) && !mkdir($folderPath, $permission, true) && !is_dir($folderPath)) {
            throw new \RuntimeException('Failed to create upload directory.');
        }

        @chmod($folderPath, $permission);

        if (!$this->security->canWritePath($folderPath)) {
            throw new \RuntimeException('Upload directory is not writable.');
        }
    }

    private function fileUploadErrorMessage(int $errorCode): string
    {
        return match ($errorCode) {
            UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the upload_max_filesize directive in php.ini.',
            UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form.',
            UPLOAD_ERR_PARTIAL => 'The uploaded file was only partially uploaded.',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload.',
            default => 'Unknown upload error.',
        };
    }

    private function configuredStorageAdapter(): ?\Core\Filesystem\FilesystemAdapterInterface
    {
        if ($this->storageDisk === null) {
            return null;
        }

        if (!function_exists('storage')) {
            throw new \RuntimeException('Managed storage is not available. Ensure the storage service provider is bootstrapped before selecting a storage disk.');
        }

        $storage = storage($this->storageDisk);
        if (!$storage instanceof \Core\Filesystem\FilesystemAdapterInterface) {
            throw new \RuntimeException('Configured storage disk did not resolve to a filesystem adapter.');
        }

        return $storage;
    }
}
