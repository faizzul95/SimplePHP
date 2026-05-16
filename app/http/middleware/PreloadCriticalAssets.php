<?php

namespace App\Http\Middleware;

use Core\Http\Middleware\MiddlewareInterface;
use Core\Http\Request;
use Core\Http\Response;

class PreloadCriticalAssets implements MiddlewareInterface
{
    public function handle(Request $request, callable $next)
    {
        foreach ((array) config('framework.preload', []) as $entry) {
            if (!is_array($entry) || empty($entry['path']) || empty($entry['as'])) {
                continue;
            }

            $path = (string) $entry['path'];
            $url = '/' . ltrim($path, '/');
            if (function_exists('asset')) {
                try {
                    $url = asset($path);
                } catch (\Throwable) {
                    $url = '/' . ltrim($path, '/');
                }
            }

            Response::preload(
                $url,
                (string) $entry['as'],
                isset($entry['type']) ? (string) $entry['type'] : null,
                ($entry['crossorigin'] ?? false) === true
            );
        }

        return $next($request);
    }
}