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
     * Inserts a new record or updates an existing record in the database.
     *
     * @param array $data An associative array of column names and their values.
     * @return mixed The result of the insert or update operation, depending on the database driver.
     */
    public function insertOrUpdate(array $data);
}
