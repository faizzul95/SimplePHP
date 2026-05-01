<?php

namespace Core\Filesystem;

use InvalidArgumentException;

class StorageManager
{
    private array $config;
    private array $disks = [];
    private array $driverFactories = [];

    public function __construct(array $config = [])
    {
        $defaults = [
            'default' => 'local',
            'drivers' => [
                'local' => [
                    'adapter' => 'Core\\Filesystem\\LocalFilesystemAdapter',
                ],
            ],
            'disks' => [
                'local' => [
                    'driver' => 'local',
                    'root' => 'storage/app',
                ],
                'public' => [
                    'driver' => 'local',
                    'root' => 'storage/public',
                    'url' => '/storage',
                ],
                'private' => [
                    'driver' => 'local',
                    'root' => 'storage/private',
                ],
            ],
        ];

        $this->config = array_replace_recursive($defaults, $config);
        $this->registerConfiguredDrivers((array) ($this->config['drivers'] ?? []));
    }

    public function disk(?string $name = null): FilesystemAdapterInterface
    {
        $name = trim((string) ($name ?? $this->config['default'] ?? 'local'));
        if ($name === '') {
            throw new InvalidArgumentException('Filesystem disk name cannot be empty.');
        }

        if (isset($this->disks[$name])) {
            return $this->disks[$name];
        }

        $diskConfig = $this->config['disks'][$name] ?? null;
        if (!is_array($diskConfig)) {
            throw new InvalidArgumentException('Filesystem disk [' . $name . '] is not configured.');
        }

        $driver = strtolower(trim((string) ($diskConfig['driver'] ?? 'local')));
        if (!isset($this->driverFactories[$driver])) {
            throw new InvalidArgumentException('Filesystem driver [' . $driver . '] is not supported.');
        }

        return $this->disks[$name] = ($this->driverFactories[$driver])($diskConfig);
    }

    public function registerDriver(string $name, callable $factory): self
    {
        $normalizedName = strtolower(trim($name));
        if ($normalizedName === '') {
            throw new InvalidArgumentException('Filesystem driver name cannot be empty.');
        }

        $this->driverFactories[$normalizedName] = $factory;

        return $this;
    }

    private function registerConfiguredDrivers(array $drivers): void
    {
        foreach ($drivers as $name => $driverConfig) {
            if (!is_array($driverConfig)) {
                continue;
            }

            $adapterClass = trim((string) ($driverConfig['adapter'] ?? ''));
            if ($adapterClass === '') {
                continue;
            }

            $this->registerDriver((string) $name, static function (array $diskConfig) use ($adapterClass, $driverConfig): FilesystemAdapterInterface {
                if (!class_exists($adapterClass)) {
                    throw new InvalidArgumentException('Filesystem adapter class [' . $adapterClass . '] was not found.');
                }

                $adapterConfig = array_replace_recursive($driverConfig, $diskConfig);
                $adapter = new $adapterClass($adapterConfig);

                if (!$adapter instanceof FilesystemAdapterInterface) {
                    throw new InvalidArgumentException('Filesystem adapter [' . $adapterClass . '] must implement FilesystemAdapterInterface.');
                }

                return $adapter;
            });
        }
    }

    public function __call(string $method, array $arguments)
    {
        return $this->disk()->{$method}(...$arguments);
    }
}