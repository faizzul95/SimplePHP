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
