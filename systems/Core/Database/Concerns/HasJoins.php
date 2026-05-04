<?php

namespace Core\Database\Concerns;

/**
 * Trait HasJoins
 *
 * Provides all JOIN builder methods: join, leftJoin, rightJoin, innerJoin,
 * outerJoin, crossJoin, _escapeJoinColumn, _buildJoinConditions.
 *
 * Consumed by: BaseDatabase
 *
 * @category Database
 * @package  Core\Database\Concerns
 */
trait HasJoins
{
    /**
     * Add a generic join clause to the query.
     *
     * @param string $table
     * @param string $foreignKey
     * @param string $localKey
     * @param string $joinType
     * @return $this
     */
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

        $safeTable    = '`' . str_replace('`', '``', $table) . '`';
        $safeLocalKey = $this->_escapeJoinColumn($localKey);

        $this->joins .= " $joinType JOIN $safeTable ON $safeTable.`$foreignKey` = $safeLocalKey";

        return $this;
    }

    /**
     * Add a LEFT JOIN clause to the query.
     *
     * @param string $table
     * @param string $foreignKey
     * @param string $localKey
     * @param \Closure|null $conditions
     * @return $this
     */
    public function leftJoin($table, $foreignKey, $localKey, $conditions = null)
    {
        if (empty($this->table)) {
            throw new \Exception('No table selected', 400);
        }

        $this->validateTableName($table, 'Join table');
        $this->validateColumn($foreignKey, 'Foreign Key');
        $this->validateColumn($localKey, 'Local Key');

        $safeTable    = '`' . str_replace('`', '``', $table) . '`';
        $safeLocalKey = $this->_escapeJoinColumn($localKey);

        $joinClause = " LEFT JOIN $safeTable ON $safeTable.`$foreignKey` = $safeLocalKey";
        $joinClause .= $this->_buildJoinConditions($conditions, $table);

        $this->joins .= $joinClause;
        return $this;
    }

    /**
     * Add a RIGHT JOIN clause to the query.
     *
     * @param string $table
     * @param string $foreignKey
     * @param string $localKey
     * @param \Closure|null $conditions
     * @return $this
     */
    public function rightJoin($table, $foreignKey, $localKey, $conditions = null)
    {
        if (empty($this->table)) {
            throw new \Exception('No table selected', 400);
        }

        $this->validateTableName($table, 'Join table');
        $this->validateColumn($foreignKey, 'Foreign Key');
        $this->validateColumn($localKey, 'Local Key');

        $safeTable    = '`' . str_replace('`', '``', $table) . '`';
        $safeLocalKey = $this->_escapeJoinColumn($localKey);

        $joinClause = " RIGHT JOIN $safeTable ON $safeTable.`$foreignKey` = $safeLocalKey";
        $joinClause .= $this->_buildJoinConditions($conditions, $table);

        $this->joins .= $joinClause;
        return $this;
    }

    /**
     * Add an INNER JOIN clause to the query.
     *
     * @param string $table
     * @param string $foreignKey
     * @param string $localKey
     * @param \Closure|null $conditions
     * @return $this
     */
    public function innerJoin($table, $foreignKey, $localKey, $conditions = null)
    {
        if (empty($this->table)) {
            throw new \Exception('No table selected', 400);
        }

        $this->validateTableName($table, 'Join table');
        $this->validateColumn($foreignKey, 'Foreign Key');
        $this->validateColumn($localKey, 'Local Key');

        $safeTable    = '`' . str_replace('`', '``', $table) . '`';
        $safeLocalKey = $this->_escapeJoinColumn($localKey);

        $joinClause = " INNER JOIN $safeTable ON $safeTable.`$foreignKey` = $safeLocalKey";
        $joinClause .= $this->_buildJoinConditions($conditions, $table);

        $this->joins .= $joinClause;
        return $this;
    }

    /**
     * Add a FULL OUTER JOIN clause to the query.
     *
     * @param string $table
     * @param string $foreignKey
     * @param string $localKey
     * @param \Closure|null $conditions
     * @return $this
     */
    public function outerJoin($table, $foreignKey, $localKey, $conditions = null)
    {
        if (empty($this->table)) {
            throw new \Exception('No table selected', 400);
        }

        $this->validateTableName($table, 'Join table');
        $this->validateColumn($foreignKey, 'Foreign Key');
        $this->validateColumn($localKey, 'Local Key');

        $safeTable    = '`' . str_replace('`', '``', $table) . '`';
        $safeLocalKey = $this->_escapeJoinColumn($localKey);

        $joinClause = " FULL OUTER JOIN $safeTable ON $safeTable.`$foreignKey` = $safeLocalKey";
        $joinClause .= $this->_buildJoinConditions($conditions, $table);

        $this->joins .= $joinClause;
        return $this;
    }

    /**
     * Add a CROSS JOIN clause to the query.
     *
     * @param string $table
     * @return $this
     */
    public function crossJoin($table)
    {
        $table = trim($table);
        $this->validateTableName($table, 'Cross join table name');

        $safeTable = str_replace('`', '``', $table);
        $this->joins .= " CROSS JOIN `{$safeTable}`";
        return $this;
    }

    /**
     * Escape a column reference used in JOIN ON clauses.
     * Accepts plain column names, dot-notation (table.column), or already-backticked identifiers.
     *
     * @param string $column
     * @return string Backtick-quoted identifier
     */
    protected function _escapeJoinColumn($column)
    {
        $column = trim($column);

        if (strpos($column, '`') !== false) {
            return $column;
        }

        if (strpos($column, '.') !== false) {
            $parts = explode('.', $column, 2);
            return '`' . str_replace('`', '``', $parts[0]) . '`.`' . str_replace('`', '``', $parts[1]) . '`';
        }

        if (!empty($this->table)) {
            return '`' . str_replace('`', '``', $this->table) . '`.`' . str_replace('`', '``', $column) . '`';
        }

        return '`' . str_replace('`', '``', $column) . '`';
    }

    /**
     * Build additional JOIN conditions from a Closure.
     * Raw string conditions are rejected to prevent SQL injection.
     *
     * @param mixed $conditions Closure for additional ON conditions, or null.
     * @param string $table The joined table name.
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
}
