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
     * @param string|null $operator The comparison operator
     * @param mixed|null $value The date to compare.
     * @return $this
     */
    public function whereDate(string $column, ?string $operator, ?string $value);

    /**
     * Adds a OR whereDate clause to the query.
     *
     * @param string $column The column name.
     * @param string|null $operator The comparison operator.
     * @param mixed|null $value The date to compare.
     * @return $this
     */
    public function orWhereDate(string $column, ?string $operator, ?string $value);

    /**
     * Adds a whereDay clause to the query.
     *
     * @param string $column The column name.
     * @param string|null $operator The comparison operator.
     * @param mixed|null $value The day to compare.
     * @return $this
     */
    public function whereDay(string $column, ?string $operator, ?string $value);

    /**
     * Adds a OR whereDay clause to the query.
     *
     * @param string $column The column name.
     * @param string|null $operator The comparison operator.
     * @param mixed|null $value The day to compare.
     * @return $this
     */
    public function orWhereDay(string $column, ?string $operator, ?string $value);

    /**
     * Adds a whereMonth clause to the query.
     *
     * @param string $column The column name.
     * @param string|null $operator The comparison operator.
     * @param mixed|null $value The month to compare.
     * @return $this
     */
    public function whereMonth(string $column, ?string $operator, ?string $value);

    /**
     * Adds a OR whereMonth clause to the query.
     *
     * @param string $column The column name.
     * @param string|null $operator The comparison operator.
     * @param mixed|null $value The month to compare.
     * @return $this
     */
    public function orWhereMonth(string $column, ?string $operator, ?string $value);

    /**
     * Adds a whereYear clause to the query.
     *
     * @param string $column The column name.
     * @param string|null $operator The comparison operator.
     * @param mixed|null $value The year to compare.
     * @return $this
     */
    public function whereYear(string $column, ?string $operator, ?string $value);

    /**
     * Adds a OR whereYear clause to the query.
     *
     * @param string $column The column name.
     * @param string|null $operator The comparison operator.
     * @param mixed|null $value The year to compare.
     * @return $this
     */
    public function orWhereYear(string $column, ?string $operator, ?string $value);

    /**
     * Adds a whereTime clause to the query.
     *
     * @param string $column The column name.
     * @param string|null $operator The comparison operator.
     * @param mixed|null $value The time to compare (e.g. '14:30:00').
     * @return $this
     */
    public function whereTime(string $column, ?string $operator, ?string $value);

    /**
     * Adds an OR whereTime clause to the query.
     *
     * @param string $column The column name.
     * @param string|null $operator The comparison operator.
     * @param mixed|null $value The time to compare (e.g. '14:30:00').
     * @return $this
     */
    public function orWhereTime(string $column, ?string $operator, ?string $value);

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
    public function orderByRaw(string $string, ?string $bindParams);

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

    /**
     * Adds a subquery count of a related one-to-many relationship, similar to Laravel's withCount().
     *
     * This method appends an aggregate count column (e.g., `${aliasKey}_count`) to the main query,
     * representing the number of related records from the given table.
     *
     * @param string $aliasKey The alias used for the count column (e.g., 'comments' becomes 'comments_count').
     * @param string $table The related table to count records from.
     * @param string $foreignKey The foreign key on the related table pointing to the parent (e.g., 'post_id').
     * @param string $localKey The local key on the parent table (e.g., 'id').
     * @param \Closure|null $callback Optional query customization for filtering the related records before counting.
     * @return $this
     */
    public function withCount($aliasKey, $table, $foreignKey, $localKey, \Closure $callback = null);

    /**
     * Adds a subquery sum of a related one-to-many relationship column.
     *
     * This method appends an aggregate sum column (e.g., `${aliasKey}_sum`) to the main query,
     * representing the total sum of a specific column from related records in the given table.
     *
     * @param string $aliasKey The alias used for the sum column (e.g., 'order_totals' becomes 'order_totals_sum').
     * @param string $table The related table to sum records from.
     * @param string $foreignKey The foreign key on the related table pointing to the parent (e.g., 'user_id').
     * @param string $localKey The local key on the parent table (e.g., 'id').
     * @param string $sumColumn The column to sum in the related table (e.g., 'amount').
     * @param \Closure|null $callback Optional query customization for filtering the related records before summing.
     * @return $this
     */
    public function withSum($aliasKey, $table, $foreignKey, $localKey, $sumColumn, \Closure $callback = null);

    /**
     * Adds a subquery average of a related one-to-many relationship column.
     *
     * This method appends an aggregate average column (e.g., `${aliasKey}_avg`) to the main query,
     * representing the average value of a specific column from related records in the given table.
     *
     * @param string $aliasKey The alias used for the average column (e.g., 'ratings' becomes 'ratings_avg').
     * @param string $table The related table to average records from.
     * @param string $foreignKey The foreign key on the related table pointing to the parent (e.g., 'product_id').
     * @param string $localKey The local key on the parent table (e.g., 'id').
     * @param string $avgColumn The column to average in the related table (e.g., 'rating_value').
     * @param \Closure|null $callback Optional query customization for filtering the related records before averaging.
     * @return $this
     */
    public function withAvg($aliasKey, $table, $foreignKey, $localKey, $avgColumn, \Closure $callback = null);

    /**
     * Adds a subquery minimum value of a related one-to-many relationship column.
     *
     * This method appends an aggregate minimum column (e.g., `${aliasKey}_min`) to the main query,
     * representing the minimum value of a specific column from related records in the given table.
     *
     * @param string $aliasKey The alias used for the minimum column (e.g., 'prices' becomes 'prices_min').
     * @param string $table The related table to find minimum values from.
     * @param string $foreignKey The foreign key on the related table pointing to the parent (e.g., 'product_id').
     * @param string $localKey The local key on the parent table (e.g., 'id').
     * @param string $minColumn The column to find minimum value in the related table (e.g., 'price').
     * @param \Closure|null $callback Optional query customization for filtering the related records before finding minimum.
     * @return $this
     */
    public function withMin($aliasKey, $table, $foreignKey, $localKey, $minColumn, \Closure $callback = null);

    /**
     * Adds a subquery maximum value of a related one-to-many relationship column.
     *
     * This method appends an aggregate maximum column (e.g., `${aliasKey}_max`) to the main query,
     * representing the maximum value of a specific column from related records in the given table.
     *
     * @param string $aliasKey The alias used for the maximum column (e.g., 'scores' becomes 'scores_max').
     * @param string $table The related table to find maximum values from.
     * @param string $foreignKey The foreign key on the related table pointing to the parent (e.g., 'user_id').
     * @param string $localKey The local key on the parent table (e.g., 'id').
     * @param string $maxColumn The column to find maximum value in the related table (e.g., 'score').
     * @param \Closure|null $callback Optional query customization for filtering the related records before finding maximum.
     * @return $this
     */
    public function withMax($aliasKey, $table, $foreignKey, $localKey, $maxColumn, \Closure $callback = null);
}
