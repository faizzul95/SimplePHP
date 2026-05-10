<?php

namespace App\Support\Auth;

class LoginPolicy
{
    private array $config;
    private array $lastAttemptStatus = [];

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    public function policy(): array
    {
        return (array) ($this->config['systems_login_policy'] ?? []);
    }

    public function lastAttemptStatus(): array
    {
        return $this->lastAttemptStatus;
    }

    public function resetAttemptStatus(): void
    {
        $this->lastAttemptStatus = [];
    }

    public function setAttemptStatus(string $reason, string $message, int $httpCode = 401, array $context = []): void
    {
        $this->lastAttemptStatus = [
            'reason' => $reason,
            'message' => $message,
            'http_code' => $httpCode,
            'context' => $context,
        ];
    }

    public function denyLockedAttempt(array $lockState): bool
    {
        $lockUntil = (int) ($lockState['locked_until_ts'] ?? 0);
        $this->setAttemptStatus('login_locked', 'Too many login attempts. Please try again later.', 423, [
            'locked_until' => $lockUntil > 0 ? date('Y-m-d H:i:s', $lockUntil) : null,
            'retry_after' => max(1, (int) ($lockState['retry_after'] ?? 1)),
            'scope' => (string) ($lockState['scope'] ?? 'unknown'),
            'scopes' => array_values(array_unique(array_filter(array_map(static function ($scope): string {
                return trim((string) $scope);
            }, (array) ($lockState['scopes'] ?? [])), static function ($scope): bool {
                return $scope !== '';
            }))),
        ]);

        return false;
    }

    public function identifier(array $credentials): string
    {
        $policy = $this->policy();
        $fields = (array) ($policy['identifier_fields'] ?? ['email', 'username']);

        foreach ($fields as $field) {
            $name = strtolower(trim((string) $field));
            if ($name === '' || !array_key_exists($name, $credentials)) {
                continue;
            }

            $value = strtolower(trim((string) $credentials[$name]));
            if ($value !== '') {
                return $name . ':' . $value;
            }
        }

        return '';
    }

    public function passwordHashConfiguration(): array
    {
        $policy = $this->policy();
        $hashing = (array) ($policy['password_hashing'] ?? []);
        $algorithmName = strtolower(trim((string) ($hashing['algorithm'] ?? 'default')));

        return match ($algorithmName) {
            'bcrypt' => [
                'enabled' => ($hashing['enabled'] ?? true) === true,
                'algorithm' => PASSWORD_BCRYPT,
                'uses_framework_default' => false,
                'options' => [
                    'cost' => max(4, (int) ($hashing['bcrypt_rounds'] ?? 12)),
                ],
            ],
            'argon2i' => [
                'enabled' => defined('PASSWORD_ARGON2I') && ($hashing['enabled'] ?? true) === true,
                'algorithm' => defined('PASSWORD_ARGON2I') ? PASSWORD_ARGON2I : PASSWORD_DEFAULT,
                'uses_framework_default' => false,
                'options' => [
                    'memory_cost' => max(1024, (int) ($hashing['argon_memory_cost'] ?? PASSWORD_ARGON2_DEFAULT_MEMORY_COST)),
                    'time_cost' => max(1, (int) ($hashing['argon_time_cost'] ?? PASSWORD_ARGON2_DEFAULT_TIME_COST)),
                    'threads' => max(1, (int) ($hashing['argon_threads'] ?? PASSWORD_ARGON2_DEFAULT_THREADS)),
                ],
            ],
            'argon2id' => [
                'enabled' => defined('PASSWORD_ARGON2ID') && ($hashing['enabled'] ?? true) === true,
                'algorithm' => defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT,
                'uses_framework_default' => false,
                'options' => [
                    'memory_cost' => max(1024, (int) ($hashing['argon_memory_cost'] ?? PASSWORD_ARGON2_DEFAULT_MEMORY_COST)),
                    'time_cost' => max(1, (int) ($hashing['argon_time_cost'] ?? PASSWORD_ARGON2_DEFAULT_TIME_COST)),
                    'threads' => max(1, (int) ($hashing['argon_threads'] ?? PASSWORD_ARGON2_DEFAULT_THREADS)),
                ],
            ],
            default => [
                'enabled' => ($hashing['enabled'] ?? true) === true,
                'algorithm' => PASSWORD_DEFAULT,
                'uses_framework_default' => true,
                'options' => [],
            ],
        };
    }

    public function maybeRefreshPasswordHash(int $userId, string $plainPassword, string $currentHash, array $user, callable $persist): void
    {
        if ($userId < 1 || $plainPassword === '' || $currentHash === '') {
            return;
        }

        $configuration = $this->passwordHashConfiguration();
        if (($configuration['enabled'] ?? false) !== true) {
            return;
        }

        $algorithm = $configuration['algorithm'] ?? PASSWORD_DEFAULT;
        $options = (array) ($configuration['options'] ?? []);
        $usesFrameworkDefault = ($configuration['uses_framework_default'] ?? false) === true;

        if (!password_needs_rehash($currentHash, $algorithm, $options)) {
            return;
        }

        // Preserve explicit algorithms like bcrypt even when PASSWORD_DEFAULT
        // currently aliases to the same constant value on this PHP runtime.
        $rehash = $usesFrameworkDefault
            ? \Core\Security\Hasher::make($plainPassword)
            : password_hash($plainPassword, $algorithm, $options);

        if (!is_string($rehash) || $rehash === '') {
            return;
        }

        $persist($userId, $rehash, $user);
    }

    public function passesPasswordRotationPolicy(array $user, callable $safeTable, callable $safeColumn, callable $tableAndColumnsExist): bool
    {
        $policy = $this->policy();
        $rotation = (array) ($policy['password_rotation'] ?? []);
        if (($rotation['enabled'] ?? false) !== true) {
            return true;
        }

        $usersTable = $safeTable((string) ($this->config['users_table'] ?? 'users'));
        $uc = (array) ($this->config['user_columns'] ?? []);
        $forceResetColumn = $safeColumn(
            (string) ($rotation['force_reset_column'] ?? ($uc['force_password_change'] ?? 'force_password_change')),
            'force_password_change'
        );

        if ($tableAndColumnsExist($usersTable, [$forceResetColumn]) && !empty($user[$forceResetColumn])) {
            $this->setAttemptStatus('password_change_required', 'Your password must be changed before you can continue.', 403, [
                'force_password_change' => true,
            ]);
            return false;
        }

        $maxAgeDays = max(0, (int) ($rotation['max_age_days'] ?? 90));
        if ($maxAgeDays < 1) {
            return true;
        }

        $changedAtColumn = $safeColumn(
            (string) ($rotation['password_changed_at_column'] ?? ($uc['password_changed_at'] ?? 'password_changed_at')),
            'password_changed_at'
        );
        $hasChangedAtColumn = $tableAndColumnsExist($usersTable, [$changedAtColumn]);
        $requireChangedAt = ($rotation['require_password_changed_at'] ?? false) === true;

        if (!$hasChangedAtColumn) {
            return !$requireChangedAt;
        }

        $changedAt = trim((string) ($user[$changedAtColumn] ?? ''));
        if ($changedAt === '') {
            if ($requireChangedAt) {
                $this->setAttemptStatus('password_change_required', 'Your password must be updated before you can continue.', 403, [
                    'password_changed_at_missing' => true,
                ]);
                return false;
            }

            return true;
        }

        $changedAtTimestamp = strtotime($changedAt);
        if ($changedAtTimestamp === false) {
            return !$requireChangedAt;
        }

        $maxAgeSeconds = $maxAgeDays * 86400;
        if (($changedAtTimestamp + $maxAgeSeconds) < time()) {
            $this->setAttemptStatus('password_expired', 'Your password has expired and must be changed.', 403, [
                'password_expired' => true,
                'max_age_days' => $maxAgeDays,
                'password_changed_at' => $changedAt,
            ]);
            return false;
        }

        return true;
    }
}