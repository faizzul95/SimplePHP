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
    protected array $routingRegistry = [];

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

        foreach (($this->routingRegistry[$normalizedName]['aliases'] ?? []) as $alias => $aliasConfig) {
            $manager->addConnection((string) $alias, (array) $aliasConfig);
        }

        if (method_exists($manager, 'configureReadWriteRouting') && isset($this->routingRegistry[$normalizedName])) {
            $routing = $this->routingRegistry[$normalizedName];
            $manager->configureReadWriteRouting(
                $normalizedName,
                (array) ($routing['read'] ?? []),
                (string) ($routing['write'] ?? $normalizedName),
                (bool) ($routing['sticky'] ?? true)
            );
        }

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

            $normalizedName = strtolower((string) $connectionName);
            $normalizedConfig = $this->normalizeConnectionConfig($normalizedName, $envConfigs[$environment]);
            $driver = strtolower(trim((string) ($normalizedConfig['driver'] ?? '')));
            if ($driver === '') {
                $this->logError('Database driver missing for connection: ' . $connectionName);
                continue;
            }

            $this->connectionRegistry[$normalizedName] = [
                'driver' => $driver,
                'config' => $normalizedConfig,
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

    protected function normalizeConnectionConfig(string $connectionName, array $config): array
    {
        $readPool = $this->normalizeReplicaPool($config['read'] ?? null);
        $writeConfig = $this->normalizeReplicaEndpoint($config['write'] ?? null);
        $sticky = array_key_exists('sticky', $config) ? (bool) $config['sticky'] : true;

        unset($config['read'], $config['write'], $config['sticky']);

        if ($writeConfig !== null) {
            $config = array_replace($config, $writeConfig);
        }

        if ($connectionName === 'default' && $readPool === []) {
            $legacyRead = $this->legacyReplicaConfig();
            if ($legacyRead !== null) {
                $readPool[] = $legacyRead;
                $sticky = true;
            }
        }

        if ($readPool !== []) {
            $writeAlias = $connectionName . '::write';
            $readAliases = [];
            $aliases = [
                $writeAlias => $config,
            ];

            foreach (array_values($readPool) as $index => $readConfig) {
                $alias = $connectionName . '::read:' . ($index + 1);
                $aliases[$alias] = array_replace($config, $readConfig);
                $readAliases[] = $alias;
            }

            $this->routingRegistry[$connectionName] = [
                'aliases' => $aliases,
                'read' => $readAliases,
                'write' => $writeAlias,
                'sticky' => $sticky,
            ];
        }

        return $config;
    }

    protected function normalizeReplicaPool(mixed $readConfig): array
    {
        if (!is_array($readConfig)) {
            return [];
        }

        $pool = [];
        foreach ($readConfig as $key => $value) {
            if (is_array($value)) {
                $normalized = $this->normalizeReplicaEndpoint($value);
                if ($normalized !== null) {
                    $pool[] = $normalized;
                }
                continue;
            }

            if (is_string($key)) {
                $normalized = $this->normalizeReplicaEndpoint($readConfig);
                if ($normalized !== null) {
                    $pool[] = $normalized;
                }
                break;
            }
        }

        return $pool;
    }

    protected function normalizeReplicaEndpoint(mixed $config): ?array
    {
        if (!is_array($config)) {
            return null;
        }

        $normalized = [];
        foreach ($config as $key => $value) {
            if (in_array($key, ['read', 'write', 'sticky'], true)) {
                continue;
            }

            $normalized[$key] = $value;
        }

        return $normalized === [] ? null : $normalized;
    }

    protected function legacyReplicaConfig(): ?array
    {
        $legacy = $this->connectionRegistry['slave']['config'] ?? null;

        return is_array($legacy) ? $legacy : null;
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