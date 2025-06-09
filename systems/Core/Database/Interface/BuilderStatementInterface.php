<?php

declare(strict_types=1);

namespace Core\Database\Interface;

/**
 * Database Builder Statement Interface
 *
 * This interface defines methods for building and executing SELECT queries
 * in a secure and flexible way. It utilizes prepared statements to prevent
 * SQL injection vulnerabilities.
 *
 * @category Database
 * @package Core\Database
 * @author 
 * @license http://opensource.org/licenses/gpl-3.0.html GNU Public License
 * @link 
 * @version 0.0.1
 */

interface BuilderStatementInterface
{
    /**
     * Reset the query statement
     *
     * @return void
     */
    public function reset();

    /**
     * Specifies the table to perform the query on.
     *
     * @param string $table The name of the table.
     * @return $this
     */
    public function table(string $table);

    /**
     * Specifies the columns to select in the query.
     *
     * @param string|array $columns The columns to select, default is '*'.
     * @return $this
     */
    public function select($columns = '*');

    /**
     * Adds a raw where clause to the query.
     *
     * @param string $rawQuery The raw where query string.
     * @param array $binds An associative array of parameter names and their values.
     * @param string $whereType The type of where clause ('AND' or 'OR').
     * @return $this
     */
    public function whereRaw(string $rawQuery, array $binds = [], string $whereType = 'AND');

    /**
     * Adds a where clause to the query.
     *
     * @param string|array|callable $column The column name.
     * @param mixed $value The value to compare.
     * @param string $operator The comparison operator, default is '='.
     * @return $this
     */
    public function where(string|array|callable $column, ?string $value = null, string $operator = '=');

    /**
     * Adds an OR where clause to the query.
     *
     * @param string|array|callable $column The column name.
     * @param mixed $value The value to compare.
     * @param string $operator The comparison operator, default is '='.
     * @return $this
     */
    public function orWhere(string|array|callable $column, ?string $value = null, string $operator = '=');

    /**
     * Adds a whereIn clause to the query.
     *
     * @param string $column The column name.
     * @param array $value The array of values to compare.
     * @return $this
     */
    public function whereIn(string $column, array $value = []);

    /**
     * Adds an OR whereIn clause to the query.
     *
     * @param string $column The column name.
     * @param array $value The array of values to compare.
     * @return $this
     */
    public function orWhereIn(string $column, array $value = []);

    /**
     * Adds a whereNotIn clause to the query.
     *
     * @param string $column The column name.
     * @param array $value The array of values to compare.
     * @return $this
     */
    public function whereNotIn(string $column, $value = []);

    /**
     * Adds an OR whereNotIn clause to the query.
     *
     * @param string $column The column name.
     * @param array $value The array of values to compare.
     * @return $this
     */
    public function orWhereNotIn(string $column, array $value = []);

    /**
     * Adds a whereBetween clause to the query.
     *
     * @param string $column The column name.
     * @param mixed $start The start value.
     * @param mixed $end The end value.
     * @return $this
     */
    public function whereBetween(string $column, string $start, string $end);

    /**
     * Adds an OR whereBetween clause to the query.
     *
     * @param string $column The column name.
     * @param mixed $start The start value.
     * @param mixed $end The end value.
     * @return $this
     */
    public function orWhereBetween(string $column, string $start, string $end);

    /**
     * Adds a whereNotBetween clause to the query.
     *
     * @param string $column The column name.
     * @param mixed $start The start value.
     * @param mixed $end The end value.
     * @return $this
     */
    public function whereNotBetween(string $column, string $start, string $end);

    /**
     * Adds an OR whereNotBetween clause to the query.
     *
     * @param string $column The column name.
     * @param mixed $start The start value.
     * @param mixed $end The end value.
     * @return $this
     */
    public function orWhereNotBetween(string $column, string $start, string $end);

    /**
     * Adds a whereNull clause to the query.
     *
     * @param string $column The column name.
     * @return $this
     */
    public function whereNull(string $column);

    /**
     * Adds an OR whereNull clause to the query.
     *
     * @param string $column The column name.
     * @return $this
     */
    public function orWhereNull(string $column);

    /**
     * Adds a whereNotNull clause to the query.
     *
     * @param string $column The column name.
     * @return $this
     */
    public function whereNotNull(string $column);

    /**
     * Adds an OR whereNotNull clause to the query.
     *
     * @param string $column The column name.
     * @return $this
     */
    public function orWhereNotNull(string $column);

    /**
     * Adds a whereDate clause to the query.
     *
     * @param string $column The column name.
     * @param mixed $date The date to compare.
     * @param string $operator The comparison operator, default is '='.
     * @return $this
     */
    public function whereDate(string $column, string $date, string $operator = '=');

    /**
     * Adds a OR whereDate clause to the query.
     *
     * @param string $column The column name.
     * @param mixed $date The date to compare.
     * @param string $operator The comparison operator, default is '='.
     * @return $this
     */
    public function orWhereDate(string $column, string $date, string $operator = '=');

    /**
     * Adds a whereDay clause to the query.
     *
     * @param string $column The column name.
     * @param mixed $day The day to compare.
     * @param string $operator The comparison operator, default is '='.
     * @return $this
     */
    public function whereDay(string $column, string $day, string $operator = '=');

    /**
     * Adds a OR whereDay clause to the query.
     *
     * @param string $column The column name.
     * @param mixed $day The day to compare.
     * @param string $operator The comparison operator, default is '='.
     * @return $this
     */
    public function orWhereDay(string $column, string $day, string $operator = '=');

    /**
     * Adds a whereMonth clause to the query.
     *
     * @param string $column The column name.
     * @param mixed $month The month to compare.
     * @param string $operator The comparison operator, default is '='.
     * @return $this
     */
    public function whereMonth(string $column, string $month, string $operator = '=');

    /**
     * Adds a OR whereMonth clause to the query.
     *
     * @param string $column The column name.
     * @param mixed $month The month to compare.
     * @param string $operator The comparison operator, default is '='.
     * @return $this
     */
    public function orWhereMonth(string $column, string $month, string $operator = '=');

    /**
     * Adds a whereYear clause to the query.
     *
     * @param string $column The column name.
     * @param mixed $date The year to compare.
     * @param string $operator The comparison operator, default is '='.
     * @return $this
     */
    public function whereYear(string $column, string $date, string $operator = '=');

    /**
     * Adds a OR whereYear clause to the query.
     *
     * @param string $column The column name.
     * @param mixed $date The year to compare.
     * @param string $operator The comparison operator, default is '='.
     * @return $this
     */
    public function orWhereYear(string $column, string $date, string $operator = '=');

    /**
     * Adds a where json contains clause to search within a JSON column.
     *
     * @param string $columnName The name of the JSON column.
     * @param string $jsonPath The JSON path to search within.
     * @param mixed $value The value to search for.
     * @return $this
     */
    public function whereJsonContains($columnName, $jsonPath, $value);

    /**
     * Adds a join clause to the query.
     *
     * @param string $table The table to join.
     * @param string $foreignKey The foreign key column.
     * @param string $localKey The local key column.
     * @param string $joinType The join type that only support 'INNER', 'LEFT', 'RIGHT', 'OUTER', 'LEFT OUTER', 'RIGHT OUTER'.
     * @return $this
     */
    public function join($table, $foreignKey, $localKey, $joinType = 'LEFT');

    /**
     * Adds a left join clause to the query.
     *
     * @param string $table The table to join.
     * @param string $foreignKey The foreign key column.
     * @param string $localKey The local key column.
     * @param string|null $conditions Additional conditions for the join.
     * @return $this
     */
    public function leftJoin($table, $foreignKey, $localKey, $conditions = null);

    /**
     * Adds a right join clause to the query.
     *
     * @param string $table The table to join.
     * @param string $foreignKey The foreign key column.
     * @param string $localKey The local key column.
     * @param string|null $conditions Additional conditions for the join.
     * @return $this
     */
    public function rightJoin($table, $foreignKey, $localKey, $conditions = null);

    /**
     * Adds an inner join clause to the query.
     *
     * @param string $table The table to join.
     * @param string $foreignKey The foreign key column.
     * @param string $localKey The local key column.
     * @param string|null $conditions Additional conditions for the join.
     * @return $this
     */
    public function innerJoin(string $table, string $foreignKey, string $localKey, ?string $conditions = null);

    /**
     * Adds an outer join clause to the query.
     *
     * @param string $table The table to join.
     * @param string $foreignKey The foreign key column.
     * @param string $localKey The local key column.
     * @param string|null $conditions Additional conditions for the join.
     * @return $this
     */
    public function outerJoin(string $table, string $foreignKey, string $localKey, ?string $conditions = null);

    /**
     * Adds an order by clause to the query.
     *
     * @param string|array $column The column to order by.
     * @param string $direction The direction of the order ('ASC' or 'DESC').
     * @return $this
     */
    public function orderBy(string|array $column, string $direction = 'ASC');

    /**
     * Adds a raw order by clause to the query.
     *
     * @param string $string The raw order by string.
     * @param array|null $bindParams Parameters to bind to the raw order by string.
     * @return $this
     */
    public function orderByRaw(string $string, string $bindParams = null);

    /**
     * Adds a group by clause to the query.
     *
     * @param string|array $columns The columns to group by.
     * @return $this
     */
    public function groupBy(string|array $columns);

    /**
     * Adds a having clause to the query.
     *
     * @param string $column The column name.
     * @param mixed $value The value to compare.
     * @param string $operator The comparison operator, default is '='.
     * @return $this
     */
    public function having(string $column, ?string $value, string $operator = '=');

    /**
     * Adds a raw having clause to the query.
     *
     * @param string $conditions The conditions for having query.
     * @return $this
     */
    public function havingRaw(string $conditions);

    /**
     * Adds a limit clause to the query.
     *
     * @param int $limit The number of rows to return.
     * @return $this
     */
    public function limit(int $limit);

    /**
     * Adds an offset clause to the query.
     *
     * @param int $offset The number of rows to skip.
     * @return $this
     */
    public function offset(int $offset);

    /**
     * Specifies a relationship to load with the query.
     *
     * @param string $aliasKey The alias key for the relationship.
     * @param string $table The related table.
     * @param string $foreignKey The foreign key column.
     * @param string $localKey The local key column.
     * @param \Closure|null $callback A callback function to apply to the relationship.
     * @return $this
     */
    public function with(string $aliasKey, string $table, string $foreignKey, string $localKey, \Closure $callback = null);

    /**
     * Specifies a one-to-one relationship to load with the query.
     *
     * @param string $aliasKey The alias key for the relationship.
     * @param string $table The related table.
     * @param string $foreignKey The foreign key column.
     * @param string $localKey The local key column.
     * @param \Closure|null $callback A callback function to apply to the relationship.
     * @return $this
     */
    public function withOne(string $aliasKey, string $table, string $foreignKey, string $localKey, \Closure $callback = null);
}
