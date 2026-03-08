<?php

namespace Core\Database\Schema;

/**
 * Seeder — Base class for database seeders.
 *
 * Usage:
 *   return new class extends Seeder
 *   {
 *       public function run(): void
 *       {
 *           db()->table('master_roles')->insert([
 *               'role_name' => 'Administrator',
 *               'role_rank' => 1000,
 *               'role_status' => 1,
 *           ]);
 *       }
 *   };
 *
 * @category  Database
 * @package   Core\Database\Schema
 * @author    Mohd Fahmy Izwan Zulkhafri <faizzul14@gmail.com>
 * @license   http://opensource.org/licenses/gpl-3.0.html GNU Public License
 * @version   1.0.0
 */
abstract class Seeder
{
    /**
     * @var string The table associated with this seeder.
     */
    protected string $table = '';

    /**
     * @var string The database connection to use.
     */
    protected string $connection = 'default';

    /**
     * Run the seeder.
     */
    abstract public function run(): void;

    /**
     * Execute a raw SQL statement.
     */
    protected function execute(string $sql): void
    {
        if (function_exists('db')) {
            db($this->connection)->query($sql)->execute();
        }
    }

    /**
     * Insert data into a table.
     *
     * @param string $table Table name
     * @param array $data Array of column => value pairs, or array of arrays for bulk insert
     */
    protected function insert(string $table, array $data): void
    {
        if (function_exists('db')) {
            // Check if it's a bulk insert (array of arrays)
            if (!empty($data) && is_array(reset($data))) {
                foreach ($data as $row) {
                    db($this->connection)->table($table)->insert($row);
                }
            } else {
                db($this->connection)->table($table)->insert($data);
            }
        }
    }

    /**
     * Insert or update a record based on conditions.
     *
     * @param string $table Table name
     * @param array $conditions Conditions to find existing record
     * @param array $data Data to insert or update
     * @param string $primaryKey Primary key column name
     * @return array|null Result from insertOrUpdate
     */
    protected function insertOrUpdate(string $table, array $conditions, array $data, string $primaryKey = 'id'): ?array
    {
        if (function_exists('db')) {
            return db($this->connection)->table($table)->insertOrUpdate($conditions, $data, $primaryKey);
        }
        return null;
    }

    /**
     * Truncate a table.
     */
    protected function truncate(string $table): void
    {
        if (function_exists('db')) {
            db($this->connection)->query("TRUNCATE TABLE `{$table}`")->execute();
        }
    }
}
