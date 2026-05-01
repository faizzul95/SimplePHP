<?php

namespace App\Support\Auth;

class AuthMethodResolver
{
    private const ALIASES = [
        'web' => 'session',
        'api' => 'token',
        'session' => 'session',
        'token' => 'token',
        'jwt' => 'jwt',
        'api_key' => 'api_key',
        'apikey' => 'api_key',
        'oauth' => 'oauth',
        'oauth2' => 'oauth2',
        'basic' => 'basic',
        'digest' => 'digest',
    ];

    public function normalize(array|string|null $methods, array $default = ['session']): array
    {
        $rawMethods = $methods;

        if ($rawMethods === null) {
            $rawMethods = $default;
        }

        if (is_string($rawMethods)) {
            $rawMethods = str_contains($rawMethods, ',')
                ? array_map('trim', explode(',', $rawMethods))
                : [trim($rawMethods)];
        }

        if (!is_array($rawMethods) || $rawMethods === []) {
            $rawMethods = $default;
        }

        $normalized = [];
        foreach ($rawMethods as $method) {
            $name = strtolower(trim((string) $method));
            if ($name === '' || !isset(self::ALIASES[$name])) {
                continue;
            }

            $normalized[] = self::ALIASES[$name];
        }

        if ($normalized === []) {
            return $default === [] ? ['session'] : array_values(array_unique($default));
        }

        return array_values(array_unique($normalized));
    }

    public function resolveGuardMethods(array|string|null $guard, array $defaultMethods, array $apiMethods = ['token']): array
    {
        if ($guard === null) {
            return $defaultMethods;
        }

        if (is_array($guard)) {
            return $this->normalize($guard, $defaultMethods);
        }

        $normalized = strtolower(trim($guard));
        if ($normalized === '' || $normalized === 'default') {
            return $defaultMethods;
        }

        return match ($normalized) {
            'web', 'session' => ['session'],
            'api' => $this->normalize($apiMethods, ['token']),
            default => $this->normalize([$normalized], $defaultMethods),
        };
    }
}