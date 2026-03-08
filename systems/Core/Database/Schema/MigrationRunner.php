<?php

namespace Core\Database\Schema;

/**
 * MigrationRunner — Manages and executes database migrations and seeders.
 *
 * Tracks migration/seeder state in a flat JSON file (deploy.json) instead of
 * a database table. This makes the system self-contained and works even before
 * the database schema is set up.
 *
 * deploy.json structure:
 * [
 *   {
 *     "file": "2025_06_22_000001_create_users_table.php",
 *     "type": "migrate",
 *     "batch": 1,
 *     "migrated_at": "2025-06-22 15:00:00",
 *     "status": "migrated"
 *   }
 * ]
 *
 * Usage:
 *   $runner = new MigrationRunner();
 *   $runner->migrate();              // Run all pending migrations
 *   $runner->rollback();             // Rollback last batch
 *   $runner->seed();                 // Run all pending seeders
 *   $runner->seed('MasterRoles');    // Run a specific seeder
 *   $runner->status();               // Get migration/seeder status
 *
 * @category  Database
 * @package   Core\Database\Schema
 * @author    Mohd Fahmy Izwan Zulkhafri <faizzul14@gmail.com>
 * @license   http://opensource.org/licenses/gpl-3.0.html GNU Public License
 * @version   1.0.0
 */
class MigrationRunner
{
    /**
     * @var string Path to migration files directory
     */
    private string $migrationsPath;

    /**
     * @var string Path to seeder files directory
     */
    private string $seedersPath;

    /**
     * @var string Path to the deploy.json tracking file
     */
    private string $deployFile;

    /**
     * Create a new MigrationRunner instance.
     */
    public function __construct(?string $basePath = null)
    {
        $base = $basePath ?? (defined('ROOT_DIR') ? ROOT_DIR : __DIR__ . '/../../../../');
        $appDir = rtrim($base, '/\\') . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR;
        $this->migrationsPath = $appDir . 'migrations' . DIRECTORY_SEPARATOR;
        $this->seedersPath = $appDir . 'seeders' . DIRECTORY_SEPARATOR;
        $this->deployFile = $appDir . 'deploy.json';
    }

    // ─── Migration Operations ────────────────────────────────

    /**
     * Run all pending migrations.
     *
     * @param callable|null $output Optional callback for progress output: function(string $status, string $message)
     * @return array{migrated: string[], errors: array}
     */
    public function migrate(?callable $output = null): array
    {
        $deployData = $this->loadDeployData();
        $pending = $this->getPendingMigrations($deployData);

        if (empty($pending)) {
            $output && $output('info', 'Nothing to migrate.');
            return ['migrated' => [], 'errors' => []];
        }

        $batch = $this->getNextBatch($deployData);
        $migrated = [];
        $errors = [];

        foreach ($pending as $file) {
            $output && $output('running', $file);
            $startTime = microtime(true);

            try {
                $migration = $this->resolveMigration($this->migrationsPath . $file);
                $migration->up();

                $elapsed = round((microtime(true) - $startTime) * 1000, 2);

                $deployData[] = [
                    'file' => $file,
                    'type' => 'migrate',
                    'batch' => $batch,
                    'migrated_at' => date('Y-m-d H:i:s'),
                    'status' => 'migrated',
                ];

                $this->saveDeployData($deployData);
                $migrated[] = $file;
                $output && $output('migrated', "{$file} ({$elapsed}ms)");
            } catch (\Throwable $e) {
                $errors[] = ['file' => $file, 'error' => $e->getMessage()];
                $output && $output('error', "{$file}: {$e->getMessage()}");
                break; // Stop on first error to prevent cascading failures
            }
        }

        return ['migrated' => $migrated, 'errors' => $errors];
    }

    /**
     * Rollback the last batch of migrations.
     *
     * @param callable|null $output Optional callback for progress output
     * @param int|null $steps Number of batches to rollback (null = last batch only)
     * @return array{rolled_back: string[], errors: array}
     */
    public function rollback(?callable $output = null, ?int $steps = null): array
    {
        $deployData = $this->loadDeployData();
        $migrated = $this->getMigratedEntries($deployData);

        if (empty($migrated)) {
            $output && $output('info', 'Nothing to rollback.');
            return ['rolled_back' => [], 'errors' => []];
        }

        // Get last batch number
        $lastBatch = max(array_column($migrated, 'batch'));

        // Determine which batches to rollback
        $targetBatch = $steps !== null ? max(1, $lastBatch - $steps + 1) : $lastBatch;

        // Get migrations to rollback (reverse order)
        $toRollback = array_filter($migrated, fn($entry) => $entry['batch'] >= $targetBatch);
        $toRollback = array_reverse($toRollback);

        $rolledBack = [];
        $errors = [];

        foreach ($toRollback as $entry) {
            $file = $entry['file'];
            $filePath = $this->migrationsPath . $file;

            $output && $output('rolling_back', $file);

            try {
                if (!file_exists($filePath)) {
                    throw new \RuntimeException("Migration file not found: {$file}");
                }

                $migration = $this->resolveMigration($filePath);
                $migration->down();

                // Add rollback record
                $deployData[] = [
                    'file' => $file,
                    'type' => 'rollback',
                    'batch' => $entry['batch'],
                    'migrated_at' => date('Y-m-d H:i:s'),
                    'status' => 'rolled_back',
                ];

                // Remove the original migrate entry
                $deployData = array_values(array_filter($deployData, function ($item) use ($file) {
                    return !($item['file'] === $file && $item['type'] === 'migrate' && $item['status'] === 'migrated');
                }));

                $this->saveDeployData($deployData);
                $rolledBack[] = $file;
                $output && $output('rolled_back', $file);
            } catch (\Throwable $e) {
                $errors[] = ['file' => $file, 'error' => $e->getMessage()];
                $output && $output('error', "{$file}: {$e->getMessage()}");
                break;
            }
        }

        return ['rolled_back' => $rolledBack, 'errors' => $errors];
    }

    /**
     * Reset all migrations (rollback everything).
     *
     * @param callable|null $output Optional callback for progress output
     * @return array{rolled_back: string[], errors: array}
     */
    public function reset(?callable $output = null): array
    {
        $deployData = $this->loadDeployData();
        $migrated = $this->getMigratedEntries($deployData);

        if (empty($migrated)) {
            $output && $output('info', 'Nothing to reset.');
            return ['rolled_back' => [], 'errors' => []];
        }

        $maxBatch = max(array_column($migrated, 'batch'));
        return $this->rollback($output, $maxBatch);
    }

    /**
     * Drop all tables and re-run all migrations from scratch.
     *
     * @param callable|null $output Optional callback for progress output
     * @return array{migrated: string[], errors: array}
     */
    public function fresh(?callable $output = null): array
    {
        $output && $output('info', 'Dropping all tables...');

        // Drop all tables via raw SQL
        if (function_exists('db')) {
            try {
                db()->query('SET FOREIGN_KEY_CHECKS = 0')->execute();

                $result = db()->query('SHOW TABLES')->execute();
                $tables = [];
                if (is_array($result) && !empty($result)) {
                    foreach ($result as $row) {
                        $tables[] = array_values($row)[0];
                    }
                }

                foreach ($tables as $table) {
                    db()->query("DROP TABLE IF EXISTS `{$table}`")->execute();
                    $output && $output('dropped', $table);
                }

                db()->query('SET FOREIGN_KEY_CHECKS = 1')->execute();
            } catch (\Throwable $e) {
                $output && $output('error', "Failed to drop tables: {$e->getMessage()}");
                return ['migrated' => [], 'errors' => [['file' => 'fresh', 'error' => $e->getMessage()]]];
            }
        }

        // Reset deploy.json
        $this->saveDeployData([]);
        $output && $output('info', 'All tables dropped. Running migrations...');

        return $this->migrate($output);
    }

    // ─── Seeder Operations ───────────────────────────────────

    /**
     * Run pending seeders, or a specific seeder by name.
     *
     * @param string|null $specific Seeder filename (without .php) or null for all pending
     * @param callable|null $output Optional callback for progress output
     * @return array{seeded: string[], errors: array}
     */
    public function seed(?string $specific = null, ?callable $output = null): array
    {
        $deployData = $this->loadDeployData();

        if ($specific !== null) {
            // Run a specific seeder
            $file = str_ends_with($specific, '.php') ? $specific : $specific . '.php';

            // If the exact file doesn't exist, search for a prefixed match
            // e.g., "MasterRolesSeeder" → "20260308_001_MasterRolesSeeder.php"
            if (!file_exists($this->seedersPath . $file)) {
                $allSeeders = $this->getSeederFiles();
                $baseName = pathinfo($file, PATHINFO_FILENAME);
                foreach ($allSeeders as $seederFile) {
                    if (str_ends_with(pathinfo($seederFile, PATHINFO_FILENAME), $baseName)) {
                        $file = $seederFile;
                        break;
                    }
                }
            }

            return $this->runSingleSeeder($file, $deployData, $output);
        }

        // Run all pending seeders
        $pending = $this->getPendingSeeders($deployData);

        if (empty($pending)) {
            $output && $output('info', 'Nothing to seed.');
            return ['seeded' => [], 'errors' => []];
        }

        $seeded = [];
        $errors = [];

        foreach ($pending as $file) {
            $result = $this->runSingleSeeder($file, $deployData, $output);
            $seeded = array_merge($seeded, $result['seeded']);
            $errors = array_merge($errors, $result['errors']);

            if (!empty($result['errors'])) {
                break;
            }

            // Reload deploy data for next iteration
            $deployData = $this->loadDeployData();
        }

        return ['seeded' => $seeded, 'errors' => $errors];
    }

    /**
     * Run a single seeder file.
     */
    private function runSingleSeeder(string $file, array $deployData, ?callable $output): array
    {
        $filePath = $this->seedersPath . $file;

        if (!file_exists($filePath)) {
            $output && $output('error', "Seeder file not found: {$file}");
            return ['seeded' => [], 'errors' => [['file' => $file, 'error' => 'File not found']]];
        }

        // Check if already seeded
        $alreadySeeded = array_filter($deployData, function ($entry) use ($file) {
            return $entry['file'] === $file && $entry['type'] === 'seed' && $entry['status'] === 'seeded';
        });

        if (!empty($alreadySeeded)) {
            $output && $output('skipped', "{$file} (already seeded)");
            return ['seeded' => [], 'errors' => []];
        }

        $output && $output('seeding', $file);
        $startTime = microtime(true);

        try {
            $seeder = $this->resolveSeeder($filePath);
            $seeder->run();

            $elapsed = round((microtime(true) - $startTime) * 1000, 2);

            $deployData[] = [
                'file' => $file,
                'type' => 'seed',
                'batch' => 0,
                'migrated_at' => date('Y-m-d H:i:s'),
                'status' => 'seeded',
            ];

            $this->saveDeployData($deployData);
            $output && $output('seeded', "{$file} ({$elapsed}ms)");

            return ['seeded' => [$file], 'errors' => []];
        } catch (\Throwable $e) {
            $output && $output('error', "{$file}: {$e->getMessage()}");
            return ['seeded' => [], 'errors' => [['file' => $file, 'error' => $e->getMessage()]]];
        }
    }

    // ─── Status & Introspection ──────────────────────────────

    /**
     * Get the status of all migrations and seeders.
     *
     * @return array{migrations: array, seeders: array}
     */
    public function status(): array
    {
        $deployData = $this->loadDeployData();
        $migratedFiles = $this->getMigratedFileNames($deployData);
        $seededFiles = $this->getSeededFileNames($deployData);

        // Get all migration files
        $allMigrations = $this->getMigrationFiles();
        $allSeeders = $this->getSeederFiles();

        $migrations = [];
        foreach ($allMigrations as $file) {
            $entry = $this->findEntry($deployData, $file, 'migrate');
            $migrations[] = [
                'file' => $file,
                'status' => in_array($file, $migratedFiles) ? 'Migrated' : 'Pending',
                'batch' => $entry['batch'] ?? null,
                'migrated_at' => $entry['migrated_at'] ?? null,
            ];
        }

        $seeders = [];
        foreach ($allSeeders as $file) {
            $entry = $this->findEntry($deployData, $file, 'seed');
            $seeders[] = [
                'file' => $file,
                'status' => in_array($file, $seededFiles) ? 'Seeded' : 'Pending',
                'migrated_at' => $entry['migrated_at'] ?? null,
            ];
        }

        return ['migrations' => $migrations, 'seeders' => $seeders];
    }

    // ─── deploy.json Management ──────────────────────────────

    /**
     * Load the deploy.json tracking data.
     */
    private function loadDeployData(): array
    {
        if (!file_exists($this->deployFile)) {
            return [];
        }

        $content = file_get_contents($this->deployFile);

        if ($content === false || trim($content) === '') {
            return [];
        }

        $data = json_decode($content, true);

        if (!is_array($data)) {
            return [];
        }

        return $data;
    }

    /**
     * Save the deploy.json tracking data.
     */
    private function saveDeployData(array $data): void
    {
        $dir = dirname($this->deployFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        file_put_contents($this->deployFile, $json, LOCK_EX);
    }

    // ─── File Discovery ──────────────────────────────────────

    /**
     * Get all migration files (sorted by filename).
     *
     * @return string[] Filenames only (not full paths)
     */
    private function getMigrationFiles(): array
    {
        if (!is_dir($this->migrationsPath)) {
            return [];
        }

        $files = glob($this->migrationsPath . '*.php');
        $files = array_map('basename', $files);
        sort($files); // Sort by filename (timestamp prefix ensures correct order)

        return $files;
    }

    /**
     * Get all seeder files (sorted by filename).
     *
     * @return string[] Filenames only
     */
    private function getSeederFiles(): array
    {
        if (!is_dir($this->seedersPath)) {
            return [];
        }

        $files = glob($this->seedersPath . '*.php');
        $files = array_map('basename', $files);
        sort($files);

        return $files;
    }

    /**
     * Get pending (un-run) migration files.
     */
    private function getPendingMigrations(array $deployData): array
    {
        $migratedFiles = $this->getMigratedFileNames($deployData);
        $allFiles = $this->getMigrationFiles();

        return array_values(array_diff($allFiles, $migratedFiles));
    }

    /**
     * Get pending (un-run) seeder files.
     */
    private function getPendingSeeders(array $deployData): array
    {
        $seededFiles = $this->getSeededFileNames($deployData);
        $allFiles = $this->getSeederFiles();

        return array_values(array_diff($allFiles, $seededFiles));
    }

    // ─── Deploy Data Helpers ─────────────────────────────────

    /**
     * Get entries that are currently migrated (not rolled back).
     */
    private function getMigratedEntries(array $deployData): array
    {
        return array_values(array_filter($deployData, function ($entry) {
            return $entry['type'] === 'migrate' && $entry['status'] === 'migrated';
        }));
    }

    /**
     * Get filenames that are currently migrated.
     *
     * @return string[]
     */
    private function getMigratedFileNames(array $deployData): array
    {
        return array_map(
            fn($entry) => $entry['file'],
            $this->getMigratedEntries($deployData)
        );
    }

    /**
     * Get filenames that are currently seeded.
     *
     * @return string[]
     */
    private function getSeededFileNames(array $deployData): array
    {
        $seeded = array_filter($deployData, function ($entry) {
            return $entry['type'] === 'seed' && $entry['status'] === 'seeded';
        });

        return array_map(fn($entry) => $entry['file'], $seeded);
    }

    /**
     * Find a deploy entry by file and type.
     */
    private function findEntry(array $deployData, string $file, string $type): ?array
    {
        foreach ($deployData as $entry) {
            if ($entry['file'] === $file && $entry['type'] === $type &&
                in_array($entry['status'], ['migrated', 'seeded'], true)) {
                return $entry;
            }
        }
        return null;
    }

    /**
     * Get the next batch number.
     */
    private function getNextBatch(array $deployData): int
    {
        $migrated = $this->getMigratedEntries($deployData);
        if (empty($migrated)) {
            return 1;
        }
        return max(array_column($migrated, 'batch')) + 1;
    }

    // ─── File Resolution ─────────────────────────────────────

    /**
     * Resolve a migration file into a Migration instance.
     *
     * @throws \RuntimeException If the file doesn't return a Migration instance
     */
    private function resolveMigration(string $filePath): Migration
    {
        $instance = require $filePath;

        if (!$instance instanceof Migration) {
            throw new \RuntimeException(
                "Migration file '{$filePath}' must return an instance of " . Migration::class .
                '. Got: ' . (is_object($instance) ? get_class($instance) : gettype($instance))
            );
        }

        return $instance;
    }

    /**
     * Resolve a seeder file into a Seeder instance.
     *
     * @throws \RuntimeException If the file doesn't return a Seeder instance
     */
    private function resolveSeeder(string $filePath): Seeder
    {
        $instance = require $filePath;

        if (!$instance instanceof Seeder) {
            throw new \RuntimeException(
                "Seeder file '{$filePath}' must return an instance of " . Seeder::class .
                '. Got: ' . (is_object($instance) ? get_class($instance) : gettype($instance))
            );
        }

        return $instance;
    }

    // ─── Accessors ───────────────────────────────────────────

    /**
     * Get the migrations path.
     */
    public function getMigrationsPath(): string
    {
        return $this->migrationsPath;
    }

    /**
     * Get the seeders path.
     */
    public function getSeedersPath(): string
    {
        return $this->seedersPath;
    }

    /**
     * Get the deploy file path.
     */
    public function getDeployFile(): string
    {
        return $this->deployFile;
    }
}
