<?php

namespace Core\Database;

use Core\Database\Query\Grammars\QueryGrammar;
use Core\Database\Schema\Grammars\SchemaGrammar;

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
    protected string $driver;
    protected DriverCapabilities $capabilities;

    public function __construct(string $driver = 'mysql')
    {
        if (empty($driver)) {
            throw new \InvalidArgumentException("No driver found.");
        }

        $dbPlatform = strtolower($driver);
        $this->driver = $dbPlatform;

        $driverClass = DriverRegistry::resolveClass($dbPlatform);
        $this->capabilities = DriverRegistry::capabilities($dbPlatform);
        $this->db = new $driverClass;
    }

    public function capabilities(): DriverCapabilities
    {
        return $this->capabilities;
    }

    public function schemaGrammar(): SchemaGrammar
    {
        return DriverRegistry::schemaGrammar($this->driver);
    }

    public function queryGrammar(): QueryGrammar
    {
        return DriverRegistry::queryGrammar($this->driver);
    }

    public function __call($method, $args)
    {
        if (method_exists($this->db, $method)) {
            return call_user_func_array([$this->db, $method], $args);
        }
        throw new \BadMethodCallException("Method $method does not exist");
    }
}
