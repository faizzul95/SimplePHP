<?php

namespace App\Providers;

use Core\Auth\AuthManager;

class AuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        register_framework_service('auth.login_policy', fn() => new \Core\Auth\LoginPolicy((array) ($this->config['auth'] ?? [])));
        register_framework_service('auth.authorization', fn() => new \Core\Auth\AuthorizationService((array) ($this->config['auth'] ?? [])));
        register_framework_service('auth.tokens', fn() => new \Core\Auth\TokenService((array) ($this->config['auth'] ?? [])));
        register_framework_service('auth.access_credentials', fn() => new \Core\Auth\AccessCredentialService((array) ($this->config['auth'] ?? [])));
        register_framework_service('auth', fn() => new AuthManager(
            (array) ($this->config['auth'] ?? []),
            framework_service('auth.login_policy'),
            framework_service('auth.authorization'),
            framework_service('auth.tokens'),
            framework_service('auth.access_credentials')
        ));
    }
}