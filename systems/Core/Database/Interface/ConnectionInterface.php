<?php

declare(strict_types=1);

namespace Core\Database\Interface;

/**
 * Database ConnectionInterface Interface
 *
 * @category Database
 * @package Core\Database
 * @author 
 * @license http://opensource.org/licenses/gpl-3.0.html GNU Public License
 * @link 
 * @version 0.0.1
 */

interface ConnectionInterface
{
    /**
     * Initializes the database connection/settings.
     *
     * @return void
     */
    public function addConnection(string $connectionID, array $config);

    /**
     * Connect to the database.
     *
     * @return         false|object|resource
     * @phpstan-return false|TConnection
     */
    public function connect();

    /**
     * Set the connection name alias
     *
     * @return bool
     */
    public function setConnection(string $alias);

    /**
     * Returns the actual connection object. If both a 'read' and 'write'
     * connection has been specified, you can pass either term in to
     * get that connection. If you pass either alias in and only a single
     * connection is present, it must return the sole connection.
     *
     * @return         false|object|resource
     * @phpstan-return false|TConnection
     */
    public function getConnection(?string $alias = null);

    /**
     * Select a specific database table to use.
     *
     * @return bool
     */
    public function setDatabase(string $databaseName);

    /**
     * Returns the name of the current database being used.
     */
    public function getDatabase();

    /**
     * The name of the platform in use (MySQL, MSSQL, MariaDB, OCI)
     */
    public function getPlatform();

    /**
     * Returns a string containing the version of the database being used.
     */
    public function getVersion();
}
