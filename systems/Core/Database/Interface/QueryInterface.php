<?php

declare(strict_types=1);

namespace Core\Database\Interface;

/**
 * Database Query Interface
 *
 * This interface defines methods for building and executing database queries.
 * It provides a consistent way to interact with different database drivers 
 * while allowing for driver-specific implementations.
 *
 * @category Database
 * @package Core\Database
 * @author 
 * @license http://opensource.org/licenses/gpl-3.0.html GNU Public License
 * @link 
 * @version 0.0.1
 */

interface QueryInterface
{
    /**
     * Executes a SELECT SQL query with optional parameter binding and fetch type.
     *
     * This method prepares and executes a SELECT statement using the provided SQL query and optional
     * parameter bindings. It supports fetching either a single result or all results as associative arrays.
     * Only SELECT statements are allowed; other query types will result in an exception.
     *
     * @param string $statement The SELECT SQL query to execute.
     * @param array|null $binds An associative array of parameter placeholders and their values (optional).
     * @param string $fetchType Determines the fetch mode: 'fetch' for a single row, 'get' for all rows (default: 'get').
     * @return mixed The fetched result(s) as an associative array or null if no results are found.
     * @throws \InvalidArgumentException If the statement is empty or not a SELECT query.
     * @throws \PDOException If a database error occurs during execution.
     */
    public function selectQuery(string $statement, ?array $binds = [], $fetchType = 'get');

    /**
     * Executes a raw SQL query without any parameter binding.
     * 
     * This method should be used sparingly and only for queries that cannot 
     * be built using the provided methods. It's generally recommended to 
     *
     * @param string $statement The raw SQL query to execute.
     * @param array $binds An associative array of parameter names (placeholders) and their corresponding values.
     * @return mixed The result of the query execution, depending on the database driver.
     */
    public function query(string $statement, array $binds = []);

    /**
     * Executes the previously prepared raw SQL query.
     *
     * This method should be called after using the `query()` method to set the SQL statement.
     * It handles parameter binding, query profiling, and returns results or status info
     * depending on the query type.
     *
     * @throws \InvalidArgumentException If no query is set or if not used with `query()`.
     * @throws \PDOException On database errors.
     * @return mixed Query result set for SELECT-like queries, or status array for DML/DDL.
     */
    public function execute();

    /**
     * Executes the query and returns the results.
     *
     * @param string|null $table The name of the table.
     * @return mixed The result of the query execution, depending on the database driver.
     */
    public function get(?string $table);

    /**
     * Fetches a single row from the result set.
     *
     * @param string|null $table The name of the table.
     * @return mixed The first row of the result set.
     */
    public function fetch(?string $table);

    /**
     * Counts the number of rows in the result set.
     *
     * @param string|null $table The name of the table.
     * @return int The number of rows in the result set.
     */
    public function count(?string $table);

    /**
     * Checks if any record exists for the current query.
     *
     * @param string|null $table The name of the table.
     * @return bool True if at least one record exists, false otherwise.
     */
    public function exists(?string $table);

    /**
     * Determine if no records exist
     *
     * @param string|null $table Optional table name
     * @return bool
     */
    public function doesntExist(?string $table = null);

    /**
     * Get a single column's value from the first result
     * More efficient than fetch() when you only need one value
     *
     * @param string $column Column name
     * @return mixed
     */
    public function value(string $column);

    /**
     * Find a single record by its primary key.
     *
     * @param mixed $id Primary key value.
     * @param array|string $columns Optional select list.
     * @return mixed
     */
    public function find($id, $columns = ['*']);

    /**
     * Find multiple records by their primary keys.
     *
     * @param array $ids Primary key values.
     * @param array|string $columns Optional select list.
     * @return mixed
     */
    public function findMany(array $ids, $columns = ['*']);

    /**
     * Find a single record by its primary key or throw when it does not exist.
     *
     * @param mixed $id Primary key value.
     * @param array|string $columns Optional select list.
     * @return mixed
     * @throws \Exception
     */
    public function findOrFail($id, $columns = ['*']);

    /**
     * Get the first record or throw an exception
     *
     * @param string|null $table Optional table name
     * @return array
     * @throws \Exception
     */
    public function firstOrFail(?string $table = null);

    /**
     * Get a single record or throw an exception if zero or multiple records found
     * Ensures exactly one record matches
     *
     * @param string|null $table Optional table name
     * @return array
     * @throws \Exception
     */
    public function sole(?string $table = null);

    /**
     * Processes the query in chunks and applies a callback function to each chunk.
     *
     * @param int $size The size of each chunk.
     * @param callable $callback The callback function to apply to each chunk.
     * @return mixed The result of processing the chunks.
     */
    public function chunk(int $size, callable $callback);

    /**
     * Returns a generator that yields results one by one using a database cursor.
     *
        * @param int $chunkSize The size of each chunk.
     * @return \Generator
     */
    public function cursor(int $chunkSize);

    /**
     * Returns a lazy collection for iterating over the results.
     *
        * @param int $chunkSize The size of each chunk.
     * @return \Traversable
     */
    public function lazy(int $chunkSize);

   /**
    * Return a streamed JSON response backed by chunked/cursor iteration.
    *
    * @param int $chunkSize The size of each chunk.
    * @param array $headers Additional response headers.
    * @param int $encodingFlags Additional json_encode flags.
    * @return mixed
    */
   public function streamJsonResponse(int $chunkSize = 1000, array $headers = [], int $encodingFlags = 0);

    /**
     * Paginates the result set.
     *
     * @param int $start The current row record.
     * @param int $limit The number of rows per page.
     * @param int $draw The draw counter for pagination.
     * @return mixed The paginated result set.
     */
    public function paginate(int $start = 1, int $limit = 10, int $draw = 1);

    /**
     * Retrieve a single column's values from the database as an array.
     *
     * @param string $column The column to retrieve values from.
     * @param string|null $keyColumn Optional. The column to use as array keys.
     * @return array The array of plucked values.
     */
    public function pluck(string $column, ?string $keyColumn);

    /**
     * Returns the current SQL query without showing the eager query.
     *
      * @return array{query: string|null, binds: array, full_query: string|null} The current SQL query with bindings.
     */
    public function toSql();

    /**
     * Returns the entire SQL query, including eager loading, without actually executing it.
     *
     * @return array The SQL query as an array.
     */
    public function toDebugSql();

   /**
    * Return a unified debug snapshot containing SQL, profiler payload, and
    * performance report information.
    *
    * @param array $reportOptions Optional performance report options.
    * @return array<string, mixed>
    */
   public function toDebugSnapshot(array $reportOptions = []);

    /**
     * Run an aggregate function on the query.
     *
     * @param string $function Aggregate function (COUNT, SUM, AVG, MIN, MAX).
     * @param string $column Column to aggregate on, default '*'.
     * @return mixed The aggregate result.
     */
    public function aggregate($function, $column = '*');

    /**
     * Get the sum of a column.
     *
     * @param string $column Column to sum.
     * @return mixed The sum result.
     */
    public function sum($column);

    /**
     * Get the average of a column.
     *
     * @param string $column Column to average.
     * @return mixed The average result.
     */
    public function avg($column);

    /**
     * Get the minimum value of a column.
     *
     * @param string $column Column to find minimum.
     * @return mixed The minimum value.
     */
    public function min($column);

    /**
     * Get the maximum value of a column.
     *
     * @param string $column Column to find maximum.
     * @return mixed The maximum value.
     */
    public function max($column);

    /**
     * Get the raw SQL query with bindings substituted.
     *
     * @return string The SQL with values inlined.
     */
    public function toRawSql();

    /**
     * Dump the current SQL query and bindings for debugging.
     *
     * @return $this
     */
    public function dump();

    /**
     * Dump the current SQL query and bindings, then stop execution.
     *
     * @return void
     */
    public function dd();
}
