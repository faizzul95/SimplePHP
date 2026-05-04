<?php

namespace Core\Database\Concerns;

use Core\Database\PerformanceMonitor;
use Components\Logger;

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
 *
 * @category Database
 * @package  Core\Database\Concerns
 */
trait HasProfiling
{
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
     * @param string $identifier
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
     *
     * @param array|null $binds
     * @return void
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
     * Persist a slow-query log entry when runtime exceeds the configured threshold.
     *
     * @param float $executionTime
     * @param array $profilerEntry
     * @return void
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
            $rootDir = defined('ROOT_DIR') ? ROOT_DIR : dirname(__DIR__, 3) . DIRECTORY_SEPARATOR;
            $logger  = new Logger($rootDir . 'logs' . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'slow.log');
            $logger->log_error(json_encode([
                'event'        => 'slow_query',
                'connection'   => $this->connectionName,
                'table'        => $this->table,
                'duration_ms'  => round($executionTime * 1000, 2),
                'threshold_ms' => $thresholdMs,
                'query'        => $profilerEntry['query'] ?? null,
                'binds'        => $profilerEntry['binds'] ?? [],
                'full_query'   => $profilerEntry['full_query'] ?? null,
                'request_uri'  => $_SERVER['REQUEST_URI'] ?? 'CLI',
            ], JSON_UNESCAPED_SLASHES));
        } catch (\Throwable $e) {
            // Slow-query logging must not break the query lifecycle.
        }
    }

    /**
     * Return normalized slow-query logging configuration.
     *
     * @return array
     */
    protected function slowQueryConfiguration(): array
    {
        $configuration = function_exists('config') ? (array) config('db.performance.slow_query', []) : [];

        return [
            'enabled'      => ($configuration['enabled'] ?? false) === true,
            'threshold_ms' => max(1, (int) ($configuration['threshold_ms'] ?? 750)),
        ];
    }

    /**
     * Return normalized retry configuration for transient database errors.
     *
     * @return array
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
     *
     * @return array
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
     *
     * @return void
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

        try {
            $lockWaitTimeout = (int) ($configuration['lock_wait_timeout_seconds'] ?? 15);
            if ($lockWaitTimeout > 0) {
                $pdo->exec('SET SESSION innodb_lock_wait_timeout = ' . $lockWaitTimeout);
            }

            $statementTimeoutMs = (int) ($configuration['statement_timeout_ms'] ?? 0);
            if ($statementTimeoutMs > 0) {
                $pdo->exec('SET SESSION max_execution_time = ' . $statementTimeoutMs);
            }
        } catch (\Throwable $e) {
            // Session-level tuning is best effort.
        }
    }

    /**
     * Decide whether a throwable qualifies for retry as a transient database failure.
     *
     * @param \Throwable $throwable
     * @return bool
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
     * @param callable $operation
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
