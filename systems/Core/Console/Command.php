<?php

namespace Core\Console;

abstract class Command
{
    abstract public function name(): string;

    public function description(): string
    {
        return '';
    }

    abstract public function handle(array $args, array $options, Kernel $console): int;

    public function register(Kernel $kernel): void
    {
        $kernel->command($this->name(), function (array $args = [], array $options = []) use ($kernel) {
            return $this->handle($args, $options, $kernel);
        }, $this->description());
    }
}