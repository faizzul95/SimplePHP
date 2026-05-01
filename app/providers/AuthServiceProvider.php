<?php

namespace App\Providers;

use App\Support\Auth\AuthManager;

class AuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        register_framework_service('auth.login_policy', fn() => new \App\Support\Auth\LoginPolicy((array) ($this->config['auth'] ?? [])));
        register_framework_service('auth.authorization', fn() => new \App\Support\Auth\AuthorizationService((array) ($this->config['auth'] ?? [])));
        register_framework_service('auth.tokens', fn() => new \App\Support\Auth\TokenService((array) ($this->config['auth'] ?? [])));
        register_framework_service('auth.access_credentials', fn() => new \App\Support\Auth\AccessCredentialService((array) ($this->config['auth'] ?? [])));
        register_framework_service('auth', fn() => new AuthManager(
            (array) ($this->config['auth'] ?? []),
            framework_service('auth.login_policy'),
            framework_service('auth.authorization'),
            framework_service('auth.tokens'),
            framework_service('auth.access_credentials')
        ));
    }
}