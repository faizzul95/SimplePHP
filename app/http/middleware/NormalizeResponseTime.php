<?php

namespace App\Http\Middleware;

use Core\Http\Middleware\MiddlewareInterface;
use Core\Http\Request;

class NormalizeResponseTime implements MiddlewareInterface
{
    private int $minimumMs = 200;

    public function setParameters(array $parameters): void
    {
        if (isset($parameters[0]) && is_numeric($parameters[0])) {
            $this->minimumMs = max(0, (int) $parameters[0]);
        }
    }

    public function handle(Request $request, callable $next)
    {
        $start = $this->now();
        $response = $next($request);
        $elapsedMs = ($this->now() - $start) / 1_000_000;
        $remainingMs = $this->minimumMs - $elapsedMs;

        if ($remainingMs > 0) {
            $this->sleepMicros((int) round($remainingMs * 1000));
        }

        return $response;
    }

    protected function now(): int
    {
        return hrtime(true);
    }

    protected function sleepMicros(int $micros): void
    {
        if ($micros > 0) {
            usleep($micros);
        }
    }
}