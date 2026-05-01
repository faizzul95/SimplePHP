<?php

namespace Components;

class FeatureManager
{
    private array $flags = [];
    private array $overrides = [];

    public function __construct(array $flags = [])
    {
        $this->flags = $flags;
    }

    public function all(): array
    {
        return $this->flags;
    }

    public function value(string $key, mixed $default = null, mixed $context = []): mixed
    {
        if (!$this->enabled($key, false, $context)) {
            return $default;
        }

        $normalizedKey = $this->normalizeKey($key);
        if ($normalizedKey === '') {
            return $default;
        }

        $definition = $this->flags[$normalizedKey] ?? null;
        if (is_array($definition) && array_key_exists('value', $definition)) {
            return $definition['value'];
        }

        return $definition ?? $default;
    }

    public function override(string $key, bool $enabled): self
    {
        $normalizedKey = $this->normalizeKey($key);
        if ($normalizedKey !== '') {
            $this->overrides[$normalizedKey] = $enabled;
        }

        return $this;
    }

    public function clearOverride(?string $key = null): self
    {
        if ($key === null) {
            $this->overrides = [];
            return $this;
        }

        unset($this->overrides[$this->normalizeKey($key)]);

        return $this;
    }

    public function enabled(string $key, bool $default = false, mixed $context = []): bool
    {
        $normalizedKey = $this->normalizeKey($key);
        if ($normalizedKey === '') {
            return $default;
        }

        $context = $this->normalizeContext($context);

        if (array_key_exists($normalizedKey, $this->overrides)) {
            return $this->overrides[$normalizedKey];
        }

        if (!array_key_exists($normalizedKey, $this->flags)) {
            return $default;
        }

        $definition = $this->flags[$normalizedKey];
        if (is_bool($definition)) {
            return $definition;
        }

        if (!is_array($definition)) {
            return $default;
        }

        $enabled = array_key_exists('enabled', $definition) ? (bool) $definition['enabled'] : $default;
        if (!$enabled) {
            return false;
        }

        $environment = strtolower(trim((string) ($context['environment'] ?? (defined('ENVIRONMENT') ? ENVIRONMENT : 'production'))));
        $allowedEnvironments = $this->normalizeStringList($definition['environments'] ?? []);
        if (!empty($allowedEnvironments) && !in_array($environment, $allowedEnvironments, true)) {
            return false;
        }

        $blockedEnvironments = $this->normalizeStringList($definition['except_environments'] ?? []);
        if (!empty($blockedEnvironments) && in_array($environment, $blockedEnvironments, true)) {
            return false;
        }

        if (array_key_exists('actors', $definition)) {
            $actorId = (string) ($context['actor'] ?? $context['actor_id'] ?? '');
            $allowedActors = array_map('strval', (array) $definition['actors']);
            if ($actorId === '' || !in_array($actorId, $allowedActors, true)) {
                return false;
            }
        }

        if (array_key_exists('roles', $definition)) {
            $allowedRoles = $this->normalizeStringList($definition['roles']);
            $contextRoles = $this->normalizeStringList($context['roles'] ?? []);
            if (empty(array_intersect($allowedRoles, $contextRoles))) {
                return false;
            }
        }

        if (array_key_exists('abilities', $definition) || array_key_exists('permissions', $definition)) {
            $requiredAbilities = $this->normalizeStringList($definition['abilities'] ?? $definition['permissions'] ?? []);
            $contextAbilities = $this->normalizeStringList($context['abilities'] ?? $context['permissions'] ?? []);
            if (empty(array_intersect($requiredAbilities, $contextAbilities))) {
                return false;
            }
        }

        if (!$this->passesDateWindow($definition, $context)) {
            return false;
        }

        if (array_key_exists('percentage', $definition)) {
            $percentage = max(0, min(100, (int) $definition['percentage']));
            if ($percentage === 0) {
                return false;
            }

            if ($percentage < 100) {
                $actorId = (string) ($context['actor'] ?? $context['actor_id'] ?? '');
                if ($actorId === '') {
                    return false;
                }

                $bucket = $this->actorBucket($normalizedKey, $actorId);
                if ($bucket >= $percentage) {
                    return false;
                }
            }
        }

        return true;
    }

    public function disabled(string $key, bool $default = false, mixed $context = []): bool
    {
        return !$this->enabled($key, $default, $context);
    }

    private function normalizeKey(string $key): string
    {
        return strtolower(trim($key));
    }

    private function normalizeContext(mixed $context): array
    {
        if (is_scalar($context) && !is_bool($context)) {
            return ['actor' => (string) $context];
        }

        if (is_object($context)) {
            $context = get_object_vars($context);
        }

        if (!is_array($context)) {
            return [];
        }

        if (isset($context['user'])) {
            $user = $context['user'];
            if (is_object($user)) {
                $user = get_object_vars($user);
            }

            if (is_array($user)) {
                $context['actor'] = $context['actor'] ?? $context['actor_id'] ?? $user['id'] ?? $user['user_id'] ?? null;
                $context['roles'] = $context['roles'] ?? $user['roles'] ?? [];
                $context['abilities'] = $context['abilities'] ?? $context['permissions'] ?? $user['abilities'] ?? $user['permissions'] ?? [];
            }
        }

        return $context;
    }

    private function normalizeStringList(mixed $values): array
    {
        $values = is_array($values) ? $values : [$values];
        $normalized = [];

        foreach ($values as $value) {
            $stringValue = strtolower(trim((string) $value));
            if ($stringValue === '') {
                continue;
            }

            $normalized[] = $stringValue;
        }

        return array_values(array_unique($normalized));
    }

    private function passesDateWindow(array $definition, array $context): bool
    {
        $now = $this->resolveTimestamp($context['now'] ?? 'now');
        if ($now === null) {
            $now = time();
        }

        $startsAt = $this->resolveTimestamp($definition['starts_at'] ?? $definition['from'] ?? null);
        if ($startsAt !== null && $now < $startsAt) {
            return false;
        }

        $endsAt = $this->resolveTimestamp($definition['ends_at'] ?? $definition['until'] ?? null);
        if ($endsAt !== null && $now > $endsAt) {
            return false;
        }

        return true;
    }

    private function resolveTimestamp(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }

        if (!is_scalar($value)) {
            return null;
        }

        $timestamp = strtotime((string) $value);

        return $timestamp === false ? null : $timestamp;
    }

    private function actorBucket(string $key, string $actorId): int
    {
        $hash = crc32($key . '|' . $actorId);

        return abs((int) $hash) % 100;
    }
}