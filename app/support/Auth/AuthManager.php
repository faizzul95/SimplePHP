<?php

namespace App\Support\Auth;

use App\Support\Auth\AuthorizationService;
use App\Support\Auth\AccessCredentialService;
use App\Support\Auth\AuthMethodResolver;
use App\Support\Auth\LoginPolicy;
use App\Support\Auth\TokenService;
use Components\Auth;

class AuthManager extends Auth
{
    /** @var array<string, AuthGuard> */
    private array $guards = [];
    private AuthMethodResolver $methodResolver;

    public function __construct(
        private array $resolvedConfig = [],
        ?LoginPolicy $loginPolicy = null,
        ?AuthorizationService $authorizationService = null,
        ?TokenService $tokenService = null,
        ?AccessCredentialService $accessCredentialService = null
    ) {
        parent::__construct($resolvedConfig, $loginPolicy, $authorizationService, $tokenService, $accessCredentialService);
        $this->methodResolver = new AuthMethodResolver();
    }

    public function methods(array|string|null $methods = null): array
    {
        return $this->methodResolver->normalize($methods, (array) ($this->resolvedConfig['methods'] ?? ['session']));
    }

    public function guard(array|string|null $guard = null): AuthGuard
    {
        $methods = $this->resolveGuardMethods($guard);
        $cacheKey = implode('|', $methods);

        if (!isset($this->guards[$cacheKey])) {
            $this->guards[$cacheKey] = new AuthGuard($this, $methods);
        }

        return $this->guards[$cacheKey];
    }

    private function resolveGuardMethods(array|string|null $guard = null): array
    {
        return $this->methodResolver->resolveGuardMethods($guard, $this->methods(), $this->apiMethods());
    }
}