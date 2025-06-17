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
     * @return mixed The result of the query execution, depending on the database driver.
     */
    public function get();

    /**
     * Fetches a single row from the result set.
     *
     * @return mixed The first row of the result set.
     */
    public function fetch();

    /**
     * Counts the number of rows in the result set.
     *
     * @return int The number of rows in the result set.
     */
    public function count();

    /**
     * Processes the query in chunks and applies a callback function to each chunk.
     *
     * @param int $size The size of each chunk.
     * @param callable $callback The callback function to apply to each chunk.
     * @return mixed The result of processing the chunks.
     */
    public function chunk($size, callable $callback);

    /**
     * Returns a generator that yields results one by one using a database cursor.
     *
     * @param int $size The size of each chunk.
     * @return \Generator
     */
    public function cursor($chunkSize);

    /**
     * Returns a lazy collection for iterating over the results.
     *
     * @param int $size The size of each chunk.
     * @return \Traversable
     */
    public function lazy($chunkSize);

    /**
     * Paginates the result set.
     *
     * @param int $start The current row record.
     * @param int $limit The number of rows per page.
     * @param int $draw The draw counter for pagination.
     * @return mixed The paginated result set.
     */
    public function paginate($start = 1, $limit = 10, $draw = 1);

    /**
     * Returns the current SQL query without showing the eager query.
     *
     * @return string The current SQL query as a string.
     */
    public function toSql();

    /**
     * Returns the entire SQL query, including eager loading, without actually executing it.
     *
     * @return array The SQL query as an array.
     */
    public function toDebugSql();
}
