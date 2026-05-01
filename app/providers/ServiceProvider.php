<?php

namespace App\Providers;

abstract class ServiceProvider
{
    protected array $config;
    protected array $runtimeState;

    public function __construct(array $config = [], array $runtimeState = [])
    {
        $this->config = $config;
        $this->runtimeState = $runtimeState;
    }

    public function register(): void
    {
    }

    public function boot(): void
    {
    }
}