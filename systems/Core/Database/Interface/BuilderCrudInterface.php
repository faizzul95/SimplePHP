<?php

declare(strict_types=1);

namespace Core\Database\Interface;

/**
 * Builder Crud Interface
 *
 * This interface defines methods for performing CRUD operations.
 * It provides methods for inserting, updating, deleting, and batch processing
 * of data, as well as transaction handling.
 *
 * @category Database
 * @package Core\Database
 * @license http://opensource.org/licenses/gpl-3.0.html GNU Public License
 * @version 0.0.1
 */
interface BuilderCrudInterface
{
    /**
     * Inserts a new record into the database.
     *
     * @param array $data An associative array of column names and their values.
     * @return mixed The result of the insert operation, depending on the database driver.
     */
    public function insert(array $data);

    /**
     * Updates an existing record in the database.
     *
     * @param array $data An associative array of column names and their values.
     * @return mixed The result of the update operation, depending on the database driver.
     */
    public function update(array $data);

    /**
     * Deletes a record from the database.
     *
     * @return mixed The result of the delete operation, depending on the database driver.
     */
    public function delete();

    /**
     * Soft deletes or updates a record by setting the specified column(s) to a value.
     *
     * If called with no arguments, defaults to setting the 'deleted_at' column to the current timestamp.
     * You can also pass a column name and value, or an associative array of columns and values to update.
     *
     * Examples:
     *   softDelete(); // sets 'deleted_at' to now
     *   softDelete('status', 0); // sets 'status' to 0
     *   softDelete(['deleted_at' => ..., 'status' => 0]); // sets both columns
     *
     * @param string|array $column The column name or an associative array of columns and values to update.
     * @param mixed $value The value to set for the column (ignored if $column is array).
     * @return mixed The result of the update operation, depending on the database driver.
     */
    public function softDelete(string|array $column = 'deleted_at', $value = null);

    /**
     * Truncates the specified table, removing all rows and resetting any auto-increment counters.
     *
     * @param string|null $table The name of the table to truncate. If null, uses the default table if set.
     * @return mixed The result of the truncate operation, depending on the database driver.
     */
    public function truncate(?string $table);

    /**
     * Inserts multiple records into the database in a single operation.
     *
     * @param array $data A multidimensional array of records, each record being an associative array of column names and their values.
     * @return mixed The result of the batch insert operation, depending on the database driver.
     */
    public function batchInsert(array $data);

    /**
     * Updates multiple records in the database in a single operation.
     *
     * @param array $data A multidimensional array of records, each record being an associative array of column names and their values.
     * @return mixed The result of the batch update operation, depending on the database driver.
     */
    public function batchUpdate(array $data);

    /**
     * Insert new records or update existing ones based on unique constraint.
     *
     * This method attempts to insert one or more records into the database. If a record 
     * with a matching unique key already exists, the specified columns will be updated instead.
     *
     * @param array $values An array of associative arrays representing rows to insert or update.
     * @param string|array $uniqueBy The column(s) that uniquely identify records (e.g., primary or unique keys).
     * @param array|null $updateColumns Columns to update if a matching record exists. If null, all columns except the unique ones will be updated.
     * @return mixed The result of the upsert operation, depending on the database driver.
     */
    public function upsert(array $values, string|array $uniqueBy = 'id', ?array $updateColumns = null);

    /**
     * Insert or update a record based on a primary or unique key condition.
     *
     * This method attempts to update an existing record that matches the given conditions (such as a primary key or unique constraint).
     * If no matching record exists, it will insert a new record with the provided data.
     *
     * @param array $conditions An associative array of column(s) and value(s) to identify the record (e.g., ['email' => 'user@example.com']).
     * @param array $data An associative array of column names and their values to insert or update.
     * @param string $primaryKey The primary key column name to use for matching (default is 'id').
     * @return mixed The result of the insert or update operation, depending on the database driver.
     */
    public function insertOrUpdate(array $conditions, array $data, string $primaryKey = 'id');

    /**
     * Get the first record matching conditions or create a new one
     *
     * @param array $conditions Conditions to search for
     * @param array $data Additional data to set when creating (merged with conditions)
     * @return array The existing or newly created record
     */
    public function firstOrCreate(array $conditions, array $data = []);

    /**
     * Increment a column's value by a given amount
     *
     * @param string $column Column to increment
     * @param int $amount Amount to increment by (default 1)
     * @param array $extra Additional columns to update
     * @return mixed Result of the update operation
     */
    public function increment(string $column, int $amount = 1, array $extra = []);

    /**
     * Decrement a column's value by a given amount
     *
     * @param string $column Column to decrement
     * @param int $amount Amount to decrement by (default 1)
     * @param array $extra Additional columns to update
     * @return mixed Result of the update operation
     */
    public function decrement(string $column, int $amount = 1, array $extra = []);
}
