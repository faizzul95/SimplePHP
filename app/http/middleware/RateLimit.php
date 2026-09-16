<?php

namespace App\Http\Middleware;

use Core\Http\Abort;
use Core\Http\Middleware\MiddlewareInterface;
use Core\Http\Request;
use Core\Http\WithHeaders;

/**
 * Two windows, cheapest first.
 *
 * The per-route window is the useful one for ordinary abuse: it stops one client
 * hammering one endpoint. It is close to useless against a flood, because its key
 * includes the path — an attacker walking a thousand URLs gets a thousand fresh
 * budgets, and `throttle:api` with 120/minute becomes 120,000/minute.
 *
 * So a coarse per-IP ceiling runs first. It shares no state with the per-route
 * window, keys on nothing but the address, resolves no user, and touches one
 * counter. Being cheap is the point: a gate that costs a database round trip to
 * say "no" is an amplifier, not a defence.
 */
class RateLimit implements MiddlewareInterface
{
    /** Scopes whose key never mentions the authenticated user. */
    private const IP_ONLY_SCOPES = ['ip', 'route', 'ip-route'];

    /** Files the fallback store may keep before it starts pruning. */
    private const FILE_STORE_SOFT_LIMIT = 5000;

    private int $maxAttempts = 120;
    private int $decaySeconds = 60;
    private string $scope = 'ip-route';

    /** 0 disables the coarse gate. */
    private int $burstMaxAttempts = 0;
    private int $burstDecaySeconds = 60;

    public function setParameters(array $parameters): void
    {
        if (empty($parameters)) {
            return;
        }

        $first = $parameters[0] ?? null;

        if (is_string($first) && $first !== '' && !is_numeric($first)) {
            $this->applyNamedLimiter($first);

            if (isset($parameters[1]) && is_numeric($parameters[1])) {
                $this->maxAttempts = max(1, (int) $parameters[1]);
            }

            if (isset($parameters[2]) && is_numeric($parameters[2])) {
                $this->decaySeconds = max(1, (int) $parameters[2]) * 60;
            }

            if (!empty($parameters[3])) {
                $this->scope = (string) $parameters[3];
            }

            return;
        }

        // Laravel-style numeric syntax: throttle:maxAttempts,decayMinutes[,scope]
        if (isset($parameters[0]) && is_numeric($parameters[0])) {
            $this->maxAttempts = max(1, (int) $parameters[0]);
        }

        if (isset($parameters[1]) && is_numeric($parameters[1])) {
            $this->decaySeconds = max(1, (int) $parameters[1]) * 60;
        }

        if (!empty($parameters[2])) {
            $this->scope = (string) $parameters[2];
        }
    }

    private function applyNamedLimiter(string $name): void
    {
        $limiter = (array) config('framework.rate_limiters.' . $name, []);
        if ($limiter === []) {
            return;
        }

        $this->maxAttempts = max(1, (int) ($limiter['max_attempts'] ?? $this->maxAttempts));
        $this->decaySeconds = max(1, (int) ($limiter['decay_seconds'] ?? $this->decaySeconds));
        $this->scope = (string) ($limiter['scope'] ?? $this->scope);
        $this->burstMaxAttempts = max(0, (int) ($limiter['burst_max_attempts'] ?? 0));
        $this->burstDecaySeconds = max(1, (int) ($limiter['burst_decay_seconds'] ?? $this->burstDecaySeconds));
    }

    public function handle(Request $request, callable $next)
    {
        $now = time();

        if ($this->burstMaxAttempts > 0) {
            $burst = $this->hit('burst:' . sha1((string) $request->ip()), $this->burstDecaySeconds, $now);

            if ($burst['attempts'] > $this->burstMaxAttempts) {
                Abort::problem(
                    $request,
                    429,
                    'Too many requests',
                    ['retry_after' => $burst['retry_after']],
                    [
                        'Retry-After' => (string) $burst['retry_after'],
                        'X-RateLimit-Limit' => (string) $this->burstMaxAttempts,
                        'X-RateLimit-Remaining' => '0',
                        'X-RateLimit-Scope' => 'burst',
                    ]
                );
            }
        }

        $state = $this->hit($this->buildSignature($request), $this->decaySeconds, $now);

        $budgetHeaders = [
            'X-RateLimit-Limit' => (string) $this->maxAttempts,
            'X-RateLimit-Remaining' => (string) max(0, $this->maxAttempts - $state['attempts']),
            'X-RateLimit-Reset' => (string) ($now + $state['retry_after']),
        ];

        if ($state['attempts'] > $this->maxAttempts) {
            Abort::problem(
                $request,
                429,
                'Too many requests',
                ['retry_after' => $state['retry_after']],
                $budgetHeaders + ['Retry-After' => (string) $state['retry_after']]
            );
        }

        // Attached to the response rather than sent with header(): a raw header
        // lives outside the response object, so the emitter, the response cache
        // and any test that inspects the response never see it.
        return WithHeaders::attach($next($request), $budgetHeaders);
    }

    /**
     * Count one request against a window and report where it stands.
     *
     * Three backends, picked by what the host has. Each has to be atomic — a
     * read-modify-write limiter loses counts under exactly the concurrency it
     * exists to bound.
     *
     * @return array{attempts: int, retry_after: int}
     */
    private function hit(string $signature, int $decaySeconds, int $now): array
    {
        if ($this->apcuAvailable()) {
            return $this->hitApcu($signature, $decaySeconds, $now);
        }

        if (function_exists('cache') && $this->cacheDriverAvailable()) {
            return $this->hitCache($signature, $decaySeconds, $now);
        }

        return $this->hitFile($signature, $decaySeconds, $now);
    }

    /** @return array{attempts: int, retry_after: int} */
    private function hitApcu(string $signature, int $decaySeconds, int $now): array
    {
        $countKey = 'rl_v3_' . $signature . '_cnt';
        $resetKey = 'rl_v3_' . $signature . '_rst';
        $ttl = $decaySeconds + 5;

        // apcu_add is atomic, so exactly one concurrent caller opens the window.
        if (!call_user_func('apcu_add', $resetKey, $now + $decaySeconds, $ttl)) {
            $resetAt = (int) call_user_func('apcu_fetch', $resetKey);
            if ($resetAt <= $now) {
                call_user_func('apcu_store', $resetKey, $now + $decaySeconds, $ttl);
                call_user_func('apcu_store', $countKey, 0, $ttl);
            }
        }

        $incremented = call_user_func('apcu_inc', $countKey, 1);

        if ($incremented === false) {
            // No counter yet, or it expired under us. add() rather than store()
            // so a concurrent caller cannot have its count overwritten with 1.
            $attempts = call_user_func('apcu_add', $countKey, 1, $ttl)
                ? 1
                : (int) call_user_func('apcu_inc', $countKey, 1);
        } else {
            $attempts = (int) $incremented;
        }

        $resetAtRaw = call_user_func('apcu_fetch', $resetKey);

        return [
            'attempts' => max(1, $attempts),
            'retry_after' => max(0, (is_numeric($resetAtRaw) ? (int) $resetAtRaw : $now + $decaySeconds) - $now),
        ];
    }

    /** @return array{attempts: int, retry_after: int} */
    private function hitCache(string $signature, int $decaySeconds, int $now): array
    {
        $countKey = 'rl_v3_' . $signature;
        $resetKey = 'rl_v3_' . $signature . '_rst';
        $ttl = $decaySeconds + 5;

        // add() is SET NX — only the first caller opens the window.
        if (!cache()->add($resetKey, $now + $decaySeconds, $ttl)) {
            $resetAt = (int) cache()->get($resetKey, $now + $decaySeconds);
            if ($resetAt <= $now) {
                cache()->put($resetKey, $now + $decaySeconds, $ttl);
                cache()->forget($countKey);
            }
        }

        // The TTL on increment() matters when the counter expired between the
        // add() below failing and the increment running: without it the recreated
        // key never expires and the caller stays limited forever.
        $attempts = cache()->add($countKey, 1, $ttl)
            ? 1
            : (int) cache()->increment($countKey, 1, $ttl);

        $resetAt = (int) cache()->get($resetKey, $now + $decaySeconds);

        return [
            'attempts' => max(1, $attempts),
            'retry_after' => max(0, $resetAt - $now),
        ];
    }

    /**
     * Last resort. One file per distinct key, so the pruning below is not
     * housekeeping: with an IP-and-path key an attacker walking random URLs
     * creates a file per request, and inode exhaustion is a denial of service
     * caused by the thing meant to prevent one.
     *
     * @return array{attempts: int, retry_after: int}
     */
    private function hitFile(string $signature, int $decaySeconds, int $now): array
    {
        $file = $this->cacheDirectory() . DIRECTORY_SEPARATOR . $signature . '.json';
        $handle = @fopen($file, 'c+b');

        if ($handle === false) {
            // Cannot count, so cannot limit. Failing open here is deliberate: a
            // broken cache directory must not lock every client out.
            return ['attempts' => 1, 'retry_after' => $decaySeconds];
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                return ['attempts' => 1, 'retry_after' => $decaySeconds];
            }

            rewind($handle);
            $raw = stream_get_contents($handle);
            $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
            $state = is_array($decoded) ? $decoded : [];

            if ((int) ($state['reset_at'] ?? 0) <= $now) {
                $state = ['attempts' => 0, 'reset_at' => $now + $decaySeconds];
            }

            $state['attempts'] = (int) ($state['attempts'] ?? 0) + 1;

            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, (string) json_encode($state));
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }

        $this->pruneFileStore();

        return [
            'attempts' => (int) $state['attempts'],
            'retry_after' => max(0, (int) $state['reset_at'] - $now),
        ];
    }

    /**
     * Delete expired entries once the directory grows past the soft limit.
     *
     * Sampled rather than run every request: scanning thousands of files on each
     * hit would cost more than the limiter saves.
     */
    private function pruneFileStore(): void
    {
        // 1-in-200, so a busy directory is swept regularly and a quiet one is not
        // scanned at all. random_int over rand: no reason to burn entropy quality
        // here, but the API is the one that cannot be seeded into a pattern.
        if (random_int(1, 200) !== 1) {
            return;
        }

        $files = @glob($this->cacheDirectory() . DIRECTORY_SEPARATOR . '*.json') ?: [];
        if (count($files) < self::FILE_STORE_SOFT_LIMIT) {
            return;
        }

        $now = time();

        foreach ($files as $file) {
            $raw = @file_get_contents($file);
            if ($raw === false) {
                continue;
            }

            $decoded = json_decode($raw, true);
            if (!is_array($decoded) || (int) ($decoded['reset_at'] ?? 0) <= $now) {
                @unlink($file);
            }
        }
    }

    private function buildSignature(Request $request): string
    {
        $ip = (string) $request->ip();
        $path = (string) $request->path();
        $method = strtoupper((string) $request->method());

        /*
        | Resolving the user costs a token lookup — a database round trip on every
        | request the limiter sees, including the ones it is about to reject. That
        | turned the defence into the amplifier: under a flood of unauthenticated
        | requests, each one bought a query before being told to go away.
        |
        | The IP-only scopes never use the value, so they no longer pay for it.
        */
        $authId = in_array($this->scope, self::IP_ONLY_SCOPES, true)
            ? null
            : auth()->id(['session', 'token', 'jwt', 'api_key', 'oauth2', 'basic', 'digest', 'oauth']);

        $userId = $authId !== null ? (string) $authId : 'guest';

        $key = match ($this->scope) {
            'auth' => $authId !== null ? 'user:' . $userId : 'ip:' . $ip,
            'auth-route' => $authId !== null
                ? 'user-route:' . $userId . ':' . $method . ':' . $path
                : 'ip-route:' . $ip . ':' . $method . ':' . $path,
            'user' => 'user:' . $userId,
            'route' => 'route:' . $method . ':' . $path,
            'user-route' => 'user-route:' . $userId . ':' . $method . ':' . $path,
            'ip' => 'ip:' . $ip,
            default => 'ip-route:' . $ip . ':' . $method . ':' . $path,
        };

        return sha1($key);
    }

    private function cacheDirectory(): string
    {
        $dir = ROOT_DIR . 'storage/cache/rate_limit';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir;
    }

    private function cacheDriverAvailable(): bool
    {
        try {
            return cache()->store() !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    private function apcuAvailable(): bool
    {
        static $available = null;

        return $available ??= function_exists('apcu_inc')
            && function_exists('apcu_add')
            && function_exists('apcu_enabled')
            && (bool) call_user_func('apcu_enabled');
    }
}
