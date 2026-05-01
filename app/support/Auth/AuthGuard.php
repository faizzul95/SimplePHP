<?php

namespace App\Support\Auth;

class AuthGuard
{
    public function __construct(private AuthManager $manager, private array $methods)
    {
    }

    public function methods(): array
    {
        return $this->methods;
    }

    public function check(): bool
    {
        return $this->manager->check($this->methods);
    }

    public function via(): ?string
    {
        return $this->manager->via($this->methods);
    }

    public function id(): ?int
    {
        return $this->manager->id($this->methods);
    }

    public function user(): ?array
    {
        return $this->manager->user($this->methods);
    }

    public function debugState(): array
    {
        return $this->manager->debugAuthState($this->methods);
    }
}