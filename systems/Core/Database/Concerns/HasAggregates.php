<?php

namespace Core\Database\Concerns;

use Core\Database\QueryAllowlist;

/**
 * Trait HasAggregates
 *
 * Provides aggregate functions, GROUP BY, HAVING, ORDER BY, index hints,
 * UNION, selectSub, and withAggregate family methods.
 *
 * Consumed by: BaseDatabase
 */
trait HasAggregates
{
    /**
     * Get the aggregate value for a column.
     * Base method used by sum(), avg(), min(), max().
     *
     * @return mixed
     */
    public function aggregate($function, $column = '*')
    {
        $allowedFunctions = ['SUM', 'AVG', 'MIN', 'MAX', 'COUNT'];
        $function = strtoupper($function);

        if (!in_array($function, $allowedFunctions, true)) {
            throw new \InvalidArgumentException("Invalid aggregate function: $function");
        }

        if ($column !== '*') {
            $this->validateColumn($column);
        }

        $result = $this->selectRaw("$function($column) as aggregate_value")->fetch();
        return $result['aggregate_value'] ?? null;
    }

    /** @return mixed */
    public function sum($column)
    {
        return $this->aggregate('SUM', $column);
    }

    /**
     * Get the average of a column.
     *
     * @return mixed
     */
    public function avg($column)
    {
        return $this->aggregate('AVG', $column);
    }

    /** @return mixed */
    public function min($column)
    {
        return $this->aggregate('MIN', $column);
    }

    /** @return mixed */
    public function max($column)
    {
        return $this->aggregate('MAX', $column);
    }

    /**
     * Append one or more ORDER BY clauses to the query.
     *
     * @return $this
     */
    /*
    | ASC, matching BuilderStatementInterface, SQL's own default and every
    | comparable builder. It defaulted to DESC, so `orderBy('name')` sorted
    | reverse-alphabetically — the opposite of what it reads as, and the
    | opposite of what the interface promised. The only two one-argument
    | call sites were docblock examples, both meaning ascending.
    */
    public function orderBy($columns, $direction = 'ASC')
    {
        if (!in_array(strtoupper($direction), ['ASC', 'DESC'])) {
            throw new \InvalidArgumentException('Order direction must be "ASC" or "DESC".');
        }

        if (is_array($columns)) {
            foreach ($columns as $column => $dir) {
                $direction = strtoupper(!in_array(strtoupper($dir), ['ASC', 'DESC']) ? 'DESC' : $dir);
                $safeCol = $this->quoteSortColumn($column);
                $this->orderBy[] = "$safeCol $direction";
            }
        } else {
            $safeCol = $this->quoteSortColumn($columns);
            $this->orderBy[] = "$safeCol $direction";
        }

        return $this;
    }

    /**
     * Sort by a user-supplied column only if it appears in an explicit allowlist.
     * Use this whenever sort column comes from user input to prevent SQL injection.
     *
     * OrderBy() validates identifier format, but this adds an extra explicit allowlist.
     *
     * @param string   $direction      'ASC' or 'DESC'
     * @param string[] $allowedColumns Explicit allowlist of permitted column names
     * @throws \InvalidArgumentException if column is not in the allowlist
     */
    public function orderByAllowed(string $column, string $direction, array $allowedColumns): static
    {
        if (!in_array($column, $allowedColumns, true)) {
            throw new \InvalidArgumentException(
                "Column '{$column}' is not in the sort allowlist: [" . implode(', ', $allowedColumns) . ']'
            );
        }

        return $this->orderBy($column, $direction);
    }

    /**
     * Order by a user-controlled column after validating it against a positive allowlist.
     *
     * @param array<string>|null $allowedColumns
     */
    public function orderBySafe(string $column, string $direction = 'ASC', ?array $allowedColumns = null): static
    {
        $allowed = $allowedColumns ?? $this->getSortableColumns();
        $resolved = QueryAllowlist::assertSortable($this->table ?? null, $column, $allowed);
        return $this->orderBy($resolved, QueryAllowlist::sanitizeDirection($direction));
    }

    /**
     * Order by column in descending order (created_at by default).
     *
     * @return $this
     */
    public function latest($column = 'created_at')
    {
        return $this->orderBy($column, 'DESC');
    }

    /**
     * Order by column in ascending order (created_at by default).
     *
     * @return $this
     */
    public function oldest($column = 'created_at')
    {
        return $this->orderBy($column, 'ASC');
    }

    /**
     * Clear existing order by and optionally set new order.
     *
     * @return $this
     */
    public function reorder($column = null, $direction = 'DESC')
    {
        $this->orderBy = null;

        if ($column !== null) {
            return $this->orderBy($column, $direction);
        }

        return $this;
    }

    /**
     * Order results randomly.
     *
     * @return $this
     */
    public function inRandomOrder()
    {
        // RAND() is MySQL's spelling; PostgreSQL and SQLite want RANDOM(),
        // SQL Server NEWID(). The grammar already knew this and was not asked.
        $this->orderBy[] = $this->grammar()->compileRandomOrder();

        return $this;
    }

    /**
     * Append a validated raw ORDER BY fragment.
     *
     * @return $this
     */
    public function orderByRaw($string, $bindParams = null)
    {
        if (empty($string)) {
            throw new \InvalidArgumentException('Order by cannot be null in `orderByRaw`.');
        }

        if (!preg_match('/\b(DESC|ASC)\b/i', $string)) {
            throw new \InvalidArgumentException('Order by clause must contain either DESC or ASC in `orderByRaw`.');
        }

        $this->_forbidRawQuery($string, 'Full SQL statements are not allowed in `orderByRaw`.');

        $this->orderBy[] = $string;

        if (!empty($bindParams)) {
            if (is_array($bindParams)) {
                $this->_binds = [...$this->_binds, ...$bindParams];
            } else {
                $this->_binds[] = $bindParams;
            }
        }

        return $this;
    }

    /**
     * Shorthand for orderBy($column, 'DESC').
     *
     * @return $this
     */
    public function orderByDesc($column)
    {
        return $this->orderBy($column, 'DESC');
    }

    /**
     * Shorthand for orderBy($column, 'ASC').
     *
     * @return $this
     */
    public function orderByAsc($column)
    {
        return $this->orderBy($column, 'ASC');
    }

    /** @return $this */
    public function groupBy($columns)
    {
        if (is_string($columns)) {
            $segments = array_filter(array_map('trim', explode(',', $columns)), static fn($column) => $column !== '');
            if (empty($segments)) {
                throw new \InvalidArgumentException('Invalid column name(s) for groupBy.');
            }

            $groupBy = [];
            foreach ($segments as $column) {
                $this->validateColumn($column);
                $this->_forbidRawQuery($column, 'Full SQL statements are not allowed in groupBy().');
                $groupBy[] = $this->_qualifyColumn($column);
            }

            $this->groupBy = implode(', ', $groupBy);
        } elseif (is_array($columns)) {
            $groupBy = [];
            foreach ($columns as $column) {
                $this->validateColumn($column);
                $this->_forbidRawQuery($column, 'Full SQL statements are not allowed in groupBy().');
                $groupBy[] = $this->_qualifyColumn(trim($column));
            }

            $this->groupBy = implode(', ', $groupBy);
        } else {
            throw new \InvalidArgumentException('groupBy expects a string or an array of column names.');
        }

        return $this;
    }

    /**
     * Add a raw GROUP BY clause.
     *
     * @return $this
     */
    public function groupByRaw($expression, array $bindings = [])
    {
        if (empty($expression)) {
            throw new \InvalidArgumentException('Expression cannot be empty in groupByRaw().');
        }

        $this->_forbidRawQuery($expression, 'Full SQL statements are not allowed in groupByRaw().');

        $this->groupBy = $expression;

        if (!empty($bindings)) {
            $this->_binds = [...$this->_binds, ...$bindings];
        }

        return $this;
    }

    /**
     * Validate a column or simple SQL expression used in ORDER BY/HAVING clauses.
     * Raw statements are rejected before this helper is called.
     */
    protected function _sanitizeColumnName(string $column): string
    {
        $column = trim($column);

        if (preg_match('/^[`a-zA-Z0-9_.(), *]+$/', $column) === 1) {
            return $column;
        }

        throw new \InvalidArgumentException("Invalid column name: {$column}");
    }

    /**
     * Quote a sort column as `column` or `table`.`column`, rejecting anything else.
     *
     * ORDER BY is the one clause where a bare expression reaches the server verbatim,
     * so a sort column taken from request input is a blind-injection vector — the old
     * character-class check accepted payloads like
     * "(SELECT IF(SUBSTRING(pwd,1,1) LIKE 0x61, SLEEP(5), 1) FROM users LIMIT 1)".
     * Expressions belong in orderByRaw().
     */
    protected function quoteSortColumn(string $column): string
    {
        $pattern = '/^`?([A-Za-z_][A-Za-z0-9_]*)`?(?:\.`?([A-Za-z_][A-Za-z0-9_]*)`?)?$/';

        if (preg_match($pattern, trim($column), $parts) !== 1) {
            throw new \InvalidArgumentException(
                "Invalid sort column: {$column}. Expected `column` or `table.column`; "
                . 'use orderByRaw() for expressions.'
            );
        }

        // The pattern above is the security boundary — it admits nothing but a
        // bare or table-qualified identifier. Quoting is the grammar's, so the
        // characters change with the engine rather than being fixed to backticks.
        return isset($parts[2])
            ? $this->wrapIdentifier($parts[1] . '.' . $parts[2])
            : $this->wrapIdentifier($parts[1]);
    }

    /** @return $this */
    public function having($column, $value, $operator = '=')
    {
        if (empty($column)) {
            throw new \InvalidArgumentException('Column cannot be null in `having`.');
        }

        $this->validateColumn($column);
        $this->validateOperator($operator);
        $this->_forbidRawQuery($column, 'Full SQL statements are not allowed in `having`.');

        $safeColumn = $this->_sanitizeColumnName($column);

        $this->having[] = "$safeColumn $operator ?";
        $this->_havingBinds[] = $value;
        return $this;
    }

    /**
     * Append a validated raw HAVING fragment.
     *
     * @return $this
     */
    public function havingRaw($conditions)
    {
        if (empty($conditions)) {
            throw new \InvalidArgumentException('Conditions cannot be null in `havingRaw`.');
        }

        $this->_forbidRawQuery($conditions, 'Full SQL statements are not allowed in `havingRaw`.');
        $this->having[] = $conditions;
        return $this;
    }

    /**
     * Add a HAVING BETWEEN predicate with bound min and max values.
     *
     * @return $this
     */
    public function havingBetween($column, array $values)
    {
        if (empty($column)) {
            throw new \InvalidArgumentException('Column cannot be null in `havingBetween`.');
        }

        if (count($values) !== 2) {
            throw new \InvalidArgumentException('havingBetween requires an array with exactly two values.');
        }

        $this->validateColumn($column);
        $this->_forbidRawQuery($column, 'Full SQL statements are not allowed in `havingBetween`.');

        $safeColumn = $this->_sanitizeColumnName($column);

        $this->having[] = "$safeColumn BETWEEN ? AND ?";
        $this->_havingBinds[] = $values[0];
        $this->_havingBinds[] = $values[1];
        return $this;
    }

    /**
     * Add a subquery select expression.
     *
     * @return $this
     */
    public function selectSub($query, $alias)
    {
        if (empty($alias) || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $alias)) {
            throw new \InvalidArgumentException('selectSub alias must be a valid column name.');
        }

        if ($query instanceof \Closure) {
            $sub = $this->createSubQueryBuilder();
            $sub->table = $this->table;
            $query($sub);

            if (empty($sub->_query)) {
                $sub->_buildSelectQuery();
            }

            $subSQL = $sub->_query;

            if (!empty($sub->_binds)) {
                $this->_binds = [...$this->_binds, ...$sub->_binds];
            }

            unset($sub);
        } elseif (is_string($query)) {
            $subSQL = $query;
        } else {
            throw new \InvalidArgumentException('selectSub expects a Closure or SQL string.');
        }

        $safeAlias    = '`' . str_replace('`', '``', $alias) . '`';
        $subExpression = "($subSQL) AS $safeAlias";

        if ($this->column === '*') {
            $this->column = $subExpression;
        } else {
            $this->column .= ", $subExpression";
        }

        return $this;
    }

    /**
     * Append a UNION or UNION ALL query to the current SELECT statement.
     *
     * @return $this
     */
    public function union($query, $all = false)
    {
        if ($query instanceof \Closure) {
            $callback = $query;
            $query = clone $this;
            $query->reset();
            $callback($query);
        }

        if (!($query instanceof self)) {
            throw new \InvalidArgumentException('Union query must be an instance of BaseDatabase or a Closure that builds a query.');
        }

        $query->_buildSelectQuery();

        $this->unions[] = [
            'query'    => $query->_query,
            'bindings' => $query->_binds,
            'all'      => $all,
        ];

        return $this;
    }

    /**
     * Append a UNION ALL query to the current SELECT statement.
     *
     * @return $this
     */
    public function unionAll($query)
    {
        return $this->union($query, true);
    }

    /**
     * Add USE INDEX hint to query for better performance.
     *
     * @return $this
     */
    public function useIndex($indexes)
    {
        $indexes = is_array($indexes) ? $indexes : [$indexes];
        $this->indexHints['USE'] = $indexes;
        return $this;
    }

    /**
     * Add FORCE INDEX hint to query — stronger than USE INDEX.
     *
     * @return $this
     */
    public function forceIndex($indexes)
    {
        $indexes = is_array($indexes) ? $indexes : [$indexes];
        $this->indexHints['FORCE'] = $indexes;
        return $this;
    }

    /** @return $this */
    public function ignoreIndex($indexes)
    {
        $indexes = is_array($indexes) ? $indexes : [$indexes];
        $this->indexHints['IGNORE'] = $indexes;
        return $this;
    }

    /**
     * Register a to-many eager-loaded relation.
     *
     * @return $this
     */
    public function with($alias, $table, $foreign_key, $local_key, ?\Closure $callback = null)
    {
        if (empty($alias) || empty($table) || empty($foreign_key) || empty($local_key)) {
            throw new \InvalidArgumentException('Missing required parameters for with()');
        }

        if (!is_string($alias)) {
            throw new \InvalidArgumentException('Alias must be a string');
        }

        $this->relations[$alias] = ['type' => 'get', 'details' => compact('table', 'foreign_key', 'local_key', 'callback')];
        return $this;
    }

    /**
     * Register a to-one eager-loaded relation.
     *
     * @return $this
     */
    public function withOne($alias, $table, $foreign_key, $local_key, ?\Closure $callback = null)
    {
        if (empty($alias) || empty($table) || empty($foreign_key) || empty($local_key)) {
            throw new \InvalidArgumentException('Missing required parameters for withOne()');
        }

        if (!is_string($alias)) {
            throw new \InvalidArgumentException('Alias must be a string');
        }

        $this->relations[$alias] = ['type' => 'fetch', 'details' => compact('table', 'foreign_key', 'local_key', 'callback')];
        return $this;
    }

    /**
     * Add a count subquery to the main query.
     *
     * @return $this
     */
    public function withCount($alias, $table, $foreign_key, $local_key, ?\Closure $callback = null)
    {
        return $this->withAggregate($alias, $table, $foreign_key, $local_key, 'COUNT', null, $callback, ['count']);
    }

    /**
     * Add a sum subquery to the main query.
     *
     * @return $this
     */
    public function withSum($alias, $table, $foreign_key, $local_key, $sum_column, ?\Closure $callback = null)
    {
        return $this->withAggregate($alias, $table, $foreign_key, $local_key, 'SUM', $sum_column, $callback, ['sum']);
    }

    /**
     * Add an average subquery to the main query.
     *
     * @return $this
     */
    public function withAvg($alias, $table, $foreign_key, $local_key, $avg_column, ?\Closure $callback = null)
    {
        return $this->withAggregate($alias, $table, $foreign_key, $local_key, 'AVG', $avg_column, $callback, ['avg', 'average']);
    }

    /**
     * Add a minimum value subquery to the main query.
     *
     * @return $this
     */
    public function withMin($alias, $table, $foreign_key, $local_key, $min_column, ?\Closure $callback = null)
    {
        return $this->withAggregate($alias, $table, $foreign_key, $local_key, 'MIN', $min_column, $callback, ['min', 'minimum']);
    }

    /**
     * Add a maximum value subquery to the main query.
     *
     * @return $this
     */
    public function withMax($alias, $table, $foreign_key, $local_key, $max_column, ?\Closure $callback = null)
    {
        return $this->withAggregate($alias, $table, $foreign_key, $local_key, 'MAX', $max_column, $callback, ['max', 'maximum']);
    }

    /**
     * Register a correlated aggregate subquery on the current select list.
     *
     * @return $this
     */
    private function withAggregate($alias, $table, $foreign_key, $local_key, $aggregate_function, $column = null, ?\Closure $callback = null, array $alias_keywords = [])
    {
        $required_params = [$alias, $table, $foreign_key, $local_key, $aggregate_function];
        if (in_array($aggregate_function, ['SUM', 'AVG', 'MIN', 'MAX']) && empty($column)) {
            $required_params[] = $column;
        }

        foreach ($required_params as $param) {
            if (empty($param)) {
                throw new \InvalidArgumentException("Missing required parameters for with{$aggregate_function}()");
            }
        }

        if (!is_string($alias)) {
            throw new \InvalidArgumentException('Alias must be a string');
        }

        if (!empty($column) && !is_string($column)) {
            throw new \InvalidArgumentException('Column must be a string');
        }

        $alias_contains_keyword = false;
        foreach ($alias_keywords as $keyword) {
            if (stripos($alias, $keyword) !== false) {
                $alias_contains_keyword = true;
                break;
            }
        }

        if (!$alias_contains_keyword && !empty($alias_keywords)) {
            $alias .= '_' . strtolower($alias_keywords[0]);
        }

        switch ($aggregate_function) {
            case 'COUNT':
                $subquery = "SELECT COUNT(1) FROM `{$table}` WHERE `{$foreign_key}` = `{$this->table}`.`{$local_key}`";
                break;
            case 'SUM':
                $subquery = "SELECT COALESCE(SUM(`{$column}`), 0) FROM `{$table}` WHERE `{$foreign_key}` = `{$this->table}`.`{$local_key}`";
                break;
            case 'AVG':
                $subquery = "SELECT COALESCE(AVG(`{$column}`), 0) FROM `{$table}` WHERE `{$foreign_key}` = `{$this->table}`.`{$local_key}`";
                break;
            case 'MIN':
                $subquery = "SELECT MIN(`{$column}`) FROM `{$table}` WHERE `{$foreign_key}` = `{$this->table}`.`{$local_key}`";
                break;
            case 'MAX':
                $subquery = "SELECT MAX(`{$column}`) FROM `{$table}` WHERE `{$foreign_key}` = `{$this->table}`.`{$local_key}`";
                break;
            default:
                throw new \InvalidArgumentException("Unsupported aggregate function: {$aggregate_function}");
        }

        if ($callback !== null && is_callable($callback)) {
            $subQueryBuilder = $this->createSubQueryBuilder();
            $subQueryBuilder->table = $table;

            $callback($subQueryBuilder);

            if (!empty($subQueryBuilder->where)) {
                $whereClause = $subQueryBuilder->where;
                if (stripos($whereClause, 'WHERE') === 0) {
                    $whereClause = substr($whereClause, 5);
                }
                $subquery .= " AND " . trim($whereClause);
            }

            if (!empty($subQueryBuilder->_binds)) {
                $hasPositional = strpos($subquery, '?') !== false;
                $hasNamed      = preg_match('/:\w+/', $subquery);

                foreach ($subQueryBuilder->_binds as $key => $value) {
                    $quotedValue = is_numeric($value) ? $value : (is_string($value) ? $this->pdo[$subQueryBuilder->connectionName]->quote($value, \PDO::PARAM_STR) : htmlspecialchars($value ?? ''));

                    if ($hasPositional) {
                        if (is_numeric($key)) {
                            $subquery = preg_replace('/\?/', $quotedValue, $subquery, 1);
                        } else {
                            throw new \PDOException('Positional parameters require numeric keys', 400);
                        }
                    } elseif ($hasNamed) {
                        $subquery = str_replace(':' . $key, $quotedValue, $subquery);
                    } else {
                        throw new \PDOException('Query must contain either positional (?) or named (:number, :param) placeholders', 400);
                    }
                }
            }

            unset($subQueryBuilder);
        }

        $this->selectRaw($this->column . ", ({$subquery}) as `$alias`");
        return $this;
    }
}
