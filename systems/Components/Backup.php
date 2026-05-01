<?php

namespace Components;

use Exception;

/**
 * Backup Class - Laravel Spatie-like Backup Component
 *
 * Supports:
 * - Database backup (mysqldump or PHP-based)
 * - File/directory backup
 * - ZIP compression
 * - Automatic cleanup of old backups
 * - Configurable backup path
 * - Cron-compatible via console scheduler
 *
 * Usage:
 *   $backup = new \Components\Backup();
 *   $backup->database()->run();                     // DB only
 *   $backup->files()->run();                        // Files only
 *   $backup->database()->files()->run();            // Both
 *   $backup->cleanup(30);                           // Clean backups older than 30 days
 *
 * Console:
 *   php myth backup:run
 *   php myth backup:run --only-db
 *   php myth backup:run --only-files
 *   php myth backup:clean --days=30
 *
 * Cron (via schedule):
 *   $console->schedule('backup:run', '0 2 * * *');
 */
class Backup
{
    private array $config;
    private bool $includeDatabase = false;
    private bool $includeFiles = false;
    private array $directories = [];
    private array $excludePatterns = [];
    private string $backupPath;
    private string $filenamePrefix;
    private ?string $lastBackupPath = null;
    private ?string $backupDisk = null;
    private string $backupDiskPrefix = 'backups';

    private Security $security;

    public function __construct(array $config = [])
    {
        $this->config = array_merge($this->getDefaultConfig(), $config);
        $this->backupPath = rtrim($this->config['backup_path'], '/\\');
        $this->filenamePrefix = $this->config['filename_prefix'];
        $this->directories = $this->config['directories'];
        $this->excludePatterns = $this->config['exclude'];
        $publishConfig = (array) ($this->config['publish'] ?? []);
        $configuredDisk = $publishConfig['disk'] ?? null;
        $configuredPrefix = $publishConfig['prefix'] ?? null;
        $this->backupDisk = is_string($configuredDisk) && trim($configuredDisk) !== '' ? trim($configuredDisk) : null;
        if (is_string($configuredPrefix) && trim($configuredPrefix) !== '') {
            $this->backupDiskPrefix = trim(str_replace('\\', '/', $configuredPrefix), '/');
        }
        $this->security = new Security();

        $this->ensureBackupDirectoryExists();
    }

    /**
     * Default configuration
     */
    private function getDefaultConfig(): array
    {
        $rootDir = defined('ROOT_DIR') ? ROOT_DIR : dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;

        return [
            'backup_path' => $rootDir . 'storage/backups',
            'filename_prefix' => 'backup',
            'directories' => [
                $rootDir . 'app',
                $rootDir . 'systems',
                $rootDir . 'public/upload',
            ],
            'exclude' => [
                '*.log',
                'node_modules',
                'vendor',
                '.git',
                'storage/cache',
                'storage/backups',
            ],
            'database' => $this->resolveDatabaseConfig(),
            'mysqldump_path' => null, // Auto-detect; set to absolute path to override
            'mysqldump_search_paths' => $this->getDefaultMysqldumpSearchPaths(),
            'compression' => 'zip', // 'zip' or 'gzip'
            'max_file_size_mb' => 512,
            'cleanup_days' => 30,
            'notifications' => false,
            'publish' => $this->resolvePublishConfig(),
        ];
    }

    /**
     * Resolve database configuration from the framework config
     */
    private function resolveDatabaseConfig(): array
    {
        if (!function_exists('config')) {
            return [];
        }

        $env = defined('ENVIRONMENT') ? ENVIRONMENT : 'development';
        $dbConfig = \config('db.default.' . $env);

        if (empty($dbConfig) || !is_array($dbConfig)) {
            return [];
        }

        return [
            'host' => $dbConfig['hostname'] ?? $dbConfig['host'] ?? 'localhost',
            'port' => $dbConfig['port'] ?? '3306',
            'database' => $dbConfig['database'] ?? '',
            'username' => $dbConfig['username'] ?? '',
            'password' => $dbConfig['password'] ?? '',
            'driver' => $dbConfig['driver'] ?? 'mysql',
        ];
    }

    private function resolvePublishConfig(): array
    {
        if (!function_exists('config')) {
            return [
                'disk' => null,
                'prefix' => 'backups',
            ];
        }

        $publishConfig = config('integration.backup.publish', []);
        if (!is_array($publishConfig)) {
            $publishConfig = [];
        }

        $disk = $publishConfig['disk'] ?? null;
        $prefix = $publishConfig['prefix'] ?? 'backups';

        return [
            'disk' => is_string($disk) && trim($disk) !== '' ? trim($disk) : null,
            'prefix' => is_string($prefix) && trim($prefix) !== ''
                ? trim(str_replace('\\', '/', $prefix), '/')
                : 'backups',
        ];
    }

    /**
     * Get default mysqldump search paths based on OS.
     * Supports glob patterns for version-independent detection.
     */
    private function getDefaultMysqldumpSearchPaths(): array
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return [
                'C:\\laragon\\bin\\mysql\\*\\bin\\mysqldump.exe',
                'C:\\xampp\\mysql\\bin\\mysqldump.exe',
                'C:\\wamp64\\bin\\mysql\\*\\bin\\mysqldump.exe',
            ];
        }

        return [
            '/usr/bin/mysqldump',
            '/usr/local/bin/mysqldump',
            '/usr/local/mysql/bin/mysqldump',
        ];
    }

    // ─── Fluent Configuration ────────────────────────────────────

    /**
     * Include database in backup
     */
    public function database(array $config = []): self
    {
        $this->includeDatabase = true;

        if (!empty($config)) {
            $this->config['database'] = array_merge($this->config['database'] ?? [], $config);
        }

        return $this;
    }

    /**
     * Include files/directories in backup
     */
    public function files(array $directories = []): self
    {
        $this->includeFiles = true;

        if (!empty($directories)) {
            $this->directories = $directories;
        }

        return $this;
    }

    /**
     * Set backup path
     */
    public function setBackupPath(string $path): self
    {
        $this->backupPath = rtrim($path, '/\\');
        $this->ensureBackupDirectoryExists();

        return $this;
    }

    /**
     * Publish finished backup archives to a managed storage disk after local creation.
     */
    public function setBackupDisk(?string $disk, ?string $prefix = null): self
    {
        $disk = $disk !== null ? trim($disk) : null;
        $this->backupDisk = $disk !== '' ? $disk : null;

        if ($prefix !== null) {
            $cleanPrefix = trim(str_replace('\\', '/', $prefix), '/');
            $this->backupDiskPrefix = $cleanPrefix !== '' ? $cleanPrefix : 'backups';
        }

        return $this;
    }

    /**
     * Add directories to backup
     */
    public function addDirectory(string $path): self
    {
        $this->directories[] = $path;
        return $this;
    }

    /**
     * Add exclude pattern
     */
    public function exclude(string $pattern): self
    {
        $this->excludePatterns[] = $pattern;
        return $this;
    }

    /**
     * Set filename prefix
     */
    public function prefix(string $prefix): self
    {
        $this->filenamePrefix = $prefix;
        return $this;
    }

    // ─── Execution ──────────────────────────────────────────────

    /**
     * Run the backup
     */
    public function run(): array
    {
        $startTime = microtime(true);
        $timestamp = date('Y-m-d_H-i-s');
        $tempDir = $this->createTemporaryBackupDirectory($timestamp);

        try {
            $filesToZip = [];

            // 1. Database backup
            if ($this->includeDatabase) {
                $dbFile = $this->backupDatabase($tempDir);
                if ($dbFile !== null) {
                    $filesToZip[] = $dbFile;
                }
            }

            // 2. File backup
            if ($this->includeFiles) {
                $fileList = $this->collectFiles();
                foreach ($fileList as $file) {
                    $filesToZip[] = $file;
                }
            }

            // 3. Create archive if we have content
            if (empty($filesToZip)) {
                return [
                    'success' => false,
                    'error' => empty($this->includeDatabase) && empty($this->includeFiles)
                        ? 'Nothing to backup. Call ->database() and/or ->files() first.'
                        : 'No files collected for backup (database dump may have failed or directories are empty).',
                    'path' => null,
                    'size' => '0 B',
                ];
            }

            $zipFilename = $this->filenamePrefix . '_' . $timestamp . '.zip';
            $zipPath = $this->backupPath . DIRECTORY_SEPARATOR . $zipFilename;

            $this->createZipArchive($zipPath, $filesToZip, $tempDir);

            $diskPath = null;
            $diskUrl = null;
            if ($this->backupDisk !== null) {
                [$diskPath, $diskUrl] = $this->publishBackupToManagedStorage($zipPath, $zipFilename);
            }

            // Cleanup temp directory
            $this->deleteDirectory($tempDir);

            $this->lastBackupPath = $zipPath;
            $elapsed = round(microtime(true) - $startTime, 2);

            return [
                'success' => true,
                'path' => $zipPath,
                'disk' => $this->backupDisk,
                'disk_path' => $diskPath,
                'disk_url' => $diskUrl,
                'filename' => $zipFilename,
                'size' => $this->formatFileSize((int) (filesize($zipPath) ?: 0)),
                'elapsed' => $elapsed . 's',
                'timestamp' => $timestamp,
                'includes' => [
                    'database' => $this->includeDatabase,
                    'files' => $this->includeFiles,
                ],
            ];
        } catch (\Throwable $e) {
            // Cleanup temp directory on error
            if (is_dir($tempDir)) {
                $this->deleteDirectory($tempDir);
            }

            $this->logError('Backup failed: ' . $e->getMessage());

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'path' => null,
                'size' => '0 B',
            ];
        }
    }

    // ─── Database Backup ────────────────────────────────────────

    /**
     * Backup database using mysqldump or PHP fallback
     */
    private function backupDatabase(string $tempDir): ?string
    {
        $dbConfig = $this->config['database'];

        if (empty($dbConfig['database'])) {
            throw new Exception('Database configuration is missing. Check your database config.');
        }

        $databaseName = $this->security->sanitizeStorageSegment((string) $dbConfig['database']);
        $filename = 'database_' . $databaseName . '_' . date('Y-m-d_H-i-s') . '.sql';
        $filePath = $tempDir . DIRECTORY_SEPARATOR . $filename;

        // Try mysqldump first (much faster)
        if ($this->tryMysqldump($dbConfig, $filePath)) {
            return $filePath;
        }

        // Fallback to PHP-based dump
        return $this->phpDatabaseDump($dbConfig, $filePath);
    }

    private function publishBackupToManagedStorage(string $localPath, string $zipFilename): array
    {
        if (!function_exists('storage')) {
            throw new Exception('Managed storage is not available for backup publishing.');
        }

        $storage = storage($this->backupDisk);
        if (!$storage instanceof \Core\Filesystem\FilesystemAdapterInterface) {
            throw new Exception('Configured backup disk did not resolve to a filesystem adapter.');
        }

        $prefix = trim(str_replace('\\', '/', $this->backupDiskPrefix), '/');
        $relativePath = ltrim(($prefix !== '' ? $prefix . '/' : '') . $zipFilename, '/');
        $stream = fopen($localPath, 'rb');
        if ($stream === false) {
            throw new Exception('Unable to open backup archive for managed storage publishing.');
        }

        try {
            if (!$storage->writeStream($relativePath, $stream)) {
                throw new Exception('Failed to publish backup archive to managed storage.');
            }
        } finally {
            fclose($stream);
        }

        return [$relativePath, $storage->url($relativePath)];
    }

    /**
     * Try to use mysqldump binary
     */
    private function tryMysqldump(array $config, string $outputFile): bool
    {
        if (!$this->isFunctionAvailable('proc_open')) {
            return false;
        }

        $mysqldump = $this->findMysqldump();
        if ($mysqldump === null) {
            return false;
        }

        $command = [
            $mysqldump,
            '--host=' . (string) ($config['host'] ?? 'localhost'),
            '--port=' . (string) ($config['port'] ?? '3306'),
            '--user=' . (string) ($config['username'] ?? ''),
            '--single-transaction',
            '--routines',
            '--triggers',
            '--add-drop-table',
            (string) ($config['database'] ?? ''),
        ];

        $password = (string) ($config['password'] ?? '');
        if ($password !== '') {
            array_splice($command, 4, 0, ['--password=' . $password]);
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $outputFile, 'w'],
            2 => ['pipe', 'w'],
        ];

        $pipes = [];
        $process = @proc_open($command, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            return false;
        }

        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }

        $stderr = '';
        if (isset($pipes[2]) && is_resource($pipes[2])) {
            $stderr = stream_get_contents($pipes[2]) ?: '';
            fclose($pipes[2]);
        }

        $returnCode = proc_close($process);

        if ($returnCode !== 0) {
            // Clean up failed dump file
            if (file_exists($outputFile)) {
                @unlink($outputFile);
            }

            if ($stderr !== '') {
                $this->logError('mysqldump failed: ' . trim($stderr));
            }

            return false;
        }

        return file_exists($outputFile) && filesize($outputFile) > 0;
    }

    /**
     * Find mysqldump binary path.
     * Checks config 'mysqldump_path' first, then falls back to 'mysqldump_search_paths'.
     */
    private function findMysqldump(): ?string
    {
        // 1. Check if a path was explicitly provided in config
        $configPath = $this->config['mysqldump_path'] ?? null;
        if (is_string($configPath) && $this->isAllowedBinaryPath($configPath)) {
            return $configPath;
        }

        $pathBinary = $this->locateBinaryOnPath(PHP_OS_FAMILY === 'Windows' ? ['mysqldump.exe', 'mysqldump'] : ['mysqldump']);
        if ($pathBinary !== null) {
            return $pathBinary;
        }

        // 2. Resolve search paths from config (supports glob patterns)
        $searchPaths = $this->config['mysqldump_search_paths'] ?? [];
        foreach ($searchPaths as $entry) {
            if (str_contains($entry, '*')) {
                $found = glob($entry);
                if (!empty($found)) {
                    foreach ($found as $path) {
                        if ($this->isAllowedBinaryPath($path)) {
                            return $path;
                        }
                    }
                }
            } elseif ($this->isAllowedBinaryPath($entry)) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Locate an allowed binary in the current PATH without invoking a shell.
     *
     * @param array<int, string> $binaryNames
     */
    private function locateBinaryOnPath(array $binaryNames): ?string
    {
        $pathEnv = getenv('PATH');
        if (!is_string($pathEnv) || trim($pathEnv) === '') {
            return null;
        }

        foreach (explode(PATH_SEPARATOR, $pathEnv) as $directory) {
            $directory = trim($directory, " \t\n\r\0\x0B\"");
            if ($directory === '' || !is_dir($directory)) {
                continue;
            }

            foreach ($binaryNames as $binaryName) {
                $candidate = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $binaryName;
                if ($this->isAllowedBinaryPath($candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * Accept only readable local mysqldump binaries.
     */
    private function isAllowedBinaryPath(string $path): bool
    {
        $path = trim($path);
        if ($path === '') {
            return false;
        }

        if (!$this->security->canReadPath($path)) {
            return false;
        }

        return is_file($path) && (PHP_OS_FAMILY === 'Windows' || is_executable($path));
    }

    /**
     * PHP-based database dump (fallback when mysqldump is not available)
     */
    private function phpDatabaseDump(array $config, string $outputFile): string
    {
        $dsn = sprintf(
            '%s:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $config['driver'] ?? 'mysql',
            $config['host'] ?? 'localhost',
            $config['port'] ?? '3306',
            $config['database'] ?? ''
        );

        $pdoOptions = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ];

        if (($config['driver'] ?? 'mysql') === 'mysql' && defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
            $pdoOptions[\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY] = false;
        }

        $pdo = new \PDO($dsn, $config['username'] ?? '', $config['password'] ?? '', $pdoOptions);

        $handle = fopen($outputFile, 'w');
        if ($handle === false) {
            throw new Exception("Cannot create dump file: {$outputFile}");
        }

        try {
            // Header
            fwrite($handle, "-- MythPHP Database Backup\n");
            fwrite($handle, "-- Generated: " . date('Y-m-d H:i:s') . "\n");
            fwrite($handle, "-- Database: {$config['database']}\n");
            fwrite($handle, "-- --------------------------------------------------------\n\n");
            fwrite($handle, "SET NAMES utf8mb4;\n");
            fwrite($handle, "SET FOREIGN_KEY_CHECKS = 0;\n\n");

            // Get all tables
            $tables = $pdo->query("SHOW TABLES")->fetchAll(\PDO::FETCH_COLUMN);

            foreach ($tables as $table) {
                if (!is_string($table) || !preg_match('/^[a-zA-Z0-9_$]+$/', $table)) {
                    continue;
                }

                $quotedTable = '`' . str_replace('`', '``', $table) . '`';

                $createStmt = $pdo->query("SHOW CREATE TABLE {$quotedTable}")->fetch();
                $createSql = $createStmt['Create Table'] ?? $createStmt['Create View'] ?? '';
                if (!is_string($createSql) || $createSql === '') {
                    continue;
                }

                fwrite($handle, "-- Table: {$table}\n");
                fwrite($handle, "DROP TABLE IF EXISTS {$quotedTable};\n");
                fwrite($handle, $createSql . ";\n\n");

                if (isset($createStmt['Create View'])) {
                    continue;
                }

                $statement = $pdo->query("SELECT * FROM {$quotedTable}");
                $chunkSize = 500;
                $rows = [];

                while (($row = $statement->fetch(\PDO::FETCH_ASSOC)) !== false) {
                    $rows[] = $row;
                    if (count($rows) >= $chunkSize) {
                        $this->writeInsertBatch($handle, $pdo, $quotedTable, $rows);
                        $rows = [];
                    }
                }

                if (!empty($rows)) {
                    $this->writeInsertBatch($handle, $pdo, $quotedTable, $rows);
                }

                $statement->closeCursor();
                unset($statement, $rows);
            }

            fwrite($handle, "SET FOREIGN_KEY_CHECKS = 1;\n");
            fwrite($handle, "\n-- Dump completed: " . date('Y-m-d H:i:s') . "\n");
        } finally {
            fclose($handle);
        }

        return $outputFile;
    }

    /**
     * Write a chunk of row inserts to the SQL dump.
     *
     * @param resource $handle
     * @param array<int, array<string, mixed>> $rows
     */
    private function writeInsertBatch($handle, \PDO $pdo, string $quotedTable, array $rows): void
    {
        if (empty($rows)) {
            return;
        }

        $columns = array_keys($rows[0]);
        $columnList = '`' . implode('`, `', array_map(static fn($column) => str_replace('`', '``', (string) $column), $columns)) . '`';
        fwrite($handle, "INSERT INTO {$quotedTable} ({$columnList}) VALUES\n");

        $valueLines = [];
        foreach ($rows as $row) {
            $values = array_map(static function ($value) use ($pdo) {
                if ($value === null) {
                    return 'NULL';
                }

                return $pdo->quote((string) $value);
            }, array_values($row));

            $valueLines[] = '(' . implode(', ', $values) . ')';
        }

        fwrite($handle, implode(",\n", $valueLines) . ";\n\n");
    }

    // ─── File Backup ────────────────────────────────────────────

    /**
     * Collect all files to include in backup
     */
    private function collectFiles(): array
    {
        $files = [];

        foreach ($this->directories as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->isLink() || !$file->isReadable()) {
                    continue;
                }

                $filePath = $file->getPathname();

                if ($this->shouldExclude($filePath)) {
                    continue;
                }

                // Skip files larger than max limit
                $maxBytes = ($this->config['max_file_size_mb'] ?? 512) * 1024 * 1024;
                $fileSize = $file->getSize();
                if ($fileSize === false || $fileSize > $maxBytes) {
                    continue;
                }

                $files[] = $filePath;
            }
        }

        return $files;
    }

    /**
     * Check if a file should be excluded
     */
    private function shouldExclude(string $filePath): bool
    {
        $normalized = str_replace('\\', '/', $filePath);

        foreach ($this->excludePatterns as $pattern) {
            // Directory pattern
            if (!str_contains($pattern, '*') && !str_contains($pattern, '.')) {
                if (str_contains($normalized, '/' . $pattern . '/') || str_ends_with($normalized, '/' . $pattern)) {
                    return true;
                }
            }

            // Glob-style pattern
            if (str_contains($pattern, '*')) {
                if (fnmatch($pattern, basename($filePath))) {
                    return true;
                }
            }
        }

        return false;
    }

    // ─── Archive Creation ───────────────────────────────────────

    /**
     * Create ZIP archive
     */
    private function createZipArchive(string $zipPath, array $files, string $tempDir): void
    {
        if (!class_exists('ZipArchive')) {
            throw new Exception('ZipArchive extension is not available. Enable ext-zip in php.ini.');
        }

        $zip = new \ZipArchive();
        $result = $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        if ($result !== true) {
            throw new Exception("Cannot create ZIP archive: {$zipPath} (Error code: {$result})");
        }

        $rootDir = defined('ROOT_DIR') ? ROOT_DIR : dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;

        foreach ($files as $file) {
            if (!file_exists($file) || !is_readable($file)) {
                continue;
            }

            // Create relative path inside ZIP
            if (str_starts_with($file, $tempDir)) {
                // Database dump files
                $localName = 'database/' . basename($file);
            } elseif (str_starts_with(str_replace('\\', '/', $file), str_replace('\\', '/', $rootDir))) {
                // Project files - use relative path from project root
                $localName = 'files/' . substr(str_replace('\\', '/', $file), strlen(str_replace('\\', '/', $rootDir)));
            } else {
                $localName = 'files/' . basename($file);
            }

            if ($zip->addFile($file, $localName) !== true) {
                throw new Exception("Failed to add file to ZIP archive: {$file}");
            }
        }

        $zip->close();

        if (!file_exists($zipPath)) {
            throw new Exception("Failed to create ZIP archive: {$zipPath}");
        }
    }

    // ─── Cleanup ────────────────────────────────────────────────

    /**
     * Clean up old backup files
     *
     * @param int $days Remove backups older than this many days
     * @return int Number of files removed
     */
    public function cleanup(int $days = 30): int
    {
        $removed = 0;
        $threshold = time() - ($days * 86400);

        $files = glob($this->backupPath . '/' . $this->filenamePrefix . '_*.zip');
        if ($files === false) {
            return 0;
        }

        foreach ($files as $file) {
            $modifiedAt = filemtime($file);
            if ($modifiedAt !== false && $modifiedAt < $threshold) {
                if (@unlink($file)) {
                    $removed++;
                }
            }
        }

        return $removed;
    }

    /**
     * List all existing backups
     */
    public function listBackups(): array
    {
        $backups = [];

        $files = glob($this->backupPath . '/' . $this->filenamePrefix . '_*.zip');
        if ($files === false) {
            return [];
        }

        // Sort by newest first
        usort($files, function ($a, $b) {
            return filemtime($b) - filemtime($a);
        });

        foreach ($files as $file) {
            $fileSize = filesize($file);
            $fileMtime = filemtime($file);

            $backups[] = [
                'filename' => basename($file),
                'path' => $file,
                'size' => $this->formatFileSize((int) ($fileSize ?: 0)),
                'created_at' => $fileMtime !== false ? date('Y-m-d H:i:s', $fileMtime) : 'Unknown',
                'age_days' => $fileMtime !== false ? (int) floor((time() - $fileMtime) / 86400) : 0,
            ];
        }

        return $backups;
    }

    /**
     * Get the last backup path
     */
    public function getLastBackupPath(): ?string
    {
        return $this->lastBackupPath;
    }

    // ─── Helpers ────────────────────────────────────────────────

    /**
     * Format file size to human-readable string
     */
    private function formatFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes >= 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * Recursively delete a directory
     */
    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($dir);
    }

    /**
     * Create the configured backup directory if needed and ensure it is writable.
     */
    private function ensureBackupDirectoryExists(): void
    {
        if (!is_dir($this->backupPath)) {
            if (!mkdir($this->backupPath, 0775, true) && !is_dir($this->backupPath)) {
                throw new Exception('Unable to create backup directory: ' . $this->backupPath);
            }
        }

        if (!$this->security->canWritePath($this->backupPath)) {
            throw new Exception('Backup directory is not writable: ' . $this->backupPath);
        }
    }

    /**
     * Create an isolated temporary directory for the current backup run.
     */
    private function createTemporaryBackupDirectory(string $timestamp): string
    {
        $tempDir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'MythPHP_backup_' . $timestamp . '_' . bin2hex(random_bytes(4));

        if (!mkdir($tempDir, 0700, true) && !is_dir($tempDir)) {
            throw new Exception('Unable to create temporary backup directory.');
        }

        return $tempDir;
    }

    /**
     * Check whether a function exists and is not disabled.
     */
    private function isFunctionAvailable(string $functionName): bool
    {
        if (!function_exists($functionName)) {
            return false;
        }

        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return !in_array($functionName, $disabled, true);
    }

    /**
     * Log error
     */
    private function logError(string $message): void
    {
        if (function_exists('logger')) {
            logger()->log_error('[Backup] ' . $message);
        } else {
            error_log('[Backup] ' . $message);
        }
    }
}
