<?php

namespace Core\Database\Concerns;

/**
 * HasDebugHelpers Trait
 *
 * Provides developer-facing methods for inspecting the current query state:
 * toSql(), toRawSql(), dump(), dd(), and toDebugSql().
 *
 * Extracted from BaseDatabase to keep the monolith manageable.
 * All methods rely on protected properties and helpers defined in BaseDatabase /
 * DatabaseHelper, which are accessible at runtime via $this.
 *
 * @category Database
 * @package  Core\Database\Concerns
 */
trait HasDebugHelpers
{
    /**
     * Get the SQL query that would be executed (with bindings)
     *
     * @return array ['query' => string, 'binds' => array, 'full_query' => string]
     */
    public function toSql()
    {
        $this->ensureDebugInspectionAllowed(__FUNCTION__);
        $this->_buildSelectQuery();
        $bindings = $this->getSelectQueryBindings();
        $fullQuery = $this->_generateFullQuery($this->_query, $bindings, false);
        return [
            'query' => $this->_query,
            'binds' => $bindings,
            'full_query' => $fullQuery
        ];
    }

    /**
     * Get the raw SQL query with bindings substituted.
     * Laravel equivalent: toRawSql()
     *
     * @return string The full SQL query string with values substituted in
     */
    public function toRawSql()
    {
        $result = $this->toSql();
        return $result['full_query'] ?? $result['query'];
    }

    /**
     * Dump the current SQL query and bindings for debugging.
     * Laravel equivalent: dump()
     *
     * @return $this
     */
    public function dump()
    {
        $this->ensureDebugInspectionAllowed(__FUNCTION__);
        $sql = $this->toSql();
        echo '<pre>';
        echo "Query: " . htmlspecialchars($sql['query']) . "\n";
        echo "Binds: " . htmlspecialchars(json_encode($sql['binds'])) . "\n";
        if (isset($sql['full_query'])) {
            echo "Full:  " . htmlspecialchars(is_string($sql['full_query']) ? $sql['full_query'] : '') . "\n";
        }
        echo '</pre>';
        return $this;
    }

    /**
     * Dump the current SQL query and stop execution.
     * Laravel equivalent: dd()
     *
     * @return never
     */
    public function dd()
    {
        $this->dump();
        exit(1);
    }

    /**
     * Return the main SQL and any eager-load SQL for debugging complex builders.
     *
     * @return array
     */
    public function toDebugSql()
    {
        $this->ensureDebugInspectionAllowed(__FUNCTION__);
        // Build the final SELECT query string
        $this->_buildSelectQuery();

        // Generate the full query string with bound values
        $fullQuery = $this->_generateFullQuery($this->_query, $this->getSelectQueryBindings(), false);

        // Add a main query
        $queryList['main_query'] = $this->_query;
        $queryList['main_full_query'] = $fullQuery;

        // Save connection name, relations & caching info temporarily
        $_temp_connection = $this->connectionName;
        $_temp_relations = $this->relations;

        // Reset internal properties for next query
        $this->reset();

        if (!empty($_temp_relations)) {
            foreach ($_temp_relations as $alias => $relation) {

                $table = $relation['details']['table'];
                $fk_id = $relation['details']['foreign_key'];
                $callback = $relation['details']['callback'];

                $connectionObj = $this->getInstance()->connect($_temp_connection);

                $chunk = ['example1'];
                $relatedRecordsQuery = $connectionObj->table($table)->whereIn($fk_id, $chunk);

                // Apply callback if provided for customization
                if ($callback instanceof \Closure) {
                    $callback($relatedRecordsQuery);
                }

                // Build the query on the related records builder, not $this
                $queryList['with_' . $alias] = $relatedRecordsQuery->toDebugSql();
            }
        }

        unset($_temp_connection, $_temp_relations);

        return $queryList;
    }

    /**
     * Return a unified debug snapshot that combines the current builder SQL,
     * local profiler payload, and the global performance report.
     *
     * @param array $reportOptions
     * @return array<string, mixed>
     */
    public function toDebugSnapshot(array $reportOptions = []): array
    {
        $this->ensureDebugInspectionAllowed(__FUNCTION__);
        $sql = $this->toSql();
        $profiler = method_exists($this, 'profiler') ? $this->profiler() : [];

        return [
            'sql' => $sql,
            'raw_sql' => $sql['full_query'] ?? $sql['query'] ?? null,
            'debug_sql' => $this->toDebugSql(),
            'profiler' => $profiler,
            'performance_report' => $this->getPerformanceReport($reportOptions),
        ];
    }

    protected function ensureDebugInspectionAllowed(string $method): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        $debugEnabled = function_exists('env') && (bool) env('APP_DEBUG', false);
        if ($debugEnabled) {
            return;
        }

        throw new \RuntimeException($method . '() is only available in CLI or when APP_DEBUG is enabled.');
    }
}
