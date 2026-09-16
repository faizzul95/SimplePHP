<?php

declare(strict_types=1);

namespace Core\Telemetry;

/**
 * Decides whether this request is recorded at all.
 *
 * Everything downstream is gated here, so this is the class that has to be
 * conservative. Recording captures query text, request payloads and mail
 * bodies; if the gate is wrong in production, that is a data-exposure incident
 * rather than a missing feature. Hence: off unless switched on, and never on in
 * production unless someone said so in as many words.
 */
final class Gate
{
    public const MODE_OFF = 'off';
    public const MODE_ALL = 'all';
    public const MODE_USERS = 'users';

    /** @var array<string, mixed> */
    private array $config;

    /** Resolved once per request — the user lookup can hit the database. */
    private ?bool $decision = null;

    /** @param array<string, mixed> $config */
    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    public function mode(): string
    {
        $mode = strtolower(trim((string) ($this->config['mode'] ?? self::MODE_OFF)));

        return in_array($mode, [self::MODE_OFF, self::MODE_ALL, self::MODE_USERS], true) ? $mode : self::MODE_OFF;
    }

    /**
     * @param int|null $userId Pass the resolved user, or null to have the gate
     *                         resolve it. Passing it explicitly keeps this
     *                         testable without an auth stack.
     */
    public function allows(?int $userId = null): bool
    {
        if ($this->decision !== null) {
            return $this->decision;
        }

        return $this->decision = $this->decide($userId);
    }

    /** Forget the cached decision — a login mid-request changes the answer. */
    public function forget(): void
    {
        $this->decision = null;
    }

    /** @return list<int> */
    public function allowedUserIds(): array
    {
        $ids = [];

        foreach ((array) ($this->config['user_ids'] ?? []) as $candidate) {
            if (is_int($candidate) || (is_string($candidate) && ctype_digit(trim($candidate)))) {
                $id = (int) $candidate;
                if ($id > 0) { $ids[] = $id; }
            }
        }

        return array_values(array_unique($ids));
    }

    private function decide(?int $userId): bool
    {
        if (!filter_var($this->config['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }

        /*
        | Recording in production is opt-in twice: `enabled`, and then
        | `allow_in_production`. One misplaced .env value should not be enough
        | to start writing request bodies to disk on a live site.
        */
        if ($this->isProduction() && !filter_var($this->config['allow_in_production'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }

        return match ($this->mode()) {
            self::MODE_ALL => true,
            self::MODE_USERS => $this->matchesUser($userId ?? $this->resolveUserId()),
            default => false,
        };
    }

    private function matchesUser(?int $userId): bool
    {
        if ($userId === null || $userId < 1) {
            // "Record these users" cannot include someone who is not logged in.
            return false;
        }

        return in_array($userId, $this->allowedUserIds(), true);
    }

    private function resolveUserId(): ?int
    {
        if (!function_exists('auth')) {
            return null;
        }

        try {
            $id = auth()->id();

            return is_numeric($id) && (int) $id > 0 ? (int) $id : null;
        } catch (\Throwable) {
            // No auth stack in this context; treat as anonymous rather than fail.
            return null;
        }
    }

    private function isProduction(): bool
    {
        $environment = '';

        if (function_exists('config')) {
            $environment = (string) (config('framework.environment') ?? '');
        }

        if ($environment === '' && defined('ENVIRONMENT')) {
            $environment = (string) constant('ENVIRONMENT');
        }

        return in_array(strtolower(trim($environment)), ['production', 'prod', 'live'], true);
    }
}
