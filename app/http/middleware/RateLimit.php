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
        $state = $this->readState($signature);

        if (($state['reset_at'] ?? 0) <= $now) {
            $state = [
                'attempts' => 0,
                'reset_at' => $now + $this->decaySeconds,
            ];
        }

        $state['attempts'] = (int) ($state['attempts'] ?? 0) + 1;
        $remaining = max(0, $this->maxAttempts - $state['attempts']);
        $retryAfter = max(0, (int) $state['reset_at'] - $now);

        // Always persist the state (even on 429) so the counter stays accurate
        $this->writeState($signature, $state);

        header('X-RateLimit-Limit: ' . $this->maxAttempts);
        header('X-RateLimit-Remaining: ' . $remaining);

        if ($state['attempts'] > $this->maxAttempts) {
            header('Retry-After: ' . $retryAfter);

            if ($request->expectsJson()) {
                Response::json([
                    'code' => 429,
                    'message' => 'Too many requests',
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

    private function readState(string $signature): array
    {
        $file = $this->cacheFile($signature);

        if (!is_file($file) || !is_readable($file)) {
            return [];
        }

        $raw = file_get_contents($file);
        if ($raw === false || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function writeState(string $signature, array $state): void
    {
        $file = $this->cacheFile($signature);
        file_put_contents($file, json_encode($state), LOCK_EX);
    }
}
