<?php

namespace Core\Database\Concerns;

use Core\Database\PerformanceMonitor;
use Core\Database\SlowQueryLogger;
use Core\Database\TimeoutDialect;

/**
 * Trait HasProfiling
 *
 * Provides query profiling, slow-query logging, retry logic, and session
 * performance tuning:
 * profiler, _setProfilerIdentifier, _startProfiler, _stopProfiler,
 * _captureExecutedQuery, logSlowQueryIfNeeded, slowQueryConfiguration,
 * retryConfiguration, statementTimeoutConfiguration,
 * applySessionPerformanceRules, shouldRetryThrowable, executeWithRetry.
 *
 * Consumed by: BaseDatabase
 */
trait HasProfiling
{
    /** @var array<string, TimeoutDialect> Resolved timeout spelling per connection. */
    private array $timeoutDialects = [];

    /**
     * Return the collected profiler payload for the current connection.
     *
     * @return array
     */
    public function profiler()
    {
        return $this->_profiler;
    }

    /**
     * Switch the active profiler bucket used for subsequent timing data.
     *
     * @return $this
     */
    protected function _setProfilerIdentifier($identifier = 'main')
    {
        $this->_profilerActive = $identifier;
        return $this;
    }

    /**
     * Starts the profiler for a specific method.
     * Optimized: skips heavy operations when profiling is disabled.
     *
     * @param string $method The name of the method that initiated profiling.
     * @return void
     */
    protected function _startProfiler($method)
    {
        // Always track SQL for N+1 detection — independent of full profiling toggle.
        if (!empty($this->_query)) {
            PerformanceMonitor::trackSql($this->_query);
        }

        if (!$this->enableProfiling) {
            return;
        }

        $startTime = microtime(true);

        $queryType = 'other';
        if (!empty($this->_query)) {
            $firstWord = strtoupper(trim(explode(' ', trim($this->_query))[0]));
            $queryType = match($firstWord) {
                'SELECT' => 'select',
                'INSERT' => 'insert',
                'UPDATE' => 'update',
                'DELETE' => 'delete',
                default  => 'other',
            };
        }

        PerformanceMonitor::startQuery(
            $this->_profilerActive,
            $this->_query ?? '',
            $this->_binds ?? [],
            $queryType
        );

        $this->_profiler['php_ver'] = phpversion();

        if (function_exists('php_uname')) {
            $this->_profiler['os_ver'] = php_uname('s') . ' ' . php_uname('r');
        } else {
            $this->_profiler['os_ver'] = 'Unknown';
        }

        $this->_profiler['db_connection'] = $this->connectionName;
        $this->_profiler['db_driver']     = $this->driver ?? 'mysql';

        if (isset($this->pdo[$this->connectionName]) && $this->pdo[$this->connectionName] instanceof \PDO) {
            $this->_profiler['db_ver'] = $this->pdo[$this->connectionName]->getAttribute(\PDO::ATTR_SERVER_VERSION);
        } else {
            $this->_profiler['db_ver'] = 'Unknown';
        }

        $this->_profiler['db_schema'] = $this->schema;

        $this->_profiler['profiling'][$this->_profilerActive] = [
            'method'             => $method,
            'start'              => $startTime,
            'end'                => null,
            'start_time'         => date('Y-m-d h:i A', (int) $startTime),
            'end_time'           => null,
            'query'              => null,
            'binds'              => null,
            'execution_time'     => null,
            'execution_status'   => null,
            'memory_usage'       => memory_get_usage(),
            'memory_usage_peak'  => memory_get_peak_usage(),
        ];
    }

    /**
     * Stops the profiler and calculates execution time and status.
     *
     * @return void
     */
    protected function _stopProfiler()
    {
        if (!isset($this->_profiler['profiling'][$this->_profilerActive])) {
            return;
        }

        PerformanceMonitor::endQuery($this->_profilerActive, 0);

        $endTime      = microtime(true);
        $profilerEntry = &$this->_profiler['profiling'][$this->_profilerActive];
        $executionTime = $endTime - $profilerEntry['start'];

        $this->logSlowQueryIfNeeded($executionTime, $profilerEntry);

        if (isset($profilerEntry['memory_usage'])) {
            $memDelta = memory_get_usage() - $profilerEntry['memory_usage'];
            $profilerEntry['memory_usage'] = $this->_formatBytes(max(0, $memDelta), 2);
        }
        if (isset($profilerEntry['memory_usage_peak'])) {
            $peakDelta = memory_get_peak_usage() - $profilerEntry['memory_usage_peak'];
            $profilerEntry['memory_usage_peak'] = $this->_formatBytes(max(0, $peakDelta), 4);
        }

        $profilerEntry['end']      = $endTime;
        $profilerEntry['end_time'] = date('Y-m-d h:i A', (int) $endTime);

        $milliseconds    = round(($executionTime - floor($executionTime)) * 1000, 2);
        $totalSeconds    = floor($executionTime);
        $seconds         = $totalSeconds % 60;
        $minutes         = floor(($totalSeconds % 3600) / 60);
        $hours           = floor($totalSeconds / 3600);

        if ($totalSeconds == 0) {
            $formattedExecutionTime = sprintf("%dms", $milliseconds);
        } elseif ($hours > 0) {
            $formattedExecutionTime = sprintf("%dh %dm %ds %dms", $hours, $minutes, $seconds, $milliseconds);
        } elseif ($minutes > 0) {
            $formattedExecutionTime = sprintf("%dm %ds %dms", $minutes, $seconds, $milliseconds);
        } else {
            $formattedExecutionTime = sprintf("%ds %dms", $seconds, $milliseconds);
        }

        $this->_profiler['profiling'][$this->_profilerActive]['execution_time'] = $formattedExecutionTime;

        if (!empty($this->_profilerShowConf['stack_trace'])) {
            $this->_profiler['stack_trace'] = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);
        }

        $this->_profiler['profiling'][$this->_profilerActive]['execution_status'] =
            ($executionTime >= 3.5) ? 'very slow' :
            (($executionTime >= 1.5 && $executionTime < 3.5) ? 'slow' :
            (($executionTime > 0.5 && $executionTime < 1.49) ? 'fast' : 'very fast'));

        unset(
            $milliseconds, $totalSeconds, $seconds, $minutes, $hours,
            $endTime, $executionTime, $formattedExecutionTime,
            $this->_profiler['profiling'][$this->_profilerActive]['start'],
            $this->_profiler['profiling'][$this->_profilerActive]['end']
        );

        foreach ($this->_profilerShowConf as $config => $value) {
            if (!$value) {
                if (!in_array($config, ['php_ver', 'os_ver', 'db_driver', 'db_ver', 'stack_trace'])) {
                    unset($this->_profiler['profiling'][$this->_profilerActive][$config]);
                } else {
                    unset($this->_profiler[$config]);
                }
            }
        }
    }

    /**
     * Capture query text for profiler output only when profiling is enabled.
     */
    protected function _captureExecutedQuery(?array $binds = null): void
    {
        if (!$this->enableProfiling) {
            return;
        }

        $this->_profiler['profiling'][$this->_profilerActive]['query'] = $this->_query;
        $this->_generateFullQuery($this->_query, $binds, true);
    }

    /**
     * Append profiler metadata to the active bucket without starting a full
     * timed profiling lifecycle.
     *
     * Used by adaptive iteration paths that want observability around runtime
     * tuning decisions but do not execute through the regular query-profiler
     * start/stop flow.
     */
    protected function _appendProfilerMetadata(string $key, array $metadata): void
    {
        if (!$this->enableProfiling) {
            return;
        }

        if ($key === 'adaptive_chunk_decisions') {
            PerformanceMonitor::recordAdaptiveChunkDecision($metadata);
        }

        if (!isset($this->_profiler['profiling'][$this->_profilerActive]) || !is_array($this->_profiler['profiling'][$this->_profilerActive])) {
            $this->_profiler['profiling'][$this->_profilerActive] = [
                'method' => null,
                'query' => null,
                'binds' => null,
                'execution_time' => null,
                'execution_status' => null,
            ];
        }

        if (!isset($this->_profiler['profiling'][$this->_profilerActive][$key]) || !is_array($this->_profiler['profiling'][$this->_profilerActive][$key])) {
            $this->_profiler['profiling'][$this->_profilerActive][$key] = [];
        }

        $this->_profiler['profiling'][$this->_profilerActive][$key][] = $metadata;
    }

    /**
     * Persist a slow-query log entry when runtime exceeds the configured threshold.
     */
    protected function logSlowQueryIfNeeded(float $executionTime, array $profilerEntry): void
    {
        $configuration = $this->slowQueryConfiguration();
        if (($configuration['enabled'] ?? false) !== true) {
            return;
        }

        $thresholdMs = max(1, (int) ($configuration['threshold_ms'] ?? 750));
        if (($executionTime * 1000) < $thresholdMs) {
            return;
        }

        try {
            SlowQueryLogger::record([
                'event'        => 'slow_query',
                'connection'   => $this->connectionName,
                'table'        => $this->table,
                'duration_ms'  => round($executionTime * 1000, 2),
                'threshold_ms' => $thresholdMs,
                'alert_ms'     => (int) ($configuration['alert_ms'] ?? 2000),
                'query'        => $profilerEntry['query'] ?? null,
                'binds'        => $profilerEntry['binds'] ?? [],
                'full_query'   => $profilerEntry['full_query'] ?? null,
                'request_uri'  => $_SERVER['REQUEST_URI'] ?? 'CLI',
            ]);
        } catch (\Throwable $e) {
            // Slow-query logging must not break the query lifecycle.
        }
    }

    /**
     * Return normalized slow-query logging configuration.
     */
    protected function slowQueryConfiguration(): array
    {
        $configuration = function_exists('config') ? (array) config('db.performance.slow_query', []) : [];

        return [
            'enabled'      => ($configuration['enabled'] ?? false) === true,
            'threshold_ms' => max(1, (int) ($configuration['threshold_ms'] ?? 750)),
            'alert_ms'     => max(1, (int) ($configuration['alert_ms'] ?? 2000)),
        ];
    }

    /**
     * Return normalized retry configuration for transient database errors.
     */
    protected function retryConfiguration(): array
    {
        $configuration = function_exists('config') ? (array) config('db.retry', []) : [];

        return [
            'enabled'  => ($configuration['enabled'] ?? false) === true,
            'attempts' => max(1, (int) ($configuration['attempts'] ?? 3)),
            'delay_ms' => max(0, (int) ($configuration['delay_ms'] ?? 50)),
        ];
    }

    /**
     * Return normalized session timeout configuration for the current driver.
     */
    protected function statementTimeoutConfiguration(): array
    {
        $configuration = function_exists('config') ? (array) config('db.performance.timeouts', []) : [];

        return [
            'enabled'                    => ($configuration['enabled'] ?? false) === true,
            'statement_timeout_ms'       => max(0, (int) ($configuration['statement_timeout_ms'] ?? 15000)),
            'lock_wait_timeout_seconds'  => max(1, (int) ($configuration['lock_wait_timeout_seconds'] ?? 15)),
        ];
    }

    /**
     * Apply best-effort per-session timeout and lock-wait settings.
     */
    protected function applySessionPerformanceRules(): void
    {
        $configuration = $this->statementTimeoutConfiguration();
        if (($configuration['enabled'] ?? false) !== true) {
            return;
        }

        $pdo = $this->pdo[$this->connectionName] ?? null;
        if (!$pdo instanceof \PDO) {
            return;
        }

        // Each setting gets its own try/catch. Sharing one meant a failure on the
        // first silently skipped the second, and both were swallowed — so a
        // deployment could believe it had a 15s statement timeout while having
        // none at all.
        $dialect = $this->timeoutDialect($pdo);

        $lockWaitValue = $dialect->lockWaitValue((int) ($configuration['lock_wait_timeout_seconds'] ?? 15));
        if ($lockWaitValue !== null && $dialect->lockWaitVariable !== null) {
            $this->applySessionVariable($pdo, $dialect->lockWaitVariable, $lockWaitValue);
        }

        $statementValue = $dialect->statementValue((int) ($configuration['statement_timeout_ms'] ?? 0));
        if ($statementValue !== null && $dialect->statementVariable !== null) {
            $this->applySessionVariable($pdo, $dialect->statementVariable, $statementValue);
        }
    }

    /**
     * The timeout spelling for whichever engine is on the other end.
     *
     * Cached per connection: the answer cannot change while the process runs, and
     * reading the server banner on every connect is a wasted round trip.
     */
    protected function timeoutDialect(\PDO $pdo): TimeoutDialect
    {
        if (isset($this->timeoutDialects[$this->connectionName])) {
            return $this->timeoutDialects[$this->connectionName];
        }

        try {
            $pdoDriver = (string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
            $version = (string) $pdo->getAttribute(\PDO::ATTR_SERVER_VERSION);
        } catch (\Throwable) {
            $pdoDriver = (string) ($this->driver ?? '');
            $version = '';
        }

        return $this->timeoutDialects[$this->connectionName] = TimeoutDialect::for($pdoDriver, $version);
    }

    /**
     * Apply one session variable, reporting rather than hiding a failure.
     *
     * Still best-effort — a managed host may forbid the SET — but a silent failure
     * on a timeout control is indistinguishable from having no control.
     */
    protected function applySessionVariable(\PDO $pdo, string $variable, string $value): bool
    {
        try {
            $pdo->exec('SET SESSION ' . $variable . ' = ' . $value);

            return true;
        } catch (\Throwable $e) {
            \Core\Support\SafeLog::warning(sprintf(
                'Could not apply session variable %s=%s on connection [%s]: %s. '
                . 'The corresponding db.performance.timeouts setting is NOT in effect.',
                $variable,
                $value,
                $this->connectionName,
                $e->getMessage()
            ));

            return false;
        }
    }

    /**
     * Decide whether a throwable qualifies for retry as a transient database failure.
     */
    protected function shouldRetryThrowable(\Throwable $throwable): bool
    {
        if (!$throwable instanceof \PDOException) {
            return false;
        }

        $sqlState   = (string) ($throwable->errorInfo[0] ?? '');
        $driverCode = (int) ($throwable->errorInfo[1] ?? 0);
        $message    = strtolower($throwable->getMessage());

        if (in_array($sqlState, ['40001', '40P01'], true)) {
            return true;
        }

        if (in_array($driverCode, [1205, 1213], true)) {
            return true;
        }

        return str_contains($message, 'deadlock') || str_contains($message, 'lock wait timeout');
    }

    /**
     * Execute an operation with retry semantics for transient lock and deadlock errors.
     *
     * @return mixed
     */
    protected function executeWithRetry(callable $operation)
    {
        $configuration = $this->retryConfiguration();
        $attempts      = ($configuration['enabled'] ?? false) === true ? (int) $configuration['attempts'] : 1;
        $delayMs       = (int) ($configuration['delay_ms'] ?? 0);
        $lastThrowable = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                return $operation();
            } catch (\Throwable $throwable) {
                $lastThrowable = $throwable;

                if ($attempt >= $attempts || !$this->shouldRetryThrowable($throwable)) {
                    throw $throwable;
                }

                if ($delayMs > 0) {
                    usleep($delayMs * 1000);
                }
            }
        }

        if ($lastThrowable instanceof \Throwable) {
            throw $lastThrowable;
        }

        return null;
    }
}
