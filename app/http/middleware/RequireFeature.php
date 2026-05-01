<?php

namespace App\Http\Middleware;

use Core\Http\Middleware\MiddlewareInterface;
use Core\Http\Request;
use Core\Http\Response;

class RequireFeature implements MiddlewareInterface
{
    private array $features = [];

    public function setParameters(array $parameters): void
    {
        $this->features = array_values(array_filter(array_map('trim', $parameters), static function ($feature): bool {
            return $feature !== '';
        }));
    }

    public function handle(Request $request, callable $next)
    {
        if (empty($this->features)) {
            return $next($request);
        }

        $context = [
            'environment' => defined('ENVIRONMENT') ? ENVIRONMENT : ((string) config('environment', 'production')),
        ];

        if (function_exists('auth')) {
            try {
                $user = auth()->user(['session', 'token', 'jwt', 'api_key', 'oauth2', 'basic', 'digest', 'oauth']);
                if (is_array($user) && !empty($user)) {
                    $context['user'] = $user;
                }
            } catch (\Throwable $e) {
                // Feature checks must not fail open because auth context could not be resolved.
            }
        }

        foreach ($this->features as $feature) {
            if (feature($feature, false, $context)) {
                return $next($request);
            }
        }

        return $this->reject($request, 403, 'Feature disabled.');
    }

    protected function reject(Request $request, int $status, string $message)
    {
        if ($request->expectsJson()) {
            Response::json([
                'code' => $status,
                'message' => $message,
                'features' => $this->features,
            ], $status);
        }

        return [
            'code' => $status,
            'message' => $message,
            'features' => $this->features,
        ];
    }
}