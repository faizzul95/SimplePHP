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
}
