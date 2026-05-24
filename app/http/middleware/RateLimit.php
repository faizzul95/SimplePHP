<?php

namespace App\Http\Middleware;

use Core\Http\Middleware\MiddlewareInterface;
use Core\Http\Request;
use Core\Http\Response;

class RateLimit implements MiddlewareInterface
{
    private int $maxAttempts = 120;
    private int $decaySeconds = 60;
    private string $scope = 'ip-route';

    public function setParameters(array $parameters): void
    {
        if (empty($parameters)) {
            return;
        }

        $first = $parameters[0] ?? null;
        if (is_string($first) && $first !== '' && !is_numeric($first)) {
            $limiter = (array) config('framework.rate_limiters.' . $first, []);
            if (!empty($limiter)) {
                $this->maxAttempts = max(1, (int) ($limiter['max_attempts'] ?? $this->maxAttempts));
                $this->decaySeconds = max(1, (int) ($limiter['decay_seconds'] ?? $this->decaySeconds));
                $this->scope = (string) ($limiter['scope'] ?? $this->scope);
            }

            if (isset($parameters[1]) && is_numeric($parameters[1])) {
                $this->maxAttempts = max(1, (int) $parameters[1]);
            }

            if (isset($parameters[2]) && is_numeric($parameters[2])) {
                $decayMinutes = max(1, (int) $parameters[2]);
                $this->decaySeconds = $decayMinutes * 60;
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
            $decayMinutes = max(1, (int) $parameters[1]);
            $this->decaySeconds = $decayMinutes * 60;
        }

        if (!empty($parameters[2])) {
            $this->scope = (string) $parameters[2];
        }
    }

    public function handle(Request $request, callable $next)
    {
        $now = time();
        $signature = $this->buildSignature($request);

        // APCu fast path: atomic increment without any file I/O.
        // This eliminates the read-increment-write race on shared hosts that
        // have APCu enabled (most cPanel/Plesk stacks include it by default).
        if ($this->apcuAvailable()) {
            return $this->handleWithApcu($request, $next, $now, $signature);
        }

        // Cache-driver fallback: uses the configured cache() driver which supports
        // Redis, APCu, and file. Redis provides atomic INCR, making this path
        // race-safe on Redis-backed deployments without needing APCu at all.
        if (function_exists('cache') && $this->cacheDriverAvailable()) {
            return $this->handleWithCache($request, $next, $now, $signature);
        }

        // File-based last-resort fallback: uses exclusive flock for atomicity.
        return $this->handleWithFile($request, $next, $now, $signature);
    }

    /** @internal Check if the application cache() driver is usable. */
    private function cacheDriverAvailable(): bool
    {
        try {
            return cache()->store() !== null;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Cache-driver path — atomic via Redis INCR / APCu add+inc / file.
     * Uses the configured cache() driver, so Redis is automatically used
     * when CACHE_DRIVER=redis is set in .env.
     */
    private function handleWithCache(Request $request, callable $next, int $now, string $signature): mixed
    {
        $cacheKey = 'rl_v3_' . $signature;
        $resetKey = 'rl_v3_' . $signature . '_rst';

        // Atomic first-write (SET NX) — only one request wins the race to open the window
        if (!cache()->add($resetKey, $now + $this->decaySeconds, $this->decaySeconds + 5)) {
            $resetAt = (int) cache()->get($resetKey, $now + $this->decaySeconds);
            if ($resetAt <= $now) {
                // Window expired — reset
                cache()->put($resetKey, $now + $this->decaySeconds, $this->decaySeconds + 5);
                cache()->forget($cacheKey);
            }
        }

        // Atomic increment using add+increment pattern.
        // cache()->add() maps to SET NX — returns true only if the key was NEWLY created.
        // On concurrent requests both seeing a missing key, whichever loses the add()
        // falls through to increment() — no request is silently counted as 1 twice.
        if (!cache()->add($cacheKey, 1, $this->decaySeconds + 5)) {
            $attempts = (int) cache()->increment($cacheKey);
        } else {
            $attempts = 1;
        }

        $resetAt    = (int) cache()->get($resetKey, $now + $this->decaySeconds);
        $remaining  = max(0, $this->maxAttempts - $attempts);
        $retryAfter = max(0, $resetAt - $now);

        return $this->applyRateLimitHeaders($request, $next, $attempts, $remaining, $retryAfter);
    }

    /** @internal APCu fast path — atomic, no file I/O. */
    private function handleWithApcu(Request $request, callable $next, int $now, string $signature): mixed
    {
        $prefix   = 'rl_v2_';
        $countKey = $prefix . $signature . '_cnt';
        $resetKey = $prefix . $signature . '_rst';

        // Initialise the window reset timestamp on first touch.
        // apcu_add() is atomic — only ONE concurrent caller wins the race.
        $isNew = (bool) call_user_func('apcu_add', $resetKey, $now + $this->decaySeconds, $this->decaySeconds + 5);

        if (!$isNew && call_user_func('apcu_exists', $resetKey)) {
            $resetAt = (int) call_user_func('apcu_fetch', $resetKey);
            if ($resetAt <= $now) {
                // Window expired — reset atomically.
                call_user_func('apcu_store', $resetKey, $now + $this->decaySeconds, $this->decaySeconds + 5);
                call_user_func('apcu_store', $countKey, 0, $this->decaySeconds + 5);
            }
        }

        // Atomic increment — no lost updates under concurrent load.
        if (!call_user_func('apcu_exists', $countKey)) {
            call_user_func('apcu_store', $countKey, 1, $this->decaySeconds + 5);
            $attempts = 1;
        } else {
            $result   = call_user_func('apcu_inc', $countKey, 1);
            $attempts = (int) ($result !== false ? $result : 1);
        }

        $resetAtRaw = call_user_func('apcu_exists', $resetKey)
            ? call_user_func('apcu_fetch', $resetKey)
            : ($now + $this->decaySeconds);
        $resetAt    = (int) $resetAtRaw;
        $remaining  = max(0, $this->maxAttempts - $attempts);
        $retryAfter = max(0, $resetAt - $now);

        return $this->applyRateLimitHeaders($request, $next, $attempts, $remaining, $retryAfter);
    }

    /** @internal File-based fallback — uses exclusive flock for atomicity. */
    private function handleWithFile(Request $request, callable $next, int $now, string $signature): mixed
    {
        $file   = $this->cacheFile($signature);
        $state  = $this->incrementFileStateAtomically($file, $now);
        $remaining  = max(0, $this->maxAttempts - $state['attempts']);
        $retryAfter = max(0, (int) ($state['reset_at'] ?? $now) - $now);

        return $this->applyRateLimitHeaders($request, $next, $state['attempts'], $remaining, $retryAfter);
    }

    private function applyRateLimitHeaders(Request $request, callable $next, int $attempts, int $remaining, int $retryAfter): mixed
    {
        header('X-RateLimit-Limit: ' . $this->maxAttempts);
        header('X-RateLimit-Remaining: ' . $remaining);

        if ($attempts > $this->maxAttempts) {
            header('Retry-After: ' . $retryAfter);

            if ($request->expectsJson()) {
                Response::json([
                    'code'        => 429,
                    'message'     => 'Too many requests',
                    'retry_after' => $retryAfter,
                ], 429);
            }

            http_response_code(429);
            echo '429 Too Many Requests';
            exit;
        }

        return $next($request);
    }

    private function buildSignature(Request $request): string
    {
        $ip = (string) $request->ip();
        $path = (string) $request->path();
        $method = strtoupper((string) $request->method());
        $authId = auth()->id(['session', 'token', 'jwt', 'api_key', 'oauth2', 'basic', 'digest', 'oauth']);
        $userId = $authId !== null ? (string) $authId : 'guest';

        switch ($this->scope) {
            case 'auth':
                $key = $authId !== null
                    ? 'user:' . $userId
                    : 'ip:' . $ip;
                break;
            case 'auth-route':
                $key = $authId !== null
                    ? 'user-route:' . $userId . ':' . $method . ':' . $path
                    : 'ip-route:' . $ip . ':' . $method . ':' . $path;
                break;
            case 'user':
                $key = 'user:' . $userId;
                break;
            case 'route':
                $key = 'route:' . $method . ':' . $path;
                break;
            case 'user-route':
                $key = 'user-route:' . $userId . ':' . $method . ':' . $path;
                break;
            case 'ip':
                $key = 'ip:' . $ip;
                break;
            case 'ip-route':
            default:
                $key = 'ip-route:' . $ip . ':' . $method . ':' . $path;
                break;
        }

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

    private function cacheFile(string $signature): string
    {
        return $this->cacheDirectory() . DIRECTORY_SEPARATOR . $signature . '.json';
    }

    /**
     * Atomically open, read, reset, increment, and persist the file-backed
     * limiter state under a single exclusive lock.
     */
    private function incrementFileStateAtomically(string $file, int $now): array
    {
        $handle = @fopen($file, 'c+b');
        if ($handle === false) {
            return ['attempts' => 1, 'reset_at' => $now + $this->decaySeconds];
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                return ['attempts' => 1, 'reset_at' => $now + $this->decaySeconds];
            }

            rewind($handle);
            $raw = stream_get_contents($handle);
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            $state = is_array($decoded) ? $decoded : ['attempts' => 0, 'reset_at' => $now + $this->decaySeconds];

            if (($state['reset_at'] ?? 0) <= $now) {
                $state = ['attempts' => 0, 'reset_at' => $now + $this->decaySeconds];
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

        return $state;
    }

    private function apcuAvailable(): bool
    {
        static $available = null;
        if ($available === null) {
            $available = function_exists('apcu_inc')
                && function_exists('apcu_add')
                && function_exists('apcu_enabled')
                && (bool) call_user_func('apcu_enabled');
        }
        return $available;
    }
}
