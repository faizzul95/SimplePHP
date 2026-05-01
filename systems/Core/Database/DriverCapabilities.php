<?php

namespace Core\Database;

class DriverCapabilities
{
    public function __construct(
        private string $driver,
        private array $features = [],
        private string $label = ''
    ) {
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function label(): string
    {
        return $this->label !== '' ? $this->label : strtoupper($this->driver);
    }

    public function supports(string $feature): bool
    {
        return (bool) ($this->features[strtolower(trim($feature))] ?? false);
    }

    public function all(): array
    {
        return $this->features;
    }
}