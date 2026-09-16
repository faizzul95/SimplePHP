<?php

namespace Core\Database\Concerns;

/**
 * Trait HasJoins
 *
 * Provides all JOIN builder methods: join, leftJoin, rightJoin, innerJoin,
 * outerJoin, crossJoin, _escapeJoinColumn, _buildJoinConditions.
 *
 * Consumed by: BaseDatabase
 */
trait HasJoins
{
    /** @return $this */
    public function join($table, $foreignKey, $localKey, $joinType = 'LEFT')
    {
        if (empty($this->table)) {
            throw new \Exception('No table selected', 400);
        }

        $this->validateTableName($table, 'Join table');
        $this->validateColumn($foreignKey, 'Foreign Key');
        $this->validateColumn($localKey, 'Local Key');

        $validJoinTypes = ['INNER', 'LEFT', 'RIGHT', 'OUTER', 'LEFT OUTER', 'RIGHT OUTER'];
        $joinType = strtoupper(trim($joinType));
        if (!in_array($joinType, $validJoinTypes)) {
            throw new \InvalidArgumentException('Invalid join type. Valid types are: ' . implode(', ', $validJoinTypes));
        }

        // $foreignKey used to be interpolated raw between backticks, so a backtick
        // in it closed the identifier quoting and the rest reached the server as SQL.
        // validateColumn() above does not catch that — it only asserts non-empty string.
        $safeTable      = $this->quoteIdentifier($table, 'Join table');
        $safeForeignKey = $this->quoteIdentifier($foreignKey, 'Foreign Key', $table);
        $safeLocalKey   = $this->_escapeJoinColumn($localKey, 'Local Key');

        $this->joins .= " $joinType JOIN $safeTable ON $safeForeignKey = $safeLocalKey";

        return $this;
    }

    /** @return $this */
    public function leftJoin($table, $foreignKey, $localKey, $conditions = null)
    {
        if (empty($this->table)) {
            throw new \Exception('No table selected', 400);
        }

        $this->validateTableName($table, 'Join table');
        $this->validateColumn($foreignKey, 'Foreign Key');
        $this->validateColumn($localKey, 'Local Key');

        // $foreignKey used to be interpolated raw between backticks, so a backtick
        // in it closed the identifier quoting and the rest reached the server as SQL.
        // validateColumn() above does not catch that — it only asserts non-empty string.
        $safeTable      = $this->quoteIdentifier($table, 'Join table');
        $safeForeignKey = $this->quoteIdentifier($foreignKey, 'Foreign Key', $table);
        $safeLocalKey   = $this->_escapeJoinColumn($localKey, 'Local Key');

        $joinClause = " LEFT JOIN $safeTable ON $safeForeignKey = $safeLocalKey";
        $joinClause .= $this->_buildJoinConditions($conditions, $table);

        $this->joins .= $joinClause;
        return $this;
    }

    /** @return $this */
    public function rightJoin($table, $foreignKey, $localKey, $conditions = null)
    {
        if (empty($this->table)) {
            throw new \Exception('No table selected', 400);
        }

        $this->validateTableName($table, 'Join table');
        $this->validateColumn($foreignKey, 'Foreign Key');
        $this->validateColumn($localKey, 'Local Key');

        // $foreignKey used to be interpolated raw between backticks, so a backtick
        // in it closed the identifier quoting and the rest reached the server as SQL.
        // validateColumn() above does not catch that — it only asserts non-empty string.
        $safeTable      = $this->quoteIdentifier($table, 'Join table');
        $safeForeignKey = $this->quoteIdentifier($foreignKey, 'Foreign Key', $table);
        $safeLocalKey   = $this->_escapeJoinColumn($localKey, 'Local Key');

        $joinClause = " RIGHT JOIN $safeTable ON $safeForeignKey = $safeLocalKey";
        $joinClause .= $this->_buildJoinConditions($conditions, $table);

        $this->joins .= $joinClause;
        return $this;
    }

    /** @return $this */
    public function innerJoin($table, $foreignKey, $localKey, $conditions = null)
    {
        if (empty($this->table)) {
            throw new \Exception('No table selected', 400);
        }

        $this->validateTableName($table, 'Join table');
        $this->validateColumn($foreignKey, 'Foreign Key');
        $this->validateColumn($localKey, 'Local Key');

        // $foreignKey used to be interpolated raw between backticks, so a backtick
        // in it closed the identifier quoting and the rest reached the server as SQL.
        // validateColumn() above does not catch that — it only asserts non-empty string.
        $safeTable      = $this->quoteIdentifier($table, 'Join table');
        $safeForeignKey = $this->quoteIdentifier($foreignKey, 'Foreign Key', $table);
        $safeLocalKey   = $this->_escapeJoinColumn($localKey, 'Local Key');

        $joinClause = " INNER JOIN $safeTable ON $safeForeignKey = $safeLocalKey";
        $joinClause .= $this->_buildJoinConditions($conditions, $table);

        $this->joins .= $joinClause;
        return $this;
    }

    /** @return $this */
    public function outerJoin($table, $foreignKey, $localKey, $conditions = null)
    {
        if (empty($this->table)) {
            throw new \Exception('No table selected', 400);
        }

        $this->validateTableName($table, 'Join table');
        $this->validateColumn($foreignKey, 'Foreign Key');
        $this->validateColumn($localKey, 'Local Key');

        // $foreignKey used to be interpolated raw between backticks, so a backtick
        // in it closed the identifier quoting and the rest reached the server as SQL.
        // validateColumn() above does not catch that — it only asserts non-empty string.
        $safeTable      = $this->quoteIdentifier($table, 'Join table');
        $safeForeignKey = $this->quoteIdentifier($foreignKey, 'Foreign Key', $table);
        $safeLocalKey   = $this->_escapeJoinColumn($localKey, 'Local Key');

        $joinClause = " FULL OUTER JOIN $safeTable ON $safeForeignKey = $safeLocalKey";
        $joinClause .= $this->_buildJoinConditions($conditions, $table);

        $this->joins .= $joinClause;
        return $this;
    }

    /** @return $this */
    public function crossJoin($table)
    {
        $table = trim($table);
        $this->validateTableName($table, 'Cross join table name');

        $this->joins .= ' CROSS JOIN ' . $this->quoteIdentifier($table, 'Cross join table name');
        return $this;
    }

    /**
     * Escape a column reference used in JOIN ON clauses.
     *
     * Accepts `column`, `table.column`, or either already backtick-quoted, and
     * always re-derives the quoting from a validated identifier. It used to
     * return the input untouched whenever it already contained a backtick,
     * which meant a caller could hand it a fragment like "`id` = 1 OR `1" and
     * have it spliced straight into the ON clause.
     *
     * @param string $label Human-readable label for error messages.
     * @return string Backtick-quoted identifier
     * @throws \InvalidArgumentException When the value is not a bare identifier.
     */
    protected function _escapeJoinColumn($column, string $label = 'Join column')
    {
        return $this->quoteIdentifier(
            $column,
            $label,
            !empty($this->table) ? $this->table : null
        );
    }

    /**
     * Build additional JOIN conditions from a Closure.
     * Raw string conditions are rejected to prevent SQL injection.
     *
     * @param mixed $conditions Closure for additional ON conditions, or null.
     * @return string Additional ON clause fragment (may be empty)
     */
    protected function _buildJoinConditions($conditions, $table)
    {
        if ($conditions === null) {
            return '';
        }

        if ($conditions instanceof \Closure) {
            $db = $this->createSubQueryBuilder();
            $db->table = $table;

            $conditions($db);

            if (!empty($db->where)) {
                $clause = " AND " . ltrim($db->where, 'AND ');
                if (!empty($db->_binds)) {
                    $this->_binds = [...$this->_binds, ...$db->_binds];
                }
                unset($db);
                return $clause;
            }

            unset($db);
            return '';
        }

        throw new \InvalidArgumentException('Join conditions must be a Closure. Raw string conditions are not permitted for security reasons.');
    }

    /**
     * Join against a derived table built from a sub-query.
     *
     * The only way to express this before was query(), which gives up parameter
     * binding, profiling and the read/write router for the whole statement. It
     * is the natural shape for "join each user to their latest order" or to a
     * pre-aggregated summary:
     *
     * $db->table('users')->joinSub(
     * fn ($q) => $q->table('orders')
     * ->select('user_id, SUM(total) AS lifetime')
     * ->groupBy('user_id'),
     * 'totals',
     * 'totals.user_id',
     * 'users.id'
     * );
     *
     * @param  \Closure $callback   Receives a fresh builder for the derived table
     * @param  string   $joinType   INNER, LEFT, RIGHT, OUTER
     * @return $this
     */
    public function joinSub(\Closure $callback, string $alias, string $foreignKey, string $localKey, string $joinType = 'INNER')
    {
        if (empty($this->table)) {
            throw new \Exception('No table selected', 400);
        }

        $this->validateTableName($alias, 'Sub-query alias');
        $this->validateColumn($foreignKey, 'Foreign Key');
        $this->validateColumn($localKey, 'Local Key');

        $validJoinTypes = ['INNER', 'LEFT', 'RIGHT', 'OUTER', 'LEFT OUTER', 'RIGHT OUTER'];
        $joinType = strtoupper(trim($joinType));
        if (!in_array($joinType, $validJoinTypes, true)) {
            throw new \InvalidArgumentException('Invalid join type. Valid types are: ' . implode(', ', $validJoinTypes));
        }

        $sub = $this->createSubQueryBuilder();
        $callback($sub);

        if (empty($sub->table)) {
            throw new \InvalidArgumentException('joinSub(): the sub-query has no table. Call $query->table(...) inside the closure.');
        }

        $sub->_buildSelectQuery();
        $sql = trim((string) $sub->_query);

        if ($sql === '') {
            throw new \RuntimeException('joinSub(): the sub-query produced no SQL.');
        }

        $safeAlias      = $this->quoteIdentifier($alias, 'Sub-query alias');
        $safeForeignKey = $this->quoteIdentifier($foreignKey, 'Foreign Key', $alias);
        $safeLocalKey   = $this->_escapeJoinColumn($localKey, 'Local Key');

        /*
        | A derived table's bindings sit between the outer SELECT list and the
        | WHERE clause, so they cannot simply be appended to $this->_binds — that
        | would place them after any binding a where() added first, and the
        | placeholders would receive each other's values.
        |
        | Joins are built before the where clause, so prepending here is correct
        | for the ordering the compiler produces. Calling joinSub() after a
        | where() on the same builder is the one case this cannot fix, and it is
        | rejected rather than silently mis-bound.
        */
        if (!empty($this->_binds) && !empty($sub->_binds)) {
            throw new \LogicException(
                'joinSub() with bound values must be called before where(): the derived '
                . 'table binds ahead of the WHERE clause, so the placeholders would be filled out of order.'
            );
        }

        $this->joins .= " {$joinType} JOIN ({$sql}) AS {$safeAlias} ON {$safeForeignKey} = {$safeLocalKey}";

        if (!empty($sub->_binds)) {
            $this->_binds = [...$sub->_binds, ...$this->_binds];
        }

        return $this;
    }

    /** @return $this */
    public function leftJoinSub(\Closure $callback, string $alias, string $foreignKey, string $localKey)
    {
        return $this->joinSub($callback, $alias, $foreignKey, $localKey, 'LEFT');
    }

    /** @return $this */
    public function rightJoinSub(\Closure $callback, string $alias, string $foreignKey, string $localKey)
    {
        return $this->joinSub($callback, $alias, $foreignKey, $localKey, 'RIGHT');
    }
}
