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

    public function __construct(array $config = [])
    {
        $this->config = array_merge($this->getDefaultConfig(), $config);
        $this->backupPath = rtrim($this->config['backup_path'], '/\\');
        $this->filenamePrefix = $this->config['filename_prefix'];
        $this->directories = $this->config['directories'];
        $this->excludePatterns = $this->config['exclude'];

        // Ensure backup directory exists
        if (!is_dir($this->backupPath)) {
            mkdir($this->backupPath, 0775, true);
        }
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

        if (!is_dir($this->backupPath)) {
            mkdir($this->backupPath, 0775, true);
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
        $tempDir = sys_get_temp_dir() . '/MythPHP_backup_' . $timestamp;

        try {
            if (!is_dir($tempDir)) {
                mkdir($tempDir, 0775, true);
            }

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

            // Cleanup temp directory
            $this->deleteDirectory($tempDir);

            $this->lastBackupPath = $zipPath;
            $elapsed = round(microtime(true) - $startTime, 2);

            return [
                'success' => true,
                'path' => $zipPath,
                'filename' => $zipFilename,
                'size' => $this->formatFileSize(filesize($zipPath)),
                'elapsed' => $elapsed . 's',
                'timestamp' => $timestamp,
                'includes' => [
                    'database' => $this->includeDatabase,
                    'files' => $this->includeFiles,
                ],
            ];
        } catch (Exception $e) {
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

        $filename = 'database_' . $dbConfig['database'] . '_' . date('Y-m-d_H-i-s') . '.sql';
        $filePath = $tempDir . DIRECTORY_SEPARATOR . $filename;

        // Try mysqldump first (much faster)
        if ($this->tryMysqldump($dbConfig, $filePath)) {
            return $filePath;
        }

        // Fallback to PHP-based dump
        return $this->phpDatabaseDump($dbConfig, $filePath);
    }

    /**
     * Try to use mysqldump binary
     */
    private function tryMysqldump(array $config, string $outputFile): bool
    {
        $mysqldump = $this->findMysqldump();
        if ($mysqldump === null) {
            return false;
        }

        $host = escapeshellarg($config['host'] ?? 'localhost');
        $port = escapeshellarg($config['port'] ?? '3306');
        $user = escapeshellarg($config['username'] ?? '');
        $pass = $config['password'] ?? '';
        $database = escapeshellarg($config['database'] ?? '');
        $output = escapeshellarg($outputFile);

        $command = "{$mysqldump} --host={$host} --port={$port} --user={$user}";

        if (!empty($pass)) {
            $command .= ' --password=' . escapeshellarg($pass);
        }

        $command .= " --single-transaction --routines --triggers --add-drop-table {$database} > {$output} 2>&1";

        exec($command, $outputLines, $returnCode);

        if ($returnCode !== 0) {
            // Clean up failed dump file
            if (file_exists($outputFile)) {
                @unlink($outputFile);
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
        if ($configPath !== null && file_exists($configPath)) {
            return $configPath;
        }

        $paths = [
            'mysqldump', // system PATH
        ];

        // 2. Resolve search paths from config (supports glob patterns)
        $searchPaths = $this->config['mysqldump_search_paths'] ?? [];
        foreach ($searchPaths as $entry) {
            if (str_contains($entry, '*')) {
                $found = glob($entry);
                if (!empty($found)) {
                    $paths = array_merge($paths, $found);
                }
            } else {
                $paths[] = $entry;
            }
        }

        foreach ($paths as $path) {
            // If it's an absolute path, just check if file exists
            if ($path !== 'mysqldump' && file_exists($path)) {
                return $path;
            }

            // Check system PATH for generic 'mysqldump' command
            if ($path === 'mysqldump') {
                $testCmd = PHP_OS_FAMILY === 'Windows'
                    ? 'where mysqldump 2>NUL'
                    : 'which mysqldump 2>/dev/null';

                $output = [];
                exec($testCmd, $output, $code);
                if ($code === 0) {
                    return $path;
                }
            }
        }

        return null;
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

        $pdo = new \PDO($dsn, $config['username'] ?? '', $config['password'] ?? '', [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);

        $handle = fopen($outputFile, 'w');
        if ($handle === false) {
            throw new Exception("Cannot create dump file: {$outputFile}");
        }

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
            // Sanitize table name: only allow valid MySQL identifier characters
            if (!preg_match('/^[a-zA-Z0-9_$]+$/', $table)) {
                continue;
            }

            $quotedTable = '`' . str_replace('`', '``', $table) . '`';

            // Get CREATE TABLE statement
            $createStmt = $pdo->query("SHOW CREATE TABLE {$quotedTable}")->fetch();
            $createSql = $createStmt['Create Table'] ?? $createStmt['Create View'] ?? '';

            fwrite($handle, "-- Table: {$table}\n");
            fwrite($handle, "DROP TABLE IF EXISTS {$quotedTable};\n");
            fwrite($handle, $createSql . ";\n\n");

            // Skip data for views
            if (isset($createStmt['Create View'])) {
                continue;
            }

            // Export data in chunks
            $count = $pdo->query("SELECT COUNT(*) FROM {$quotedTable}")->fetchColumn();

            if ($count > 0) {
                $chunkSize = 1000;
                $offset = 0;

                while ($offset < $count) {
                    $rows = $pdo->query("SELECT * FROM {$quotedTable} LIMIT {$chunkSize} OFFSET {$offset}")->fetchAll();

                    if (empty($rows)) {
                        break;
                    }

                    $columns = array_keys($rows[0]);
                    $columnList = '`' . implode('`, `', array_map(fn($c) => str_replace('`', '``', $c), $columns)) . '`';

                    fwrite($handle, "INSERT INTO {$quotedTable} ({$columnList}) VALUES\n");

                    $valueLines = [];
                    foreach ($rows as $row) {
                        $values = array_map(function ($val) use ($pdo) {
                            if ($val === null) {
                                return 'NULL';
                            }
                            return $pdo->quote($val);
                        }, array_values($row));

                        $valueLines[] = '(' . implode(', ', $values) . ')';
                    }

                    fwrite($handle, implode(",\n", $valueLines) . ";\n\n");

                    $offset += $chunkSize;

                    // Free memory
                    unset($rows, $valueLines);
                }
            }
        }

        fwrite($handle, "SET FOREIGN_KEY_CHECKS = 1;\n");
        fwrite($handle, "\n-- Dump completed: " . date('Y-m-d H:i:s') . "\n");

        fclose($handle);

        return $outputFile;
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
                if (!$file->isFile()) {
                    continue;
                }

                $filePath = $file->getPathname();

                if ($this->shouldExclude($filePath)) {
                    continue;
                }

                // Skip files larger than max limit
                $maxBytes = ($this->config['max_file_size_mb'] ?? 512) * 1024 * 1024;
                if ($file->getSize() > $maxBytes) {
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

            $zip->addFile($file, $localName);
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
            if (filemtime($file) < $threshold) {
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
            $backups[] = [
                'filename' => basename($file),
                'path' => $file,
                'size' => $this->formatFileSize(filesize($file)),
                'created_at' => date('Y-m-d H:i:s', filemtime($file)),
                'age_days' => (int) floor((time() - filemtime($file)) / 86400),
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
