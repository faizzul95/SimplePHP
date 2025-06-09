<?php

declare(strict_types=1);

namespace Core\Database\Interface;

/**
 * Utils Interface
 *
 * @category Database
 * @package Core\Database
 * @license http://opensource.org/licenses/gpl-3.0.html GNU Public License
 * @version 0.0.1
 */

interface UtilsInterface
{
    /**
     * Begins a database transaction.
     *
     * @return void
     */
    public function beginTrans();

    /**
     * Completes the current transaction.
     *
     * @return void
     */
    public function completeTrans();

    /**
     * Commits the current transaction.
     *
     * @return void
     */
    public function commit();

    /**
     * Rolls back the current transaction.
     *
     * @return void
     */
    public function rollback();

    /**
     * Truncates the table.
     *
     * @return bool True on success, false on failure.
     */
    public function truncate();

    /**
     * Analyzes the table.
     *
     * @return bool True on success, false on failure.
     */
    public function analyze();

    /**
     * Checks if the specified table exists.
     *
     * @param string $table The name of the table.
     * @return bool True if the table exists, false otherwise.
     */
    public function isTableExist($table);

    /**
     * Checks if the specified column exists in the table.
     *
     * @param string $table The name of the table.
     * @param string $column The name of the column.
     * @return bool True if the column exists, false otherwise.
     */
    public function isColumnExist($table, $column);
}
