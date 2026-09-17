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
 * @license http://opensource.org/licenses/gpl-3.0.html GNU Public License
 */

interface BuilderStatementInterface
{
    /**
     * Reset the query statement
     *
     * @return void
     */
    public function reset();

    /** @return $this */
    public function table(string $table);

    /** @return $this */
    public function select(string|array $columns = '*');

    /** @return $this */
    public function distinct(string|null $column = null);

    /**
     * Adds a raw where clause to the query.
     *
     * @param array $binds An associative array of parameter names and their values.
     * @param string $whereType The type of where clause ('AND' or 'OR').
     * @return $this
     */
    public function whereRaw(string $rawQuery, array $binds = [], string $whereType = 'AND');

    /*
    | Operator second, value third — `where('age', '>', 30)`.
    |
    | This interface declared them the other way round, `(column, value,
    | operator)`, while every implementation and every call site has always
    | used `(column, operator, value)`. PHP checks arity and types but never
    | parameter names, so the two never had to agree: the lie survived because
    | nothing could detect it. Anyone who trusted the interface got
    | "Invalid operator", and a named argument bound to a parameter that does
    | not exist.
    |
    | The two-argument form `where('id', 5)` still means equality.
    */

    /** @return $this */
    public function where(string|array|\Closure $column, mixed $operator = null, mixed $value = null);

    /** @return $this */
    public function orWhere(string|array|\Closure $column, mixed $operator = null, mixed $value = null);

    /** @return $this */
    public function whereIn(string $column, array $value = []);

    /** @return $this */
    public function orWhereIn(string $column, array $value = []);

    /** @return $this */
    public function whereNotIn(string $column, array $value = []);

    /** @return $this */
    public function orWhereNotIn(string $column, array $value = []);

    /** @return $this */
    public function whereBetween(string $column, mixed $start, mixed $end);

    /** @return $this */
    public function orWhereBetween(string $column, mixed $start, mixed $end);

    /** @return $this */
    public function whereNotBetween(string $column, string $start, string $end);

    /** @return $this */
    public function orWhereNotBetween(string $column, string $start, string $end);

    /** @return $this */
    public function whereNull(string $column);

    /** @return $this */
    public function orWhereNull(string $column);

    /** @return $this */
    public function whereNotNull(string $column);

    /** @return $this */
    public function orWhereNotNull(string $column);

    /**
     * Adds a WHERE NOT clause (negated condition or grouped closure).
     *
     * @return $this
     */
    public function whereNot($column, $operator = null, $value = null);

    /** @return $this */
    public function orWhereNot($column, $operator = null, $value = null);

    /** @return $this */
    public function whereLike($column, $value);

    /** @return $this */
    public function orWhereLike($column, $value);

    /** @return $this */
    public function whereNotLike($column, $value);

    /** @return $this */
    public function orWhereNotLike($column, $value);

    /**
     * Adds a WHERE IN clause for raw integer values (no binding, faster for large lists).
     *
     * @return $this
     */
    public function whereIntegerInRaw($column, array $values);

    /**
     * Adds a WHERE NOT IN clause for raw integer values.
     *
     * @return $this
     */
    public function whereIntegerNotInRaw($column, array $values);

    /** @return $this */
    public function whereDate(string $column, ?string $operator, ?string $value);

    /** @return $this */
    public function orWhereDate(string $column, ?string $operator, ?string $value);

    /** @return $this */
    public function whereDay(string $column, ?string $operator, ?string $value);

    /** @return $this */
    public function orWhereDay(string $column, ?string $operator, ?string $value);

    /** @return $this */
    public function whereMonth(string $column, ?string $operator, ?string $value);

    /** @return $this */
    public function orWhereMonth(string $column, ?string $operator, ?string $value);

    /** @return $this */
    public function whereYear(string $column, ?string $operator, ?string $value);

    /** @return $this */
    public function orWhereYear(string $column, ?string $operator, ?string $value);

    /**
     * @param string|null $value The time to compare (e.g. '14:30:00').
     * @return $this
     */
    public function whereTime(string $column, ?string $operator, ?string $value);

    /**
     * @param string|null $value The time to compare (e.g. '14:30:00').
     * @return $this
     */
    public function orWhereTime(string $column, ?string $operator, ?string $value);

    /**
     * Adds a where json contains clause to search within a JSON column.
     *
     * @param string $jsonPath The JSON path to search within.
     * @return $this
     */
    public function whereJsonContains(string $columnName, string $jsonPath, $value);

    /**
     * Add a where clause comparing two columns
     *
     * @param string|null $operator Comparison operator (if null, defaults to '=')
     * @param string|null $column2 Second column (if null, operator becomes '=' and column2 becomes operator)
     * @return $this
     */
    public function whereColumn(string $column1, ?string $operator = null, ?string $column2 = null);

    /**
     * Add an or where clause comparing two columns
     *
     * @return $this
     */
    public function orWhereColumn(string $column1, ?string $operator = null, ?string $column2 = null);

    /**
     * Add a WHERE clause matching ANY of the given columns.
     *
     * @return $this
     */
    public function whereAny(array $columns, $operator, $value);

    /**
     * Add a WHERE clause matching ALL of the given columns.
     *
     * @return $this
     */
    public function whereAll(array $columns, $operator, $value);

    /**
     * Add a WHERE clause matching NONE of the given columns.
     *
     * @return $this
     */
    public function whereNone(array $columns, $operator, $value);

    /**
     * Add a WHERE clause checking if a column value is between two other column values.
     *
     * @param array $columns Array of two column names [min_column, max_column].
     * @return $this
     */
    public function whereBetweenColumns($column, array $columns);

    /**
     * @param array $options Options: 'mode' => 'boolean'|'natural'|'expansion'.
     * @return $this
     */
    public function whereFullText($columns, $value, array $options = []);

    /**
     * Conditionally adds query constraints if the given conditions are true.
     *
     * This method allows you to fluently add query clauses only when certain conditions are met.
     * If $conditions evaluates to true, the $callback is executed and receives the current query builder instance.
     *
     * Example usage:
     *   $builder->when($isActive, function($query) {
     *       $query->where('status', 'active');
     *   });
     *
     * @param mixed $conditions The condition(s) to evaluate (value, or closure).
     * @param callable $callback The callback to execute if the condition is true. Receives the builder instance.
     * @return $this
     */
    public function when(mixed $conditions, callable $callback);

    /**
     * Execute callback unless condition is true (opposite of when)
     *
     * @param callable $callback Callback to execute if condition is false
     * @return $this
     */
    public function unless(mixed $condition, callable $callback);

    /**
     * Execute callback and return the builder for debugging
     *
     * @param callable $callback Callback receives the builder instance
     * @return $this
     */
    public function tap(callable $callback);

    /**
     * @param string $joinType The join type that only support 'INNER', 'LEFT', 'RIGHT', 'OUTER', 'LEFT OUTER', 'RIGHT OUTER'.
     * @return $this
     */
    public function join(string $table, string $foreignKey, string $localKey, string $joinType = 'LEFT');

    /**
     * @param string|\Closure|null $conditions Additional conditions for the join. Can be a string or a closure for complex conditions.
     * @return $this
     */
    public function leftJoin(string $table, string $foreignKey, string $localKey, string|\Closure|null $conditions = null);

    /**
     * @param string|\Closure|null $conditions Additional conditions for the join. Can be a string or a closure for complex conditions.
     * @return $this
     */
    public function rightJoin(string $table, string $foreignKey, string $localKey, string|\Closure|null $conditions = null);

    /**
     * @param string|\Closure|null $conditions Additional conditions for the join. Can be a string or a closure for complex conditions.
     * @return $this
     */
    public function innerJoin(string $table, string $foreignKey, string $localKey, string|\Closure|null $conditions = null);

    /**
     * @param string|\Closure|null $conditions Additional conditions for the join. Can be a string or a closure for complex conditions.
     * @return $this
     */
    public function outerJoin(string $table, string $foreignKey, string $localKey, string|\Closure|null $conditions = null);

    /**
     * @param string $direction The direction of the order ('ASC' or 'DESC').
     * @return $this
     */
    public function orderBy(string|array $column, string $direction = 'ASC');

    /**
     * Order by column in descending order (created_at by default)
     *
     * @return $this
     */
    public function latest(string $column = 'created_at');

    /**
     * Order by column in ascending order (created_at by default)
     *
     * @return $this
     */
    public function oldest(string $column = 'created_at');

    /**
     * Clear existing order by and optionally set new order
     *
     * @return $this
     */
    public function reorder(?string $column = null, string $direction = 'DESC');

    /**
     * Order results randomly
     *
     * @return $this
     */
    public function inRandomOrder();

    /**
     * Order by a column in descending order.
     *
     * @return $this
     */
    public function orderByDesc($column);

    /**
     * Order by a column in ascending order.
     *
     * @return $this
     */
    public function orderByAsc($column);

    /** @return $this */
    public function crossJoin($table);

    /**
     * Add a subquery select expression.
     *
     * @param \Closure|string $query Closure or raw SQL for the subquery.
     * @return $this
     */
    public function selectSub($query, $alias);

    /**
     * Adds a raw order by clause to the query.
     *
     * @param string|null $bindParams Parameters to bind to the raw order by string.
     * @return $this
     */
    public function orderByRaw(string $string, ?string $bindParams);

    /** @return $this */
    public function groupBy(string|array $columns);

    /**
     * Adds a raw GROUP BY expression.
     *
     * @param string $expression The raw GROUP BY expression.
     * @return $this
     */
    public function groupByRaw($expression, array $bindings = []);

    /** @return $this */
    public function having(string $column, ?string $value, string $operator = '=');

    /**
     * Adds a raw having clause to the query.
     *
     * @return $this
     */
    public function havingRaw(string $conditions);

    /** @return $this */
    public function havingBetween($column, array $values);

    /** @return $this */
    public function limit(int $limit);

    /** @return $this */
    public function offset(int $offset);

    /** @return $this */
    public function skip(int $offset);

    /** @return $this */
    public function take(int $limit);

    /**
     * Simple pagination helper - set offset and limit for a page
     *
     * @param int $page Page number (1-indexed)
     * @return $this
     */
    public function forPage(int $page, int $perPage = 15);

    /** @return $this */
    public function with(string $aliasKey, string $table, string $foreignKey, string $localKey, ?\Closure $callback = null);

    /**
     * Specifies a one-to-one relationship to load with the query.
     *
     * @return $this
     */
    public function withOne(string $aliasKey, string $table, string $foreignKey, string $localKey, ?\Closure $callback = null);

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
    public function withCount(string $aliasKey, string $table, string $foreignKey, string $localKey, ?\Closure $callback = null);

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
    public function withSum(string $aliasKey, string $table, string $foreignKey, string $localKey, string $sumColumn, ?\Closure $callback = null);

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
    public function withAvg(string $aliasKey, string $table, string $foreignKey, string $localKey, string $avgColumn, ?\Closure $callback = null);

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
    public function withMin($aliasKey, $table, $foreignKey, $localKey, $minColumn, ?\Closure $callback = null);

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
    public function withMax($aliasKey, $table, $foreignKey, $localKey, $maxColumn, ?\Closure $callback = null);

    /**
     * @param \Closure|null $callback A callback to apply additional conditions on the relationship query
     * @return $this
     */
    public function whereHas(string $relationTable, string $foreignKey, string $localKey, ?\Closure $callback = null);

    /**
     * @param \Closure|null $callback A callback to apply additional conditions on the relationship query
     * @return $this
     */
    public function orWhereHas(string $relationTable, string $foreignKey, string $localKey, ?\Closure $callback = null);

    /**
     * @param \Closure|null $callback A callback to apply additional conditions on the relationship query
     * @return $this
     */
    public function whereDoesntHave(string $relationTable, string $foreignKey, string $localKey, ?\Closure $callback = null);

    /**
     * @param \Closure|null $callback A callback to apply additional conditions on the relationship query
     * @return $this
     */
    public function orWhereDoesntHave(string $relationTable, string $foreignKey, string $localKey, ?\Closure $callback = null);
}
