<?php

namespace Core\Database;

use Core\Database\Query\Grammars\QueryGrammar;
use Core\Database\Schema\Grammars\SchemaGrammar;
use Core\Database\PerformanceMonitor;

/**
 * Database Class
 *
 * @category  Database
 * @package   Core\Database
 * @author    
 * @license   http://opensource.org/licenses/gpl-3.0.html GNU Public License
 * @link      -
 * @version   0.0.1
 *
 * @method BaseDatabase addConnection(string $name, array $params)
 * @method BaseDatabase setProfilingEnabled(bool $enable = true)
 * @method BaseDatabase connect(string $connectionID = null)
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

        // Auto-enable N+1 detection when APP_DEBUG is on.
        // This is cheap (array key increment) and produces actionable log warnings
        // without requiring the developer to enable full profiling.
        if (function_exists('env') && (bool) env('APP_DEBUG', false)) {
            PerformanceMonitor::setN1DetectionEnabled(true);
        }
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

    public function raw(): BaseDatabase
    {
        return $this->db;
    }

    public function __call($method, $args)
    {
        if (method_exists($this->db, $method)) {
            return call_user_func_array([$this->db, $method], $args);
        }
        throw new \BadMethodCallException("Method $method does not exist");
    }
}
