<?php

namespace Core\Database;

/**
 * Database Class
 *
 * @category  Database
 * @package   Core\Database
 * @author    
 * @license   http://opensource.org/licenses/gpl-3.0.html GNU Public License
 * @link      -
 * @version   0.0.1
 */

class Database
{
    protected $db;

    /**
     * @var array The list of database support.
     */
    protected $listDatabaseSupport = [
        'mysql' => 'MySQL',
        'mariadb' => 'MariaDB',
        '-' => 'Unknown'
    ];

    public function __construct(string $driver = 'mysql')
    {
        if (empty($driver)) {
            throw new \InvalidArgumentException("No driver found.");
        }

        $dbPlatform = strtolower($driver);

        if (!isset($this->listDatabaseSupport[$dbPlatform])) {
            throw new \InvalidArgumentException("Unsupported database driver: {$dbPlatform}");
        }

        $driverClass = "Core\\Database\\Drivers\\" . $this->listDatabaseSupport[$dbPlatform] . "Driver";
        $this->db = new $driverClass;
    }

    public function __call($method, $args)
    {
        if (method_exists($this->db, $method)) {
            return call_user_func_array([$this->db, $method], $args);
        }
        throw new \BadMethodCallException("Method $method does not exist");
    }
}
