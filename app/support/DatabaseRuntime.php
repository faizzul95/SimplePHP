<?php

namespace App\Support;

use Core\Database\Database;
use Core\Database\BaseDatabase;
use RuntimeException;

class DatabaseRuntime
{
    protected array $config;
    protected array $connectionRegistry = [];
    protected array $managers = [];
    protected array $loadedScopeMacros = [];
    protected bool $registryInitialized = false;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    public function connectionConfig(string $connectionName): ?array
    {
        $this->initializeRegistry();

        return $this->connectionRegistry[$this->normalizeConnectionName($connectionName)] ?? null;
    }

    public function manager(string $connectionName): BaseDatabase
    {
        $normalizedName = $this->normalizeConnectionName($connectionName);
        if (isset($this->managers[$normalizedName]) && $this->managers[$normalizedName] instanceof BaseDatabase) {
            return $this->managers[$normalizedName];
        }

        $connection = $this->connectionConfig($normalizedName);
        if ($connection === null) {
            throw new RuntimeException('Database connection not configured: ' . $normalizedName);
        }

        $database = new Database((string) $connection['driver']);
        $manager = $database->raw();

        $manager->addConnection($normalizedName, (array) $connection['config']);

        if (!empty($this->config['db']['profiling']['enabled'])) {
            $manager->setProfilingEnabled(true);
        }

        $this->managers[$normalizedName] = $manager;

        return $manager;
    }

    public function connection(string $connectionName = 'default')
    {
        $normalizedName = $this->normalizeConnectionName($connectionName);
        $connection = $this->manager($normalizedName)->connect($normalizedName);
        $this->loadScopeMacros($connection, $normalizedName);

        return $connection;
    }

    protected function initializeRegistry(): void
    {
        if ($this->registryInitialized) {
            return;
        }

        $dbConfig = (array) ($this->config['db'] ?? []);
        if (empty($dbConfig)) {
            $this->fail('Database configuration is missing or invalid.');
        }

        $environment = $this->normalizeEnvironment();

        foreach ($dbConfig as $connectionName => $envConfigs) {
            if ($connectionName === 'cache' || !is_array($envConfigs) || !isset($envConfigs[$environment]) || !is_array($envConfigs[$environment])) {
                continue;
            }

            $driver = strtolower(trim((string) ($envConfigs[$environment]['driver'] ?? '')));
            if ($driver === '') {
                $this->logError('Database driver missing for connection: ' . $connectionName);
                continue;
            }

            $this->connectionRegistry[strtolower((string) $connectionName)] = [
                'driver' => $driver,
                'config' => $envConfigs[$environment],
            ];
        }

        if (empty($this->connectionRegistry)) {
            $this->fail('No usable database connections were found for environment: ' . $environment);
        }

        $this->registryInitialized = true;
    }

    protected function normalizeEnvironment(): string
    {
        $configuredEnvironment = trim((string) ($this->config['environment'] ?? ''));
        $environment = $configuredEnvironment !== ''
            ? $configuredEnvironment
            : (defined('ENVIRONMENT') ? ENVIRONMENT : 'development');
        $allowed = ['development', 'staging', 'production'];

        if (!in_array($environment, $allowed, true)) {
            $this->fail("Environment '" . $environment . "' is not recognized. Please check your configuration.");
        }

        return $environment;
    }

    protected function normalizeConnectionName(string $connectionName): string
    {
        $normalizedName = strtolower(trim($connectionName));

        return $normalizedName !== '' ? $normalizedName : 'default';
    }

    protected function loadScopeMacros($connection, string $connectionName): void
    {
        if (isset($this->loadedScopeMacros[$connectionName]) || empty($connection) || !function_exists('loadScopeMacroDBFunctions')) {
            return;
        }

        $scopeMacroConfig = (array) ($this->config['framework']['scope_macro'] ?? []);
        $scopeMacroBasePath = ROOT_DIR . ($scopeMacroConfig['base_path'] ?? 'app/database/');

        loadScopeMacroDBFunctions(
            $connection,
            (array) ($scopeMacroConfig['files'] ?? []),
            (array) ($scopeMacroConfig['folders'] ?? ['ScopeControllers']),
            $scopeMacroBasePath,
            false
        );

        $this->loadedScopeMacros[$connectionName] = true;
    }

    protected function fail(string $message, ?\Throwable $previous = null): never
    {
        if (function_exists('bootstrapFail')) {
            bootstrapFail($message, 500, $previous);
        }

        error_log($message . ($previous !== null ? ' :: ' . $previous->getMessage() : ''));
        throw new RuntimeException($message, 0, $previous);
    }

    protected function logError(string $message): void
    {
        if (function_exists('logger')) {
            logger()->log_error($message);
            return;
        }

        error_log($message);
    }
}