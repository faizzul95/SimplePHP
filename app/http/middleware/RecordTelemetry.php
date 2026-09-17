<?php

namespace App\Http\Middleware;

use Core\Database\PerformanceMonitor;
use Core\Http\Middleware\MiddlewareInterface;
use Core\Http\Request;
use Core\Telemetry\Entry;

/**
 * Starts the recorder, subscribes it to the query monitor, records the request
 * itself, and flushes once at the end.
 *
 * Placed early in the global stack so it sees the whole request, including
 * anything later middleware rejects. Every step is wrapped: a telemetry failure
 * must not become a request failure.
 */
class RecordTelemetry implements MiddlewareInterface
{
    public function handle(Request $request, callable $next)
    {
        if (!function_exists('telemetry')) {
            return $next($request);
        }

        try {
            $recorder = telemetry();
        } catch (\Throwable) {
            return $next($request);
        }

        /*
        | The user is resolved before the gate is consulted, because "record
        | these user ids" cannot be decided while the actor is unknown. Auth
        | middleware may not have run yet, so this is re-checked after $next.
        */
        $this->identify($recorder);

        if (!$recorder->enabled()) {
            return $next($request);
        }

        $this->subscribeToQueries($recorder);

        $started = microtime(true);
        $status = 200;

        try {
            $response = $next($request);

            if (is_object($response) && method_exists($response, 'status')) {
                $status = (int) $response->status();
            }

            return $this->injectBar($request, $response, $recorder);
        } catch (\Throwable $e) {
            $status = 500;
            $this->safely(static fn() => $recorder->recordException($e));

            throw $e;
        } finally {
            $this->safely(function () use ($recorder, $request, $started, $status): void {
                // A login during this request changes who the entries belong to.
                $this->identify($recorder);

                /*
                | The query summary rides on the request entry so the bar can
                | answer "how many queries, how much of the time, and which
                | shape repeated" without re-scanning every entry.
                */
                $shapes = $recorder->queryShapes();
                $repeated = array_values(array_filter(
                    $shapes,
                    static fn(array $shape): bool => $shape['count'] > 1
                ));

                $recorder->recordRequest([
                    'method' => $request->method(),
                    'path' => $request->path(),
                    'status' => $status,
                    'ajax' => $this->isAjax($request),
                    'ip' => $request->ip(),
                    'input' => $this->input($request),
                    'memory_mb' => round(memory_get_peak_usage(true) / 1048576, 2),
                    'dropped_entries' => $recorder->droppedCount(),
                    'query_count' => array_sum(array_column($shapes, 'count')),
                    'query_time_ms' => round((float) array_sum(array_column($shapes, 'total_ms')), 2),
                    'query_shapes' => count($shapes),
                    'repeated_queries' => array_slice($repeated, 0, 10),
                ], (microtime(true) - $started) * 1000);

                PerformanceMonitor::observe(null);
                $recorder->flush();
            });
        }
    }

    /**
     * Put the bar into an HTML page.
     *
     * Only a full HtmlResponse qualifies. A JSON body, a file download or a
     * partial fetched for an AJAX swap must come back untouched — those are
     * consumed by code, and a debug bar spliced into one is a bug, not a
     * feature. The AJAX calls themselves are still recorded and still show up
     * in the bar on the page that made them.
     */
    private function injectBar(Request $request, mixed $response, $recorder): mixed
    {
        if (!$response instanceof \Core\Http\HtmlResponse || $this->isAjax($request)) {
            return $response;
        }

        try {
            $config = function_exists('config') ? (array) (config('telemetry') ?? []) : [];

            if (!filter_var($config['inject_bar'] ?? true, FILTER_VALIDATE_BOOLEAN)) {
                return $response;
            }

            $html = (new \Core\Telemetry\Bar($config))
                ->inject($response->content(), $recorder->requestId());

            if ($html === $response->content()) {
                return $response;
            }

            return new \Core\Http\HtmlResponse($html, $response->status(), $response->headers());
        } catch (\Throwable) {
            return $response;
        }
    }

    private function identify($recorder): void
    {
        $this->safely(static function () use ($recorder): void {
            if (!function_exists('auth')) {
                return;
            }

            $id = auth()->id();
            $recorder->identify(is_numeric($id) ? (int) $id : null);
        });
    }

    private function subscribeToQueries($recorder): void
    {
        $this->safely(static function () use ($recorder): void {
            // endQuery() is where timing exists, and it only runs while the
            // monitor is on — so recording queries means turning it on.
            if (!PerformanceMonitor::isEnabled()) {
                PerformanceMonitor::enable();
            }

            PerformanceMonitor::observe(static function (array $entry) use ($recorder): void {
                $recorder->recordQuery(
                    (string) ($entry['sql'] ?? ''),
                    (array) ($entry['binds'] ?? []),
                    (float) ($entry['execution_time'] ?? 0) * 1000,
                    null,
                    self::firstAppFrame($entry['backtrace'] ?? null)
                );
            });
        });
    }

    /**
     * The first frame outside the framework — the line a developer wants, not
     * the twelve builder frames above it.
     */
    private static function firstAppFrame(mixed $backtrace): ?string
    {
        if (!is_array($backtrace)) {
            return null;
        }

        foreach ($backtrace as $frame) {
            $file = (string) ($frame['file'] ?? '');
            if ($file === '' || str_contains($file, 'systems' . DIRECTORY_SEPARATOR)) {
                continue;
            }

            return $file . ':' . (int) ($frame['line'] ?? 0);
        }

        return null;
    }

    private function isAjax(Request $request): bool
    {
        if (method_exists($request, 'isAjax')) {
            return (bool) $request->isAjax();
        }

        return strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
    }

    /** @return array<string, mixed> */
    private function input(Request $request): array
    {
        try {
            $input = method_exists($request, 'all') ? $request->all() : [];

            return is_array($input) ? $input : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function safely(callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable) {
            // Telemetry is an observer. It does not get to fail the request.
        }
    }
}
