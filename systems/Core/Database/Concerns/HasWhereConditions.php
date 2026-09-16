<?php

namespace Core\Database\Concerns;

use Core\Database\DriverRegistry;
use Core\Database\QueryAllowlist;

/**
 * Trait HasWhereConditions
 *
 * Provides all WHERE clause builder methods: where, orWhere, whereColumn,
 * whereIn/NotIn, whereBetween, whereNull, whereNot, whereLike, whereAny,
 * whereAll, whereNone, whereBetweenColumns, whereFullText,
 * whereIntegerInRaw, whereIntegerNotInRaw, applyTemporalWhereClause,
 * when, unless, tap, whereHas family, _qualifyColumn, _buildWhereClause,
 * whereRaw, _whereRawInternal.
 *
 * Consumed by: BaseDatabase
 */
trait HasWhereConditions
{
    protected const IN_LIST_CHUNK_SIZE = 1000;

    /**
     * Add a where clause using a positive allowlist for the column name.
     *
     * @param array<string>|null $allowedColumns
     * @return $this
     */
    public function whereSafe(string $column, mixed $value, string $operator = '=', ?array $allowedColumns = null)
    {
        $allowed = $allowedColumns ?? $this->getFilterableColumns();
        $resolved = QueryAllowlist::assertFilterable($this->table ?? null, $column, $allowed);
        return $this->where($resolved, $operator, $value);
    }

    /**
     * Append a raw WHERE fragment with bound parameters.
     *
     * Validates the expression with _forbidRawQuery() to block stacked queries,
     * comment injection, and other dangerous patterns before appending the clause.
     *
     * @return $this
     */
    public function whereRaw($rawQuery, $value = [], $whereType = 'AND')
    {
        $this->_forbidRawQuery($rawQuery, 'Full SQL statements are not allowed in whereRaw(). Use query() for raw SQL.');
        return $this->_whereRawInternal($rawQuery, $value, $whereType);
    }

    /**
     * Internal variant of whereRaw() used by framework builders that produce
     * their own validated SQL fragments (e.g. _buildWhereHas, whereNot closures).
     *
     * Skips _forbidRawQuery() — only call this when the expression is framework-generated.
     *
     * @return $this
     */
    protected function _whereRawInternal($rawQuery, $value = [], $whereType = 'AND')
    {
        try {
            $this->validateColumn($rawQuery, 'query');

            if (!empty($value) && !is_array($value)) {
                throw new \InvalidArgumentException("Value for whereRaw() must be an array");
            }

            if (!in_array($whereType, ['AND', 'OR'])) {
                throw new \InvalidArgumentException('Invalid where type. Supported operators are: AND/OR');
            }

            $this->_buildWhereClause($rawQuery, $value, 'RAW', $whereType);
            return $this;
        } catch (\InvalidArgumentException $e) {
            $this->logDatabaseError($e, __FUNCTION__);
            throw $e;
        }
    }

    /**
     * Add an AND where clause, grouped closure, or associative array of clauses.
     *
     * @return $this
     */
    public function where($columnName, $operator = null, $value = null)
    {
        try {
            if (!is_callable($columnName) && !is_string($columnName) && !is_array($columnName)) {
                throw new \InvalidArgumentException('Invalid column name. Must be a string or an associative array.');
            }

            if (is_callable($columnName)) {
                $db = $this->createSubQueryBuilder();
                $db->table = $this->table;
                $columnName($db);

                if (!empty($db->where) && is_string($db->where)) {
                    $this->_whereRawInternal($db->where, $db->_binds, 'AND');
                } else {
                    throw new \InvalidArgumentException('Callable must return a valid SQL clause string.');
                }

                unset($db);
                return $this;
            }

            if (is_array($columnName)) {
                foreach ($columnName as $key => $val) {
                    $this->where($key, $val);
                }
                return $this;
            }

            if ($value === null && $operator !== 'IS NULL' && $operator !== 'IS NOT NULL') {
                $value = $operator;
                $operator = '=';
            }

            $this->validateOperator($operator, ['IN', 'NOT IN', 'BETWEEN', 'NOT BETWEEN', 'IS NULL', 'IS NOT NULL', 'LIKE', 'NOT LIKE']);

            $this->_forbidRawQuery($columnName, 'Full/Sub SQL statements are not allowed in query builder. Please use query() function.');

            $this->_buildWhereClause($this->_qualifyColumn($columnName), $value, $operator, 'AND');

            return $this;
        } catch (\InvalidArgumentException $e) {
            $this->logDatabaseError($e, __FUNCTION__);
            throw $e;
        }
    }

    /**
     * Normalize a column reference into a safely quoted identifier.
     */
    protected function _qualifyColumn(string $column): string
    {
        $column = trim($column);

        if ($column === '*' || $column === '') {
            return $column;
        }

        // Unqualified names are scoped to the current table so a join cannot make
        // them ambiguous. Everything else — quoting, escaping, splitting on the
        // dot — is the grammar's, so a second engine changes one class rather
        // than every call site.
        if (!str_contains($column, '.') && !empty($this->table)) {
            $column = $this->table . '.' . $column;
        }

        return $this->wrapIdentifier($column);
    }

    /**
     * Add an OR where clause, grouped closure, or associative array of clauses.
     *
     * @return $this
     */
    public function orWhere($columnName, $operator = null, $value = null)
    {
        try {
            if (!is_callable($columnName) && !is_string($columnName) && !is_array($columnName)) {
                throw new \InvalidArgumentException('Invalid column name. Must be a string or an associative array.');
            }

            if (is_callable($columnName)) {
                $db = $this->createSubQueryBuilder();
                $db->table = $this->table;
                $columnName($db);

                if (!empty($db->where) && is_string($db->where)) {
                    $this->_whereRawInternal($db->where, $db->_binds, 'OR');
                } else {
                    throw new \InvalidArgumentException('Callable must return a valid SQL clause string.');
                }

                unset($db);
                return $this;
            }

            if (is_array($columnName)) {
                foreach ($columnName as $key => $val) {
                    $this->orWhere($key, $val);
                }
                return $this;
            }

            if ($value === null && $operator !== 'IS NULL' && $operator !== 'IS NOT NULL') {
                $value = $operator;
                $operator = '=';
            }

            $this->validateOperator($operator, ['IN', 'NOT IN', 'BETWEEN', 'NOT BETWEEN', 'IS NULL', 'IS NOT NULL', 'LIKE', 'NOT LIKE']);

            $this->_forbidRawQuery($columnName, 'Full/Sub SQL statements are not allowed in orWhere(). Please use query() function.');

            $this->_buildWhereClause($this->_qualifyColumn($columnName), $value, $operator, 'OR');

            return $this;
        } catch (\InvalidArgumentException $e) {
            $this->logDatabaseError($e, __FUNCTION__);
            throw $e;
        }
    }

    /**
     * Add a where clause comparing two columns.
     *
     * @return $this
     */
    public function whereColumn($column1, $operator = null, $column2 = null)
    {
        if ($column2 === null) {
            $column2 = $operator;
            $operator = '=';
        }

        $this->validateColumn($column1);
        $this->validateColumn($column2);
        $this->validateOperator($operator);
        $this->_forbidRawQuery($column1, 'Full/Sub SQL statements are not allowed in whereColumn().');
        $this->_forbidRawQuery($column2, 'Full/Sub SQL statements are not allowed in whereColumn().');

        // wrapIdentifier splits on the dot. The old form wrapped the whole
        // qualified name, so whereColumn('orders.user_id', 'users.id') asked the
        // server for a single column literally called "orders.user_id".
        $col1 = $this->wrapIdentifier($column1);
        $col2 = $this->wrapIdentifier($column2);

        return $this->whereRaw("$col1 $operator $col2", [], 'AND');
    }

    /**
     * Add an OR where clause comparing two columns.
     *
     * @return $this
     */
    public function orWhereColumn($column1, $operator = null, $column2 = null)
    {
        if ($column2 === null) {
            $column2 = $operator;
            $operator = '=';
        }

        $this->validateColumn($column1);
        $this->validateColumn($column2);
        $this->validateOperator($operator);
        $this->_forbidRawQuery($column1, 'Full/Sub SQL statements are not allowed in orWhereColumn().');
        $this->_forbidRawQuery($column2, 'Full/Sub SQL statements are not allowed in orWhereColumn().');

        // wrapIdentifier splits on the dot. The old form wrapped the whole
        // qualified name, so whereColumn('orders.user_id', 'users.id') asked the
        // server for a single column literally called "orders.user_id".
        $col1 = $this->wrapIdentifier($column1);
        $col2 = $this->wrapIdentifier($column2);

        return $this->whereRaw("$col1 $operator $col2", [], 'OR');
    }

    /**
     * Add an IN condition to the query.
     *
     * @return $this
     */
    public function whereIn($column, $value = [])
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException("Value for 'IN' operator must be an array");
        }

        if ($value === []) {
            return $this->_whereRawInternal('0 = 1', [], 'AND');
        }

        return $this->buildChunkedInCondition($column, $value, 'IN', 'AND');
    }

    /**
     * Add an OR IN condition to the query.
     *
     * @return $this
     */
    public function orWhereIn($column, $value = [])
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException("Value for 'IN' operator must be an array");
        }

        if ($value === []) {
            return $this->_whereRawInternal('0 = 1', [], 'OR');
        }

        return $this->buildChunkedInCondition($column, $value, 'IN', 'OR');
    }

    /**
     * Add a NOT IN condition to the query.
     *
     * @return $this
     */
    public function whereNotIn($column, $value = [])
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException("Value for 'NOT IN' operator must be an array");
        }

        if ($value === []) {
            return $this;
        }

        return $this->buildChunkedInCondition($column, $value, 'NOT IN', 'AND');
    }

    /**
     * Add an OR NOT IN condition to the query.
     *
     * @return $this
     */
    public function orWhereNotIn($column, $value = [])
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException("Value for 'NOT IN' operator must be an array");
        }

        if ($value === []) {
            return $this;
        }

        return $this->buildChunkedInCondition($column, $value, 'NOT IN', 'OR');
    }

    /**
     * Split oversized IN predicates into grouped chunks to avoid driver limits.
     *
     * @param array<int, mixed> $value
     * @param 'IN'|'NOT IN' $operator
     * @return $this
     */
    protected function buildChunkedInCondition(string $column, array $value, string $operator, string $whereType)
    {
        $value = array_values($value);
        $chunkSize = $this->getInListChunkSize();

        if (count($value) <= $chunkSize) {
            return $whereType === 'OR'
                ? $this->orWhere($column, $operator, $value)
                : $this->where($column, $operator, $value);
        }

        $qualifiedColumn = $this->_qualifyColumn($column);
        $bindings = [];
        $clauses = [];

        foreach (array_chunk($value, $chunkSize) as $chunk) {
            $clauses[] = $qualifiedColumn . ' ' . $operator . ' (' . implode(',', array_fill(0, count($chunk), '?')) . ')';
            $bindings = [...$bindings, ...$chunk];
        }

        $glue = $operator === 'NOT IN' ? ' AND ' : ' OR ';

        return $this->_whereRawInternal('(' . implode($glue, $clauses) . ')', $bindings, $whereType);
    }

    protected function getInListChunkSize(): int
    {
        return self::IN_LIST_CHUNK_SIZE;
    }

    /**
     * Add a BETWEEN condition after validating the range bounds.
     *
     * @return $this
     */
    public function whereBetween($columnName, $start, $end)
    {
        $formattedValues = [];
        foreach ([$start, $end] as $value) {
            if (
                is_int($value)
                || is_float($value)
                || preg_match('/^\d{1,4}-\d{2}-\d{2}$/', $value)
                || preg_match('/^\d{2}:\d{2}:\d{2}$/', $value)
            ) {
                $formattedValues[] = trim($value);
            } else {
                throw new \InvalidArgumentException('Invalid start or end value for BETWEEN. Must be numeric, date (YYYY-MM-DD), or time (HH:MM:SS).');
            }
        }

        if (!($formattedValues[0] <= $formattedValues[1])) {
            throw new \InvalidArgumentException('Start value must be less than or equal to end value for BETWEEN.');
        }

        return $this->where($columnName, 'BETWEEN', $formattedValues);
    }

    /**
     * Add an OR BETWEEN condition after validating the range bounds.
     *
     * @return $this
     */
    public function orWhereBetween($columnName, $start, $end)
    {
        $formattedValues = [];
        foreach ([$start, $end] as $value) {
            if (
                is_int($value)
                || is_float($value)
                || preg_match('/^\d{1,4}-\d{2}-\d{2}$/', $value)
                || preg_match('/^\d{2}:\d{2}:\d{2}$/', $value)
            ) {
                $formattedValues[] = trim($value);
            } else {
                throw new \InvalidArgumentException('Invalid start or end value for BETWEEN. Must be numeric, date (YYYY-MM-DD), or time (HH:MM:SS).');
            }
        }

        if (!($formattedValues[0] <= $formattedValues[1])) {
            throw new \InvalidArgumentException('Start value must be less than or equal to end value for BETWEEN.');
        }

        return $this->orWhere($columnName, 'BETWEEN', $formattedValues);
    }

    /**
     * Add a NOT BETWEEN condition after validating the range bounds.
     *
     * @return $this
     */
    public function whereNotBetween($columnName, $start, $end)
    {
        $formattedValues = [];
        foreach ([$start, $end] as $value) {
            if (
                is_int($value)
                || is_float($value)
                || preg_match('/^\d{1,4}-\d{2}-\d{2}$/', $value)
                || preg_match('/^\d{2}:\d{2}:\d{2}$/', $value)
            ) {
                $formattedValues[] = trim($value);
            } else {
                throw new \InvalidArgumentException('Invalid start or end value for NOT BETWEEN. Must be numeric, date (YYYY-MM-DD), or time (HH:MM:SS).');
            }
        }

        if (!($formattedValues[0] <= $formattedValues[1])) {
            throw new \InvalidArgumentException('Start value must be less than or equal to end value for NOT BETWEEN.');
        }

        return $this->where($columnName, 'NOT BETWEEN', $formattedValues);
    }

    /**
     * Add an OR NOT BETWEEN condition after validating the range bounds.
     *
     * @return $this
     */
    public function orWhereNotBetween($columnName, $start, $end)
    {
        $formattedValues = [];
        foreach ([$start, $end] as $value) {
            if (
                is_int($value)
                || is_float($value)
                || preg_match('/^\d{1,4}-\d{2}-\d{2}$/', $value)
                || preg_match('/^\d{2}:\d{2}:\d{2}$/', $value)
            ) {
                $formattedValues[] = trim($value);
            } else {
                throw new \InvalidArgumentException('Invalid start or end value for NOT BETWEEN. Must be numeric, date (YYYY-MM-DD), or time (HH:MM:SS).');
            }
        }

        if (!($formattedValues[0] <= $formattedValues[1])) {
            throw new \InvalidArgumentException('Start value must be less than or equal to end value for NOT BETWEEN.');
        }

        return $this->orWhere($columnName, 'NOT BETWEEN', $formattedValues);
    }

    /**
     * Add an IS NULL condition to the query.
     *
     * @return $this
     */
    public function whereNull($column)
    {
        return $this->where($column, 'IS NULL', null);
    }

    /**
     * Add an OR IS NULL condition to the query.
     *
     * @return $this
     */
    public function orWhereNull($column)
    {
        return $this->orWhere($column, 'IS NULL', null);
    }

    /**
     * Add an IS NOT NULL condition to the query.
     *
     * @return $this
     */
    public function whereNotNull($column)
    {
        return $this->where($column, 'IS NOT NULL', null);
    }

    /**
     * Add an OR IS NOT NULL condition to the query.
     *
     * @return $this
     */
    public function orWhereNotNull($column)
    {
        return $this->orWhere($column, 'IS NOT NULL', null);
    }

    /**
     * Add a negated where clause or negated grouped closure.
     *
     * @return $this
     */
    public function whereNot($column, $operator = null, $value = null)
    {
        if ($column instanceof \Closure) {
            $db = $this->createSubQueryBuilder();
            $db->table = $this->table;
            $column($db);

            if (!empty($db->where) && is_string($db->where)) {
                $this->whereRaw("NOT ({$db->where})", $db->_binds, 'AND');
            }
            unset($db);
            return $this;
        }

        if ($value === null && $operator !== null) {
            $value = $operator;
            $operator = '!=';
        }

        return $this->where($column, $operator ?? '!=', $value);
    }

    /** @return $this */
    public function orWhereNot($column, $operator = null, $value = null)
    {
        if ($column instanceof \Closure) {
            $db = $this->createSubQueryBuilder();
            $db->table = $this->table;
            $column($db);

            if (!empty($db->where) && is_string($db->where)) {
                $this->whereRaw("NOT ({$db->where})", $db->_binds, 'OR');
            }
            unset($db);
            return $this;
        }

        if ($value === null && $operator !== null) {
            $value = $operator;
            $operator = '!=';
        }

        return $this->orWhere($column, $operator ?? '!=', $value);
    }

    /** @return $this */
    public function whereLike($column, $value)
    {
        return $this->where($column, 'LIKE', $value);
    }

    /** @return $this */
    public function orWhereLike($column, $value)
    {
        return $this->orWhere($column, 'LIKE', $value);
    }

    /** @return $this */
    public function whereNotLike($column, $value)
    {
        return $this->where($column, 'NOT LIKE', $value);
    }

    /** @return $this */
    public function orWhereNotLike($column, $value)
    {
        return $this->orWhere($column, 'NOT LIKE', $value);
    }

    /**
     * Add a grouped OR predicate across multiple columns.
     *
     * @return $this
     */
    public function whereAny(array $columns, $operator, $value)
    {
        if (empty($columns)) {
            throw new \InvalidArgumentException('whereAny requires at least one column.');
        }

        $this->validateOperator($operator);

        return $this->where(function ($query) use ($columns, $operator, $value) {
            foreach ($columns as $i => $column) {
                if ($i === 0) {
                    $query->where($column, $operator, $value);
                } else {
                    $query->orWhere($column, $operator, $value);
                }
            }
        });
    }

    /**
     * Add a grouped AND predicate across multiple columns.
     *
     * @return $this
     */
    public function whereAll(array $columns, $operator, $value)
    {
        if (empty($columns)) {
            throw new \InvalidArgumentException('whereAll requires at least one column.');
        }

        $this->validateOperator($operator);

        return $this->where(function ($query) use ($columns, $operator, $value) {
            foreach ($columns as $column) {
                $query->where($column, $operator, $value);
            }
        });
    }

    /**
     * Add a grouped predicate asserting none of the columns match.
     *
     * @return $this
     */
    public function whereNone(array $columns, $operator, $value)
    {
        if (empty($columns)) {
            throw new \InvalidArgumentException('whereNone requires at least one column.');
        }

        $this->validateOperator($operator);

        return $this->whereNot(function ($query) use ($columns, $operator, $value) {
            foreach ($columns as $i => $column) {
                if ($i === 0) {
                    $query->where($column, $operator, $value);
                } else {
                    $query->orWhere($column, $operator, $value);
                }
            }
        });
    }

    /**
     * Add a "where between columns" clause: column BETWEEN column_start AND column_end.
     *
     * @return $this
     */
    public function whereBetweenColumns($column, array $columns)
    {
        if (count($columns) !== 2) {
            throw new \InvalidArgumentException('whereBetweenColumns requires exactly two column names.');
        }

        $this->validateColumn($column);
        $this->validateColumn($columns[0]);
        $this->validateColumn($columns[1]);

        $col  = $this->wrapIdentifier($column);
        $col1 = $this->wrapIdentifier($columns[0]);
        $col2 = $this->wrapIdentifier($columns[1]);

        return $this->whereRaw("{$col} BETWEEN {$col1} AND {$col2}");
    }

    /**
     * Add a FULLTEXT MATCH ... AGAINST predicate.
     *
     * @return $this
     */
    public function whereFullText($columns, $value, array $options = [])
    {
        $columns = is_array($columns) ? $columns : [$columns];

        foreach ($columns as $column) {
            $this->validateColumn($column);
        }

        $columnList = implode(', ', array_map(function ($col) {
            return '`' . str_replace('`', '``', $col) . '`';
        }, $columns));

        $mode = strtolower($options['mode'] ?? 'natural');
        switch ($mode) {
            case 'boolean':
                $modeStr = 'IN BOOLEAN MODE';
                break;
            case 'expansion':
                $modeStr = 'WITH QUERY EXPANSION';
                break;
            default:
                $modeStr = 'IN NATURAL LANGUAGE MODE';
                break;
        }

        return $this->whereRaw("MATCH($columnList) AGAINST(? $modeStr)", [$value]);
    }

    /**
     * Add an integer-only IN predicate without PDO placeholders.
     *
     * @return $this
     */
    public function whereIntegerInRaw($column, array $values)
    {
        if (empty($values)) {
            return $this->_whereRawInternal('0 = 1', [], 'AND');
        }

        return $this->buildChunkedIntegerRawCondition($column, $values, 'IN', 'AND');
    }

    /**
     * Add a NOT IN clause with raw integer values.
     *
     * @return $this
     */
    public function whereIntegerNotInRaw($column, array $values)
    {
        if (empty($values)) {
            return $this;
        }

        return $this->buildChunkedIntegerRawCondition($column, $values, 'NOT IN', 'AND');
    }

    /**
     * Build a grouped raw integer IN/NOT IN predicate for large numeric lists.
     *
     * @param array<int, int|string> $values
     * @param 'IN'|'NOT IN' $operator
     * @return $this
     */
    protected function buildChunkedIntegerRawCondition(string $column, array $values, string $operator, string $whereType)
    {
        $this->validateColumn($column);

        $safeValues = array_map(function ($v) {
            if (is_int($v)) {
                return $v;
            }
            if (is_string($v) && preg_match('/^-?\d+$/', $v) === 1) {
                return (int) $v;
            }
            throw new \InvalidArgumentException('All values in whereIntegerNotInRaw must be integers');
        }, $values);

        $safeValues = array_unique($safeValues);
        sort($safeValues);

        $chunkSize = $this->getInListChunkSize();
        if (count($safeValues) <= $chunkSize) {
            $list = implode(',', $safeValues);
            return $this->_whereRawInternal($this->_qualifyColumn($column) . " $operator ($list)", [], $whereType);
        }

        $qualifiedColumn = $this->_qualifyColumn($column);
        $clauses = [];

        foreach (array_chunk($safeValues, $chunkSize) as $chunk) {
            $clauses[] = $qualifiedColumn . ' ' . $operator . ' (' . implode(',', $chunk) . ')';
        }

        $glue = $operator === 'NOT IN' ? ' AND ' : ' OR ';

        return $this->_whereRawInternal('(' . implode($glue, $clauses) . ')', [], $whereType);
    }

    /**
     * Build a driver-aware temporal where clause using the registered query grammar.
     *
     * @param string $type  date|day|month|year|time
     * @return $this
     */
    protected function applyTemporalWhereClause(string $type, $column, $operator = null, $value = null, string $whereType = 'AND')
    {
        $this->validateColumn($column);
        $this->_forbidRawQuery($column, 'Full/Sub SQL statements are not allowed in temporal where clauses. Please use query() function.');

        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }

        $operator = is_string($operator) ? $operator : '=';
        $this->validateOperator($operator);

        $normalizedType = strtolower(trim($type));
        $resolvedValue = match ($normalizedType) {
            'date'  => $this->validateDate($value),
            'day'   => (function ($candidate) {
                $this->validateDay($candidate);
                return (int) $candidate;
            })($value),
            'month' => (function ($candidate) {
                $this->validateMonth($candidate);
                return (int) $candidate;
            })($value),
            'year'  => (function ($candidate) {
                $this->validateYear($candidate);
                return (int) $candidate;
            })($value),
            'time'  => $this->validateTime($value),
            default => throw new \InvalidArgumentException('Unsupported temporal where type: ' . $type),
        };

        $expression = DriverRegistry::queryGrammar((string) ($this->driver ?: 'mysql'))
            ->compileTemporalExpression($normalizedType, $this->_qualifyColumn((string) $column));

        $this->_buildWhereClause($expression, $resolvedValue, $operator, $whereType);

        return $this;
    }

    /**
     * Conditionally mutate the builder when the predicate is truthy.
     *
     * @return $this
     */
    public function when($condition, $callback)
    {
        if (is_callable($condition) && !is_string($condition)) {
            $condition = $condition($this);
        }

        if ($condition) {
            $callback($this);
        }

        return $this;
    }

    /**
     * Execute callback unless condition is true.
     *
     * @return $this
     */
    public function unless($condition, $callback)
    {
        if (is_callable($condition) && !is_string($condition)) {
            $condition = $condition($this);
        }

        if (!$condition) {
            $callback($this);
        }

        return $this;
    }

    /**
     * Execute callback and return the builder (for debugging/side effects).
     *
     * @return $this
     */
    public function tap($callback)
    {
        $callback($this);
        return $this;
    }

    /**
     * Build an EXISTS or NOT EXISTS subquery for relationship predicates.
     *
     * @param string $relationTable
     * @param string $foreignKey
     * @param string $localKey
     * @param \Closure|null $callback
     * @param string $operator
     * @param string $comparison
     * @return $this
     */
    /**
     * Add an EXISTS predicate built from an arbitrary sub-query.
     *
     * whereHas() covers the common case — "rows in table B that point back at
     * this row" — but forces the foreign-key correlation, so anything else (a
     * correlated aggregate, an EXISTS over a join, a self-referencing check) had
     * no builder form at all and had to drop to query(). This is the general
     * shape:
     *
     *     $db->table('users')->whereExists(function ($q) {
     *         $q->table('orders')
     *           ->whereColumn('orders.user_id', 'users.id')
     *           ->where('total', '>', 100);
     *     });
     *
     * @param \Closure $callback Receives a fresh builder for the sub-query
     * @return $this
     */
    public function whereExists(\Closure $callback)
    {
        return $this->_buildWhereExists($callback, 'AND', 'EXISTS');
    }

    /** @return $this */
    public function orWhereExists(\Closure $callback)
    {
        return $this->_buildWhereExists($callback, 'OR', 'EXISTS');
    }

    /** @return $this */
    public function whereNotExists(\Closure $callback)
    {
        return $this->_buildWhereExists($callback, 'AND', 'NOT EXISTS');
    }

    /** @return $this */
    public function orWhereNotExists(\Closure $callback)
    {
        return $this->_buildWhereExists($callback, 'OR', 'NOT EXISTS');
    }

    /** @return $this */
    private function _buildWhereExists(\Closure $callback, string $boolean, string $comparison)
    {
        $sub = $this->createSubQueryBuilder();
        $callback($sub);

        if (empty($sub->table)) {
            throw new \InvalidArgumentException(
                $comparison . '(): the sub-query has no table. Call $query->table(...) inside the closure.'
            );
        }

        $sub->_buildSelectQuery();
        $sql = trim((string) $sub->_query);

        if ($sql === '') {
            throw new \RuntimeException($comparison . '(): the sub-query produced no SQL.');
        }

        // The sub-query's bindings have to reach the outer statement in the order
        // its placeholders appear, which _whereRawInternal appends them in.
        return $this->_whereRawInternal(
            $comparison . ' (' . $sql . ')',
            $sub->_binds ?? [],
            $boolean
        );
    }

    private function _buildWhereHas($relationTable, $foreignKey, $localKey, ?\Closure $callback = null, string $operator = 'AND', string $comparison = 'EXISTS')
    {
        $this->validateTableName($relationTable, 'Relation table');
        $this->validateColumn($foreignKey, 'Foreign key');
        $this->validateColumn($localKey, 'Local key');

        $subQueryBuilder = $this->createSubQueryBuilder();
        $subQueryBuilder->table = $relationTable;

        $safeRelationTable = '`' . str_replace('`', '``', $relationTable) . '`';
        $safeForeignKey    = $safeRelationTable . '.`' . str_replace('`', '``', $foreignKey) . '`';
        $safeLocalKey      = $this->_escapeJoinColumn($localKey);

        $subquery = "SELECT 1 FROM {$safeRelationTable}";

        if (!empty($subQueryBuilder->joins)) {
            $subquery .= ' ' . trim($subQueryBuilder->joins);
        }

        $subConditions = ["{$safeForeignKey} = {$safeLocalKey}"];

        if ($callback !== null && $callback instanceof \Closure) {
            $callback($subQueryBuilder);
        }

        if (!empty($subQueryBuilder->joins)) {
            $subquery = "SELECT 1 FROM {$safeRelationTable} " . trim($subQueryBuilder->joins);
        }

        if (!empty($subQueryBuilder->where)) {
            $subConditions[] = '(' . trim($subQueryBuilder->where) . ')';
        }

        $subquery .= ' WHERE ' . implode(' AND ', $subConditions);

        $fullQuery = "{$comparison} ({$subquery})";
        $this->_whereRawInternal($fullQuery, $subQueryBuilder->_binds ?? [], $operator);

        return $this;
    }

    /**
     * Add an EXISTS predicate for a related table.
     *
     * @return $this
     */
    public function whereHas($relationTable, $foreignKey, $localKey, ?\Closure $callback = null)
    {
        return $this->_buildWhereHas($relationTable, $foreignKey, $localKey, $callback, 'AND', 'EXISTS');
    }

    /**
     * Add an OR EXISTS predicate for a related table.
     *
     * @return $this
     */
    public function orWhereHas($relationTable, $foreignKey, $localKey, ?\Closure $callback = null)
    {
        return $this->_buildWhereHas($relationTable, $foreignKey, $localKey, $callback, 'OR', 'EXISTS');
    }

    /**
     * Add a NOT EXISTS predicate for a related table.
     *
     * @return $this
     */
    public function whereDoesntHave($relationTable, $foreignKey, $localKey, ?\Closure $callback = null)
    {
        return $this->_buildWhereHas($relationTable, $foreignKey, $localKey, $callback, 'AND', 'NOT EXISTS');
    }

    /**
     * Add an OR NOT EXISTS predicate for a related table.
     *
     * @return $this
     */
    public function orWhereDoesntHave($relationTable, $foreignKey, $localKey, ?\Closure $callback = null)
    {
        return $this->_buildWhereHas($relationTable, $foreignKey, $localKey, $callback, 'OR', 'NOT EXISTS');
    }

    /**
     * Append a normalized WHERE fragment and merge any required bindings.
     *
     * @return void
     */
    protected function _buildWhereClause($columnName, $value = null, $operator = '=', $whereType = 'AND')
    {
        if ($this->where === null || $this->where === '') {
            $this->where = "";
        } else {
            $this->where .= " $whereType ";
        }

        $this->validateColumn($columnName);

        $placeholder = '?';

        switch ($operator) {
            case 'IN':
            case 'NOT IN':
                if (!is_array($value)) {
                    throw new \InvalidArgumentException('Value for IN or NOT IN operator must be an array');
                }
                $this->where .= "$columnName $operator (" . implode(',', array_fill(0, count($value), $placeholder)) . ")";
                break;
            case 'BETWEEN':
            case 'NOT BETWEEN':
                if (!is_array($value) || count($value) !== 2) {
                    throw new \InvalidArgumentException("Value for 'BETWEEN' or 'NOT BETWEEN' operator must be an array with two elements (start and end)");
                }
                $this->where .= "($columnName $operator $placeholder AND $placeholder)";
                break;
            case 'JSON':
                $this->where .= "$columnName";
                break;
            case 'IS NULL':
            case 'IS NOT NULL':
                $this->where .= "$columnName $operator";
                break;
            case 'RAW':
                $this->where .= "($columnName)";
                break;
            default:
                if ($value === '' && ($operator == '=' || $operator == '!=')) {
                    $this->where .= "$columnName $operator ''";
                } else {
                    $this->where .= "$columnName $operator $placeholder";
                }
                break;
        }

        if ($value !== null && !($value === '' && ($operator === '=' || $operator === '!='))) {
            if (is_array($value)) {
                $this->_binds = [...$this->_binds, ...$value];
            } else {
                $this->_binds[] = $value;
            }
        }
    }
}
