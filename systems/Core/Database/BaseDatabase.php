<?php

namespace Core\Database;

/**
 * Database Class
 *
 * @category  Database Access
 * @package   Database
 * @author    Mohd Fahmy Izwan Zulkhafri <faizzul14@gmail.com>
 * @license   http://opensource.org/licenses/gpl-3.0.html GNU Public License
 * @link      -
 * @version   1.0.0
 */

use PDOException;

use Core\Database\Interface\ConnectionInterface;
use Core\Database\Interface\BuilderCrudInterface;
use Core\Database\Interface\BuilderStatementInterface;
use Core\Database\Interface\QueryInterface;
use Core\Database\Interface\ResultInterface;

use Core\Database\Traits\Macroable;
use Core\Database\Traits\Scopeable;
use Core\Database\Traits\ValidationTrait;

use Core\Database\Concerns\HasDebugHelpers;
use Core\Database\Concerns\HasStreaming;
use Core\Database\Concerns\HasWhereConditions;
use Core\Database\Concerns\HasJoins;
use Core\Database\Concerns\HasAggregates;
use Core\Database\Concerns\HasEagerLoading;
use Core\Database\Concerns\HasPaginateCountCache;
use Core\Database\Concerns\HasProfiling;

use Core\Database\StatementCache;
use Core\Database\QueryCache;
use Core\Database\PerformanceMonitor;

use Components\Logger;

/** @phpstan-consistent-constructor */
abstract class BaseDatabase extends DatabaseHelper implements ConnectionInterface, BuilderStatementInterface, QueryInterface, BuilderCrudInterface, ResultInterface
{
    use Macroable, Scopeable, ValidationTrait;
    use HasDebugHelpers, HasStreaming;
    use HasWhereConditions, HasJoins, HasAggregates;
    use HasEagerLoading, HasPaginateCountCache, HasProfiling;

    protected const DEFAULT_PAGINATE_LIMIT = 10;
    protected const MAX_PAGINATE_LIMIT = 500;
    protected const MAX_PAGINATE_FILTER_LENGTH = 255;

    /**
     * Static instance of self
     *
     * @var Database
     */
    protected static $_instance;

    /**
     * @var array<string, \PDO|null> PDO instances keyed by connection name.
     */
    protected array $pdo = [];

    /**
     * @var string $driver The database driver being used (e.g., 'mysql', 'oracle', etc.).
     */
    protected $driver = 'mysql';

    /**
     * @var array The database config
     */
    protected $config = [];

    /**
     * @var string the name of a default (main) pdo connection
     */
    public $connectionName = 'default';

    /**
     * @var string|null The database schema name.
     */
    protected $schema;

    /**
     * @var string|null The table name.
     */
    protected $table;

    /**
     * @var string The column to select.
     */
    protected $column = '*';

    /**
     * @var int|null The limit for the query.
     */
    protected $limit;

    /**
     * @var int|null The offset for the query.
     */
    protected $offset;

    /**
     * @var array|null The order by columns and directions.
     */
    protected $orderBy;

    /**
     * @var array|string|null The group by columns.
     */
    protected $groupBy;

    /**
     * @var array The having columns.
     */
    protected $having;

    /**
     * @var array The having clause bind parameters (kept separate from WHERE binds for correct ordering).
     */
    protected $_havingBinds = [];

    /**
     * @var string|null The conditions for WHERE clause.
     */
    protected $where = null;

    /**
     * @var bool The flag to put the distinct in query.
     */
    protected $distinct = false;

    /**
     * @var string|null The join clauses.
     */
    protected $joins = null;

    /**
     * @var array The relations used for eager loading (N+1).
     */
    protected $relations = [];

    /**
     * @var array The union queries to append.
     */
    protected $unions = [];

    /**
     * @var array The previously executed error query
     */
    protected $_error;

    /**
     * Explicit column allowlist for mass-assignment (insert/update).
     *
     * When non-empty, only the declared columns are permitted to be written
     * through insert() / update() — identical to Eloquent's $fillable.
     * Columns absent from this list but present in the schema are silently
     * dropped before the query is built, preventing privilege-escalation via
     * crafted payloads (e.g. is_admin=1).
     *
     * Leave as an empty array (default) to fall back to the schema-based guard
     * (any column that exists in the table is allowed).  For maximum security,
     * always declare $fillable on subclasses that accept user input.
     *
     * Example (subclass or model-style usage):
     *   protected array $fillable = ['name', 'email', 'bio'];
     *
     * @var string[]
     */
    protected array $fillable = [];

    /**
     * Columns that must NEVER be mass-assigned regardless of $fillable.
     * Takes precedence over $fillable.  Use this to hard-block sensitive
     * columns (e.g. is_admin, role_id) even if a subclass accidentally
     * includes them in $fillable.
     *
     * @var string[]
     */
    protected array $guarded = [];

    /**
     * Set the fillable allowlist at runtime.
     *
     * @param string[] $columns
     * @return $this
     */
    public function setFillable(array $columns): static
    {
        $this->fillable = $columns;
        return $this;
    }

    /**
     * Return the current fillable allowlist.
     *
     * @return string[]
     */
    public function getFillable(): array
    {
        return $this->fillable;
    }

    /**
     * Set the guarded denylist at runtime.
     *
     * @param string[] $columns
     * @return $this
     */
    public function setGuarded(array $columns): static
    {
        $this->guarded = $columns;
        return $this;
    }

    /**
     * Return the current guarded denylist.
     *
     * @return string[]
     */
    public function getGuarded(): array
    {
        return $this->guarded;
    }

    /**
     * @var bool The flag for sanitization for insert/update method.
     */
    protected $_secureInput = false;

    /**
     * @var bool The flag for sanitization for get/fetch/pagination.
     */
    protected $_secureOutput = false;

    /**
     * @var array The list of columns that will be ignored during sanitization.
     */
    protected $_secureOutputExeception = [];

    /**
     * @var array An array to store the bound parameters.
     */
    protected $_binds = [];

    /**
     * @var string The raw SQL query string.
     */
    protected $_query;

    /**
     * @var array An array to store profiling information (optional).
     */
    protected $_profiler = [];

    /**
     * @var bool A flag to indicate if the query is a raw SQL query.
     */
    protected $_isRawQuery = false;

    /**
     * @var array An array to store profiling config to display.
     */
    protected $_profilerShowConf = [
        'php_ver' => true,
        'os_ver' => true,
        'db_driver' => true,
        'db_ver' => true,
        'method' => true,
        'start_time' => true,
        'end_time' => true,
        'query' => true,
        'binds' => true,
        'full_query' => true,
        'execution_time' => true,
        'execution_status' => true,
        'memory_usage' => true,
        'memory_usage_peak' => true,
        'stack_trace' => false
    ];

    /**
     * @var string A string that stores the current active profiler identifier.
     */
    protected $_profilerActive = 'main';

    /**
     * @var array The list of database support.
     */
    protected $listDatabaseDriverSupport = [
        'mysql' => 'MySQL',
        'mariadb' => 'MariaDB',
        '-' => 'Unknown Driver'
    ];

    /**
     * @var string The return type for return result.
     */
    protected $returnType = 'array';

    /**
     * @var array|string The list of columns used for pagination filtering.
     */
    protected $_paginateColumn = [];

    /**
     * @var array|string The list of allowed columns for pagination ordering
     */
    protected $_paginateAllowedSortColumns = [];

    /**
     * @var string|null The current pagination filter value.
     */
    protected $_paginateFilterValue = null;

    /**
     * @var array Index hints for query optimization
     */
    protected $indexHints = [];

    /**
     * @var bool Dry-run mode - build query without executing
     */
    protected $dryRun = false;

    /**
     * @var string|null Pessimistic lock clause (e.g. FOR UPDATE / LOCK IN SHARE MODE)
     */
    protected $_lock = null;

    /**
     * @var bool Transaction state flag
     */
    protected $inTransaction = false;

    /**
     * @var bool Enable/disable query profiling (set via env.php)
     */
    protected $enableProfiling = false;

    /**
     * @var bool Skip QueryCache for streaming operations to avoid cache growth.
     */
    protected $suppressQueryCache = false;

    /**
     * @var string|null Optional cache namespace for paginate count queries.
     */
    protected $paginateCountCacheNamespace = null;

    /**
     * @var int Time to live for paginate count cache entries.
     */
    protected $paginateCountCacheTtl = 0;

    # Implement ConnectionInterface logic

    /**
     * Create & store a new PDO instance
     *
     * @param string $name
     * @param array  $params
     *
     * @return $this
     */
    public function addConnection($name, array $params)
    {
        $this->config[$name] = array();
        foreach (array('driver', 'host', 'username', 'password', 'database', 'port', 'socket', 'charset') as $k) {
            $prm = isset($params[$k]) ? $params[$k] : null;

            if ($k == 'host') {
                if (is_object($prm)) {
                    $this->pdo[$name] = $prm;
                }

                if (!is_string($prm)) {
                    $prm = null;
                }
            }

            $this->config[$name][$k] = $prm;
        }

        return $this;
    }

    /**
     * Establish the underlying PDO connection for the active driver.
     *
     * @return static
     */
    abstract public function connect($connectionID = null);

    /**
     * Switch the active connection name used by subsequent queries.
     *
     * @param string $connectionID
     * @return void
     */
    public function setConnection($connectionID)
    {
        $this->connectionName = $connectionID;
    }

    /**
     * Return the active connection name.
     *
     * @param string|null $connectionID Unused legacy parameter.
     * @return string|null
     */
    public function getConnection($connectionID = null)
    {
        return $this->connectionName;
    }

    /**
     * Override the schema/database used when qualifying table names.
     *
     * @param string|null $databaseName
     * @return void
     */
    public function setDatabase($databaseName = null)
    {
        $this->schema = $databaseName;
    }

    /**
     * Return the currently selected schema/database name.
     *
     * @return string|null
     */
    public function getDatabase()
    {
        return $this->schema ?? null;
    }

    /**
     * Resolve the configured database platform label for the current driver.
     *
     * @return string|null
     */
    public function getPlatform()
    {
        $dbPlatform = strtolower((string) ($this->driver ?: ($this->config[$this->connectionName]['driver'] ?? '-')));
        return $this->listDatabaseDriverSupport[$dbPlatform] ?? $this->listDatabaseDriverSupport['-'];
    }

    /**
     * Return the PDO driver name for the active connection.
     *
     * @return string
     */
    public function getDriver()
    {
        return $this->pdo[$this->connectionName]->getAttribute(\PDO::ATTR_DRIVER_NAME);
    }

    /**
     * Return the database server version for the active connection.
     *
     * @return string
     */
    public function getVersion()
    {
        // Get database version 
        if (isset($this->pdo[$this->connectionName]) && $this->pdo[$this->connectionName] instanceof \PDO) {
            return $this->pdo[$this->connectionName]->getAttribute(\PDO::ATTR_SERVER_VERSION);
        } else {
            return 'Unknown';  // Handle cases where no database connection exists
        }
    }

    /**
     * Return the active PDO instance.
     *
     * @return \PDO
     */
    public function getPdo()
    {
        return $this->pdo[$this->connectionName];
    }

    /**
     * Close an existing connection and optionally drop its config entry.
     *
     * @param string $connection
     * @param bool $remove
     * @return void
     */
    public function disconnect($connection = 'default', $remove = false)
    {
        if (!isset($this->pdo[$connection])) {
            return;
        }

        $this->pdo[$connection] = null;
        unset($this->pdo[$connection]);

        if ($connection == $this->connectionName) {
            $this->connectionName = 'default';
        }

        if ($remove && isset($this->config[$connection])) {
            unset($this->config[$connection]);
        }
    }

    /**
     * Enable or disable query profiling and performance monitoring hooks.
     *
     * @param bool $enable
     * @return $this
     */
    public function setProfilingEnabled($enable = true)
    {
        $this->enableProfiling = (bool) $enable;

        if ($this->enableProfiling) {
            PerformanceMonitor::enable();
            PerformanceMonitor::setN1DetectionEnabled(true);
        } else {
            PerformanceMonitor::disable();
            // Keep N+1 detection active in debug mode even when profiling is off
            $debugMode = function_exists('env') && (bool) env('APP_DEBUG', false);
            PerformanceMonitor::setN1DetectionEnabled($debugMode);
        }

        return $this;
    }

    /**
     * Check if profiling is enabled
     * 
     * @return bool
     */
    public function isProfilingEnabled()
    {
        return $this->enableProfiling;
    }

    /**
     * Save the current query state for later restoration.
     * Used by chunk(), cursor(), and lazy() to preserve state across iterations.
     *
     * @return array Saved state array
     */
    protected function _saveQueryState(): array
    {
        return [
            'driver' => $this->driver,
            'connectionName' => $this->connectionName,
            'table' => $this->table,
            'column' => $this->column,
            'distinct' => $this->distinct,
            'orderBy' => $this->orderBy,
            'groupBy' => $this->groupBy,
            'where' => $this->where,
            'joins' => $this->joins,
            'binds' => $this->_binds,
            'havingBinds' => $this->_havingBinds,
            'having' => $this->having,
            'relations' => $this->relations,
            'secureOutput' => $this->_secureOutput,
            'returnType' => $this->returnType,
            'isRawQuery' => $this->_isRawQuery,
            'limit' => $this->limit,
            'offset' => $this->offset,
            'query' => $this->_query,
            'lock' => $this->_lock,
        ];
    }

    /**
     * Restore query state from a previously saved state array.
     *
     * @param array $state Saved state from _saveQueryState()
     * @return void
     */
    protected function _restoreQueryState(array $state): void
    {
        $this->driver = $state['driver'];
        $this->connectionName = $state['connectionName'];
        $this->table = $state['table'];
        $this->column = $state['column'];
        $this->distinct = $state['distinct'];
        $this->orderBy = $state['orderBy'];
        $this->groupBy = $state['groupBy'];
        $this->where = $state['where'];
        $this->joins = $state['joins'];
        $this->_binds = $state['binds'];
        $this->_havingBinds = $state['havingBinds'];
        $this->having = $state['having'];
        $this->relations = $state['relations'];
        $this->_secureOutput = $state['secureOutput'];
        $this->returnType = $state['returnType'];
        $this->_isRawQuery = $state['isRawQuery'];
        $this->limit = $state['limit'];
        $this->offset = $state['offset'];
        $this->_query = $state['query'] ?? null;
        $this->_lock = $state['lock'] ?? null;
    }

    /**
     * Create a lightweight builder that reuses the current connection context.
     *
     * @return static
     */
    protected function createSubQueryBuilder()
    {
        $builder = clone $this;
        $builder->reset();
        return $builder;
    }

    /**
     * Run the callback inside a transaction and commit or rollback automatically.
     *
     * @param callable $callback
     * @return mixed
     */
    public function transaction(callable $callback)
    {
        $this->beginTransaction();

        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }

    # Implement BuilderStatementInterface logic

    /**
     * Reset per-query builder state while keeping connection-level state intact.
     *
     * @return $this
     */
    public function reset()
    {
        // Note: driver and connectionName are connection-level properties
        // set by connect() and should NOT be reset here.
        $this->table = null;
        $this->column = '*';
        $this->limit = null;
        $this->offset = null;
        $this->orderBy = null;
        $this->groupBy = null;
        $this->where = null;
        $this->distinct = false;
        $this->joins = null;
        $this->_error = [];
        $this->_secureInput = false;
        $this->_secureOutput = false;
        $this->_secureOutputExeception = [];
        $this->_binds = [];
        $this->_query = null;
        $this->relations = [];
        $this->unions = [];
        $this->cacheFile = null;
        $this->cacheFileExpired = 3600;
        $this->_profilerActive = 'main';
        $this->returnType = 'array';
        $this->having = [];
        $this->_havingBinds = [];
        $this->_isRawQuery = false;
        $this->indexHints = [];
        $this->dryRun = false;
        $this->_lock = null;
        $this->_paginateColumn = [];
        $this->_paginateAllowedSortColumns = [];
        $this->_paginateFilterValue = null;
        $this->paginateCountCacheNamespace = null;
        $this->paginateCountCacheTtl = 0;
        $this->pendingPaginateCountCacheRemovals = [];
        return $this;
    }

    /**
     * Start a new builder scope for the given table.
     *
     * @param string $table
     * @return $this
     */
    public function table($table)
    {
        $table = trim($table);
        $this->validateTableName($table, 'Table name');

        // Start a fresh builder scope for each new table selection.
        // Connection-level state is preserved by reset(), but stale WHERE clauses,
        // binds, joins, and cached query strings must not leak across queries.
        $this->reset();
        $this->table = $table;
        return $this;
    }

    /**
     * Mark the query as DISTINCT and optionally replace the select list.
     *
     * @param array|string|null $columns
     * @return $this
     */
    public function distinct($columns = null)
    {
        if (!empty($columns)) {
            $this->select($columns);
        }

        $this->distinct = true;
        return $this;
    }

    /**
     * Define the SELECT column list for the query.
     *
     * @param array|string $columns
     * @return $this
     */
    public function select($columns = ['*'])
    {
        if (!is_array($columns)) {
            $columns = explode(',', $columns);
        }

        $columns = array_map(function ($column) {
            $column = trim($column);
            // Skip prefixing for table.column, aliases, or SQL functions
            if (
                strpos($column, '.') !== false ||
                stripos($column, ' as ') !== false ||
                preg_match('/\w+\s*\(.*\)/i', $column) // handles nested functions like SUM(price * quantity)
            ) {
                return $column;
            }
            return "`{$this->table}`.`{$column}`";
        }, $columns);

        $this->column = implode(', ', $columns);
        return $this;
    }

    /**
     * Set the SELECT clause to a raw SQL expression, with optional positional bindings.
     *
     * Guards against null bytes, stacked queries, and comment injection.
     * SQL keywords such as SELECT, COUNT, and SUM are intentionally permitted
     * because aggregate expressions and correlated subqueries are valid here.
     *
     * @param string $expression
     * @param array  $bindings Optional positional binding values merged into the bind list.
     * @return $this
     */
    public function selectRaw($expression, array $bindings = [])
    {
        if (empty($expression)) {
            throw new \InvalidArgumentException('Expression cannot be empty in selectRaw()');
        }

        // Null-byte injection guard
        if (strpos($expression, "\0") !== false) {
            throw new \InvalidArgumentException('Null bytes are not allowed in selectRaw() expressions');
        }

        // Stacked-query guard: reject semicolons followed by DML/DDL keywords
        if (preg_match('/;\s*(SELECT|INSERT|UPDATE|DELETE|DROP|CREATE|ALTER|TRUNCATE|EXEC|EXECUTE|UNION|GRANT|REVOKE)/i', $expression)) {
            throw new \InvalidArgumentException('Stacked queries are not allowed in selectRaw()');
        }

        // Comment-injection guard
        if (preg_match('/\/\*[\s\S]*?\*\/|--\s|#\s|#$/', $expression)) {
            throw new \InvalidArgumentException('SQL comments are not allowed in selectRaw() expressions');
        }

        $this->column = $expression;

        if (!empty($bindings)) {
            $this->_binds = array_merge($this->_binds, array_values($bindings));
        }

        return $this;
    }



    /**
     * Add a driver-specific date predicate.
     *
     * @param mixed $column
     * @param mixed $operator
     * @param mixed $value
     * @return $this
     */
    abstract public function whereDate($column, $operator, $value);

    /**
     * Add a driver-specific OR date predicate.
     *
     * @param mixed $column
     * @param mixed $operator
     * @param mixed $value
     * @return $this
     */
    abstract public function orWhereDate($column, $operator, $value);

    /**
     * Add a driver-specific day predicate.
     *
     * @param mixed $column
     * @param mixed $operator
     * @param mixed $value
     * @return $this
     */
    abstract public function whereDay($column, $operator, $value);

    /**
     * Add a driver-specific OR day predicate.
     *
     * @param mixed $column
     * @param mixed $operator
     * @param mixed $value
     * @return $this
     */
    abstract public function orWhereDay($column, $operator, $value);

    /**
     * Add a driver-specific month predicate.
     *
     * @param mixed $column
     * @param mixed $operator
     * @param mixed $value
     * @return $this
     */
    abstract public function whereMonth($column, $operator, $value);

    /**
     * Add a driver-specific OR month predicate.
     *
     * @param mixed $column
     * @param mixed $operator
     * @param mixed $value
     * @return $this
     */
    abstract public function orWhereMonth($column, $operator, $value);

    /**
     * Add a driver-specific year predicate.
     *
     * @param mixed $column
     * @param mixed $operator
     * @param mixed $value
     * @return $this
     */
    abstract public function whereYear($column, $operator, $value);

    /**
     * Add a driver-specific time predicate.
     *
     * @param mixed $column
     * @param mixed $operator
     * @param mixed $value
     * @return $this
     */
    abstract public function whereTime($column, $operator, $value);

    /**
     * Add a driver-specific OR time predicate.
     *
     * @param mixed $column
     * @param mixed $operator
     * @param mixed $value
     * @return $this
     */
    abstract public function orWhereTime($column, $operator, $value);

    /**
     * Add a driver-specific JSON contains predicate.
     *
     * @param mixed $columnName
     * @param mixed $jsonPath
     * @param mixed $value
     * @return $this
     */
    abstract public function whereJsonContains($columnName, $jsonPath, $value);

    /**
     * Alias for insertOrUpdate() - matches Laravel's updateOrInsert() naming.
     *
     * @param array $conditions The conditions to check for existence
     * @param array $data Data to update or insert
     * @return mixed
     */
    public function updateOrInsert(array $conditions, array $data)
    {
        return $this->insertOrUpdate($conditions, $data);
    }

    /**
     * Apply a driver-specific LIMIT clause.
     *
     * @param mixed $limit
     * @return $this
     */
    abstract public function limit($limit);

    /**
     * Apply a driver-specific OFFSET clause.
     *
     * @param mixed $offset
     * @return $this
     */
    abstract public function offset($offset);

    /**
     * Alias for offset() - skip records
     *
     * @param int $offset Number of records to skip
     * @return $this
     */
    public function skip($offset)
    {
        return $this->offset($offset);
    }

    /**
     * Alias for limit() - take records
     *
     * @param int $limit Number of records to take
     * @return $this
     */
    public function take($limit)
    {
        return $this->limit($limit);
    }

    /**
     * Simple pagination helper - set offset and limit for a page
     *
     * @param int $page Page number (1-indexed)
     * @param int $perPage Records per page
     * @return $this
     */
    public function forPage($page, $perPage = 15)
    {
        $page = max(1, $page);
        return $this->skip(($page - 1) * $perPage)->take($perPage);
    }

    /**
     * Compile the current builder state into a SELECT SQL string.
     *
     * @return $this
     */
    protected function _buildSelectQuery()
    {
        // Check if table name is empty
        if (empty($this->table)) {
            throw new \InvalidArgumentException('Please specify the table.');
        }

        // Build the basic SELECT clause with fields
        $this->_query = "SELECT " . ($this->distinct ? "DISTINCT " : "") . ($this->column === '*' ? '*' : $this->column) . " FROM ";

        // Append table name with schema (if provided)
        if (empty($this->schema)) {
            $this->_query .= "`{$this->table}`";
        } else {
            $this->_query .= "`{$this->schema}`.`{$this->table}`";
        }

        // Add index hints if specified (MySQL optimization)
        if (!empty($this->indexHints)) {
            foreach ($this->indexHints as $type => $indexes) {
                $indexList = implode(', ', array_map(function($idx) { return "`$idx`"; }, $indexes));
                $this->_query .= " $type INDEX ($indexList)";
            }
        }

        // Add JOIN clauses if available
        if ($this->joins) {
            $this->_query .= $this->joins;
        }

        // Add WHERE clause if conditions exist
        if ($this->where) {
            $this->_query .= " WHERE " . $this->where;
        }

        // Add GROUP BY clause if specified
        if ($this->groupBy) {
            $this->_query .= " GROUP BY " . $this->groupBy;
        }

        // Add HAVING clause if specified
        if ($this->having) {
            $having = implode(' AND ', $this->having);
            $this->_query .= " HAVING " . $having;
        }

        // Add ORDER BY clause if specified
        if ($this->orderBy) {
            $orderBy = implode(', ', $this->orderBy);
            $this->_query .= " ORDER BY " . $orderBy;
        }

        // Add UNION clauses if specified
        if (!empty($this->unions)) {
            foreach ($this->unions as $union) {
                $this->_query .= $union['all'] ? ' UNION ALL ' : ' UNION ';
                $this->_query .= $union['query'];
            }
        }

        // Add LIMIT clause if specified
        if ($this->limit) {
            if (!isset($this->listDatabaseDriverSupport[$this->driver])) {
                throw new \Exception("LIMIT clause not supported for driver: " . $this->driver);
            }

            $this->_query .= $this->limit;
        }

        // Add OFFSET clause if offset is set
        if ($this->offset) {
            $this->_query .= $this->offset;
        }

        // Add pessimistic lock clause (FOR UPDATE / LOCK IN SHARE MODE) if set
        if (!empty($this->_lock)) {
            $this->_query .= ' ' . $this->_lock;
        }

        // Expand asterisks in the query (replace with actual column names)
        $this->_query = $this->_expandAsterisksInQuery($this->_query);

        return $this;
    }

    /**
     * Return the binding list for the current SELECT shape in execution order.
     *
     * @return array
     */
    protected function getSelectQueryBindings(): array
    {
        $bindings = $this->_binds;

        if (!empty($this->_havingBinds)) {
            $bindings = [...$bindings, ...$this->_havingBinds];
        }

        if (!empty($this->unions)) {
            foreach ($this->unions as $union) {
                if (!empty($union['bindings'])) {
                    $bindings = [...$bindings, ...$union['bindings']];
                }
            }
        }

        return $bindings;
    }

    # Implement QueryInterface logic

    /**
     * Execute an ad-hoc SELECT statement without mutating builder state.
     *
     * @param string $statement
     * @param array|null $binds
     * @param string $fetchType
     * @return mixed
     */
    public function selectQuery($statement, $binds = null, $fetchType = 'get')
    {
        if (empty($statement)) {
            throw new \InvalidArgumentException('Query statement cannot be null in `selectQuery()` function.');
        }

        // Check if the statement is a SELECT query
        if (strtoupper(strtok(trim($statement), " \t\n\r")) !== 'SELECT') {
            throw new \InvalidArgumentException('Only SELECT statements are allowed in `selectQuery()` function.');
        }

        try {
            // Prepare the query statement
            $stmt = $this->_prepareStatement($statement);

            // Bind parameters if any
            if (!empty($binds)) {
                $this->_bindParams($stmt, $binds);
            }

            // Execute the prepared statement
            $stmt->execute();

            switch ($fetchType) {
                case 'fetch':
                    // Fetch only the first result as an associative array
                    $result = $stmt->fetch(\PDO::FETCH_ASSOC);
                    break;
                default:
                    // Fetch all results as associative arrays
                    $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                    break;
            }
            
            // Close cursor and free statement memory
            $stmt->closeCursor();
            unset($stmt);
        } catch (\PDOException $e) {
            $this->db_error_log($e, __FUNCTION__);
            throw $e; // Re-throw the exception
        }

        // Reset safeOutput
        $this->safeOutput(false);

        return $this->_returnResult($result);
    }

    /**
     * Register a raw SQL statement for later execution through execute().
     *
     * @param string $statement
     * @param array $bindParams
     * @return $this
     */
    public function query($statement, $bindParams = [])
    {
        // Check if string is empty
        if (empty($statement)) {
            throw new \InvalidArgumentException('Query statement cannot be null in `query()` function.');
        }

        $this->_query = trim($statement);
        $this->_binds = $bindParams;
        $this->_isRawQuery = true;

        return $this;
    }

    /**
     * Execute the raw SQL statement previously registered with query().
     *
     * @return mixed
     */
    public function execute()
    {
        // Validate query
        if (empty($this->_query)) {
            throw new \InvalidArgumentException('Query statement cannot be null in `execute()` function. Please specify query using `query()` function.');
        }

        if (!$this->_isRawQuery) {
            throw new \InvalidArgumentException('The `execute()` function only can use with `query()` function.');
        }

        // Start profiler for performance measurement (only if enabled)
        if ($this->enableProfiling) {
            $this->_startProfiler(__FUNCTION__);
        }

        // Determine query type
        $firstWord = strtoupper(strtok(trim($this->_query), " \t\n\r"));

        $queryTypesList = [
            'SELECT' => 'SELECT',
            'INSERT' => 'INSERT',
            'UPDATE' => 'UPDATE',
            'DELETE' => 'DELETE',
            'TRUNCATE' => 'TRUNCATE',
            'DROP' => 'DROP',
            'ALTER' => 'ALTER',
            'CREATE' => 'CREATE',
            'RENAME' => 'RENAME',
            'COMMENT' => 'COMMENT',
            'GRANT' => 'GRANT',
            'REVOKE' => 'REVOKE',
            'SET' => 'SET',
            'SHOW' => 'SHOW',
            'DESCRIBE' => 'DESCRIBE',
            'DESC' => 'DESCRIBE',
            'EXPLAIN' => 'EXPLAIN'
        ];

        $queryType = $queryTypesList[$firstWord] ?? 'SELECT';

        try {

            // Prepare the query statement
            $stmt = $this->_prepareStatement($this->_query);

            // Bind parameters if any
            if (!empty($this->_binds)) {
                $this->_bindParams($stmt, $this->_binds);
            }

            $this->_captureExecutedQuery($this->_binds);

            // Execute the prepared statement
            $success = $stmt->execute();

            // Handle different query types
            if ($queryType === 'SELECT' || $queryType === 'SHOW' || $queryType === 'DESCRIBE' || $queryType === 'EXPLAIN') {
                // For SELECT and other data-returning queries, return the fetched results
                $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                
                // Close cursor and free statement memory
                $stmt->closeCursor();
                unset($stmt);
            } else {
                // For DDL/DML queries, return status information
                $affectedRows = $stmt->rowCount();

                $messages = [
                    'INSERT' => $success ? 'Data inserted successfully' : 'Failed to insert data',
                    'UPDATE' => $success ? 'Data updated successfully' : 'Failed to update data',
                    'DELETE' => $success ? 'Data deleted successfully' : 'Failed to delete data',
                    'TRUNCATE' => $success ? 'Table truncated successfully' : 'Failed to truncate table',
                    'DROP' => $success ? 'Object dropped successfully' : 'Failed to drop object',
                    'ALTER' => $success ? 'Object altered successfully' : 'Failed to alter object',
                    'CREATE' => $success ? 'Object created successfully' : 'Failed to create object',
                    'RENAME' => $success ? 'Object renamed successfully' : 'Failed to rename object',
                    'COMMENT' => $success ? 'Comment added successfully' : 'Failed to add comment',
                    'GRANT' => $success ? 'Privileges granted successfully' : 'Failed to grant privileges',
                    'REVOKE' => $success ? 'Privileges revoked successfully' : 'Failed to revoke privileges',
                    'SET' => $success ? 'Variable set successfully' : 'Failed to set variable'
                ];

                $statusCodes = [
                    'INSERT' => $success ? 201 : 422,
                    'UPDATE' => $success ? 200 : 422,
                    'DELETE' => $success ? 200 : 422,
                    'TRUNCATE' => $success ? 200 : 422,
                    'DROP' => $success ? 200 : 422,
                    'ALTER' => $success ? 200 : 422,
                    'CREATE' => $success ? 201 : 422,
                    'RENAME' => $success ? 200 : 422,
                    'COMMENT' => $success ? 200 : 422,
                    'GRANT' => $success ? 200 : 422,
                    'REVOKE' => $success ? 200 : 422,
                    'SET' => $success ? 200 : 422
                ];

                $result = [
                    'code' => $statusCodes[$queryType],
                    'affected_rows' => $affectedRows,
                    'message' => $messages[$queryType],
                    'action' => strtolower($queryType)
                ];
            }
        } catch (\PDOException $e) {
            $this->db_error_log($e, __FUNCTION__);
            throw $e; // Re-throw the exception
        }

        // Stop profiler (only if enabled)
        if ($this->enableProfiling) {
            $this->_stopProfiler();
        }

        // Reset safeOutput
        $this->safeOutput(false);

        return $this->_returnResult($result);
    }

    /**
     * Execute the current SELECT builder and return all matching rows.
     *
     * @param string|null $table
     * @return mixed
     */
    public function get($table = null)
    {
        $result = null;

        if (!empty($table)) {
            $this->table = $table;
        }

        if (!$this->_isRawQuery) {
            // Build the final SELECT query string
            $this->_buildSelectQuery();
        }

        // Dry-run mode: return query without executing
        if ($this->dryRun) {
            $query = $this->_query;
            $binds = $this->getSelectQueryBindings();
            $fullQuery = $this->_generateFullQuery($this->_query, $binds, false);
            $this->reset();
            return [
                'dry_run' => true,
                'query' => $query,
                'binds' => $binds,
                'full_query' => $fullQuery
            ];
        }

        $cachePrefix = 'get_';
        if (!empty($this->cacheFile)) {
            $result = $this->_getCacheData($cachePrefix . $this->cacheFile);
        }

        $queryCacheEnabled = $this->shouldUseQueryCache();

        // Check QueryCache if enabled and old cache system not used
        if (empty($result) && $queryCacheEnabled) {
            $cacheKey = QueryCache::generateKey($this->_query, $this->getSelectQueryBindings(), $this->connectionName);
            $result = QueryCache::get($cacheKey);
            
            // If cache hit, reset query builder state since data is already complete with eager loading
            if (!empty($result)) {
                $this->reset();
            }
        }

        if (empty($result)) {
            $result = $this->executeSelectOperation('get', __FUNCTION__);
            $result = $this->finalizeSelectOperation($result, 'get', $cachePrefix, $queryCacheEnabled, $cacheKey ?? null);
        }

        // Reset safeOutput
        $this->safeOutput(false);

        return $this->_returnResult($result);
    }

    /**
     * Execute the current SELECT builder and return the first matching row.
     *
     * @param string|null $table
     * @return mixed
     */
    public function fetch($table = null)
    {
        $result = null;

        if (!empty($table)) {
            $this->table = $table;
        }

        if (!$this->_isRawQuery) {
            // Set limit to 1 to ensure only 1 data return
            $this->limit(1);

            // Build the final SELECT query string
            $this->_buildSelectQuery();
        }

        $cachePrefix = 'fetch_';
        if (!empty($this->cacheFile)) {
            $result = $this->_getCacheData($cachePrefix . $this->cacheFile);
        }

        $queryCacheEnabled = $this->shouldUseQueryCache();

        // Check QueryCache if enabled and old cache system not used
        if (empty($result) && $queryCacheEnabled) {
            $cacheKey = QueryCache::generateKey($this->_query, $this->getSelectQueryBindings(), $this->connectionName);
            $result = QueryCache::get($cacheKey);
            
            // If cache hit, reset query builder state since data is already complete with eager loading
            if (!empty($result)) {
                $this->reset();
            }
        }

        if (empty($result)) {
            $result = $this->executeSelectOperation('fetch', __FUNCTION__);
            $result = $this->finalizeSelectOperation($result, 'fetch', $cachePrefix, $queryCacheEnabled, $cacheKey ?? null);
        }

        // Reset secureOutput
        $this->safeOutput(false);

        // Return the first result or null if not found
        return $this->_returnResult($result);
    }

    /**
     * Determine whether QueryCache should participate in the current read.
     *
     * @return bool
     */
    protected function shouldUseQueryCache(): bool
    {
        return empty($this->cacheFile) && !$this->suppressQueryCache && QueryCache::isEnabled();
    }

    /**
     * Prepare, bind, and execute a SELECT statement for get() or fetch().
     *
     * @param string $fetchType
     * @param string $methodName
     * @return mixed
     */
    protected function executeSelectOperation(string $fetchType, string $methodName)
    {
        if ($this->enableProfiling) {
            $this->_startProfiler($methodName);
        }

        $stmt = $this->_prepareStatement($this->_query);

        $bindings = $this->getSelectQueryBindings();

        if (!empty($bindings)) {
            $this->_bindParams($stmt, $bindings);
        }

        try {
            $this->_captureExecutedQuery($bindings);
            $stmt->execute();

            $result = $fetchType === 'fetch'
                ? $stmt->fetch(\PDO::FETCH_ASSOC)
                : $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $stmt->closeCursor();
            unset($stmt);
        } catch (\PDOException $e) {
            $this->db_error_log($e, $methodName);
            throw $e;
        }

        if ($this->enableProfiling) {
            $this->_stopProfiler();
        }

        return $result;
    }

    /**
     * Finalize a SELECT result by applying eager loading and any cache writes.
     *
     * @param mixed $result
     * @param string $fetchType
     * @param string $cachePrefix
     * @param bool $queryCacheEnabled
     * @param string|null $cacheKey
     * @return mixed
     */
    protected function finalizeSelectOperation($result, string $fetchType, string $cachePrefix, bool $queryCacheEnabled, ?string $cacheKey = null)
    {
        $result = $this->_safeOutputSanitize($result);

        $shouldProcessRelations = !empty($result) && !empty($this->relations);
        $shouldWriteQueryCache = $queryCacheEnabled && !empty($result);
        $shouldWriteFileCache = !empty($this->cacheFile) && !empty($result);

        if (!$shouldProcessRelations && !$shouldWriteQueryCache && !$shouldWriteFileCache) {
            $this->reset();
            return $result;
        }

        $_temp_connection = $this->connectionName;
        $_temp_relations = $this->relations;
        $_temp_cacheKey = $this->cacheFile;
        $_temp_cacheExpired = $this->cacheFileExpired;
        $_temp_queryCacheKey = null;
        if ($queryCacheEnabled) {
            $_temp_queryCacheKey = $cacheKey ?? QueryCache::generateKey($this->_query, $this->getSelectQueryBindings(), $this->connectionName);
        }

        $this->reset();

        if (!empty($result) && !empty($_temp_relations)) {
            $result = $this->_processEagerLoading($result, $_temp_relations, $_temp_connection, $fetchType);
        }

        if ($queryCacheEnabled && !empty($result) && $_temp_queryCacheKey) {
            QueryCache::set($_temp_queryCacheKey, $result);
        }

        if (!empty($_temp_cacheKey) && !empty($result)) {
            $this->_setCacheData($cachePrefix . $_temp_cacheKey, $result, $_temp_cacheExpired);
        }

        unset($_temp_connection, $_temp_relations, $_temp_cacheKey, $_temp_cacheExpired, $_temp_queryCacheKey);

        return $result;
    }

    /**
     * Count rows for the current builder state.
     *
     * @param string|null $table
     * @return int
     */
    abstract public function count($table = null);

    /**
     * Determine whether at least one row matches the current builder state.
     *
     * @param string|null $table
     * @return bool
     */
    abstract public function exists($table = null);

    /**
     * Determine if no records exist
     *
     * @param string|null $table Optional table name
     * @return bool
     */
    public function doesntExist($table = null)
    {
        return !$this->exists($table);
    }

    /**
     * Get a single column's value from the first result
     * More efficient than fetch() when you only need one value
     *
     * @param string $column Column name
     * @return mixed
     */
    public function value($column)
    {
        $result = $this->select($column)->fetch();

        if (!is_array($result) || $result === []) {
            return null;
        }

        if (array_key_exists($column, $result)) {
            return $result[$column];
        }

        $unqualifiedColumn = str_contains((string) $column, '.')
            ? (string) substr((string) $column, strrpos((string) $column, '.') + 1)
            : (string) $column;

        if (array_key_exists($unqualifiedColumn, $result)) {
            return $result[$unqualifiedColumn];
        }

        $firstKey = array_key_first($result);
        return $firstKey !== null ? $result[$firstKey] : null;
    }

    /**
     * Find a single record by its primary key.
     *
     * @param mixed $id
     * @param array|string $columns
     * @return mixed
     */
    public function find($id, $columns = ['*'])
    {
        if (is_array($id)) {
            return $this->findMany($id, $columns);
        }

        if ($columns !== ['*'] && $columns !== '*') {
            $this->select($columns);
        }

        return $this->where('id', $id)->fetch();
    }

    /**
     * Find multiple records by their primary keys.
     *
     * @param array $ids
     * @param array|string $columns
     * @return mixed
     */
    public function findMany(array $ids, $columns = ['*'])
    {
        $ids = array_values(array_unique($ids, SORT_REGULAR));
        if ($ids === []) {
            return [];
        }

        if ($columns !== ['*'] && $columns !== '*') {
            $this->select($columns);
        }

        return $this->whereIn('id', $ids)->get();
    }

    /**
     * Find a single record by its primary key or throw an exception.
     *
     * @param mixed $id
     * @param array|string $columns
     * @return mixed
     * @throws \Exception
     */
    public function findOrFail($id, $columns = ['*'])
    {
        $result = $this->find($id, $columns);

        if (empty($result)) {
            throw new \Exception('No records found matching the query');
        }

        return $result;
    }

    /**
     * Get the first record or throw an exception
     *
     * @param string|null $table Optional table name
     * @return array
     * @throws \Exception
     */
    public function firstOrFail($table = null)
    {
        $result = $this->fetch($table);
        
        if (empty($result)) {
            throw new \Exception('No records found matching the query');
        }
        
        return $result;
    }

    /**
     * Get a single record or throw an exception if zero or multiple records found
     * Ensures exactly one record matches
     *
     * @param string|null $table Optional table name
     * @return array
     * @throws \Exception
     */
    public function sole($table = null)
    {
        $results = $this->limit(2)->get($table);
        $count = count($results);
        
        if ($count === 0) {
            throw new \Exception('No records found matching the query');
        }
        
        if ($count > 1) {
            throw new \Exception('Multiple records found, expected only one');
        }
        
        return $results[0];
    }

    /**
     * Determine whether OFFSET-based iteration can be replaced by keyset pagination.
     * Keep this conservative to avoid changing semantics for complex query shapes.
     */
    protected function canAutoUseChunkById(array $state, string $column = 'id'): bool
    {
        if (($state['isRawQuery'] ?? false) === true) {
            return false;
        }

        if (empty($state['table']) || !empty($state['offset']) || !empty($state['groupBy']) || !empty($state['joins']) || !empty($state['having'])) {
            return false;
        }

        if (!empty($this->unions)) {
            return false;
        }

        if (!$this->hasCompatibleKeysetOrder($state['orderBy'] ?? null, $column, (string) ($state['table'] ?? ''))) {
            return false;
        }

        $selectedColumns = trim((string) ($state['column'] ?? '*'));
        if (!$this->selectedColumnsSupportKeyset($selectedColumns, $column, (string) ($state['table'] ?? ''))) {
            return false;
        }

        return $this->hasColumn($column);
    }

    /**
     * Determine whether the selected column list still exposes the keyset column.
     *
     * @param string $selectedColumns
     * @param string $column
     * @param string $table
     * @return bool
     */
    protected function selectedColumnsSupportKeyset(string $selectedColumns, string $column, string $table = ''): bool
    {
        $selectedColumns = trim($selectedColumns);
        if ($selectedColumns === '*' || stripos($selectedColumns, '.*') !== false) {
            return true;
        }

        $normalized = strtolower(str_replace('`', '', $selectedColumns));
        $tokens = array_map('trim', explode(',', $normalized));
        $candidates = [strtolower($column)];
        if ($table !== '') {
            $candidates[] = strtolower($table . '.' . $column);
        }

        foreach ($tokens as $token) {
            if (in_array($token, $candidates, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine whether an explicit ORDER BY remains compatible with ascending keyset scans.
     *
     * @param mixed $orderBy
     * @param string $column
     * @param string $table
     * @return bool
     */
    protected function hasCompatibleKeysetOrder($orderBy, string $column, string $table = ''): bool
    {
        if (empty($orderBy)) {
            return true;
        }

        if (!is_array($orderBy) || count($orderBy) !== 1) {
            return false;
        }

        $normalized = strtolower(trim(preg_replace('/\s+/', ' ', str_replace('`', '', (string) $orderBy[0]))));
        $candidates = [strtolower($column . ' asc')];
        if ($table !== '') {
            $candidates[] = strtolower($table . '.' . $column . ' asc');
        }

        return in_array($normalized, $candidates, true);
    }

    /**
     * Apply a SELECT ... FOR UPDATE lock to the next query.
     * Must be run inside a transaction to be effective.
     *
     * @return $this
     */
    public function lockForUpdate()
    {
        $this->_lock = 'FOR UPDATE';
        return $this;
    }

    /**
     * Apply a shared lock (LOCK IN SHARE MODE / FOR SHARE) to the next query.
     * Must be run inside a transaction to be effective.
     *
     * @return $this
     */
    public function sharedLock()
    {
        $driver = strtolower((string)$this->driver);
        // Postgres uses FOR SHARE; MySQL/MariaDB use LOCK IN SHARE MODE
        if (in_array($driver, ['pgsql', 'postgres', 'postgresql'], true)) {
            $this->_lock = 'FOR SHARE';
        } else {
            $this->_lock = 'LOCK IN SHARE MODE';
        }
        return $this;
    }

    /**
     * Insert a row and return the lastInsertId directly.
     *
     * @param array $data
     * @param string|null $sequence Sequence name (for drivers like Postgres)
     * @return string|int|false The last insert id, or false on failure
     */
    public function insertGetId(array $data, ?string $sequence = null)
    {
        $result = $this->insert($data);

        if (is_array($result) && !empty($result['id'])) {
            return $result['id'];
        }

        // Fallback: query the driver directly if response format differs
        try {
            return $this->pdo[$this->connectionName]->lastInsertId($sequence);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * INSERT INTO ... SELECT ... from a sub-query builder.
     *
     * Example:
     *   $db->table('archive_users')->insertUsing(
     *       ['id', 'name', 'email'],
     *       function ($q) { $q->table('users')->select(['id','name','email'])->where('active', 0); }
     *   );
     *
     * @param array $columns Destination columns
     * @param \Closure|callable $query Closure receiving a sub-builder to define the SELECT
     * @return mixed Result of the execute() call
     */
    public function insertUsing(array $columns, $query)
    {
        if (empty($this->table)) {
            throw new \InvalidArgumentException('Please specify the destination table before calling insertUsing().');
        }
        if (empty($columns)) {
            throw new \InvalidArgumentException('insertUsing(): destination columns cannot be empty.');
        }
        if (!is_callable($query)) {
            throw new \InvalidArgumentException('insertUsing(): second argument must be a Closure.');
        }

        $sub = $this->createSubQueryBuilder();
        $query($sub);

        // Build the sub-SELECT without executing
        $sub->_buildSelectQuery();
        $selectSql = $sub->_query;
        $selectBinds = $sub->_binds;

        if (empty($selectSql)) {
            throw new \RuntimeException('insertUsing(): sub-query produced no SQL.');
        }

        // Escape destination columns
        $escapedColumns = array_map(function ($c) {
            $c = trim($c);
            if (strpos($c, '`') !== false) return $c;
            return '`' . str_replace('`', '``', $c) . '`';
        }, $columns);
        $columnList = implode(', ', $escapedColumns);

        $table = empty($this->schema)
            ? "`{$this->table}`"
            : "`{$this->schema}`.`{$this->table}`";

        $sql = "INSERT INTO {$table} ({$columnList}) {$selectSql}";

        unset($sub);

        // Execute via query() so profiling/binds are handled consistently
        return $this->query($sql, $selectBinds)->execute();
    }

    /**
     * Execute a DataTables-style paginated query with exact total counts.
     *
     * @param int $start
     * @param int $limit
     * @param int $draw
     * @return mixed
     */
    public function paginate($start = 0, $limit = 10, $draw = 1)
    {
        $totalRecords = 0;
        $totalFiltered = 0;

        if ($start < 0) {
            $start = 0;
        }

        // Reset the offset & limit to ensure the $this->_query not generate with that when call _buildSelectQuery() function
        $this->offset = $this->limit = null;

        try {

            // Count total rows before filter
            $this->_setProfilerIdentifier('count_all'); // set new profiler
            if (!$this->_isRawQuery) {
                // Lightweight clone: reuse PDO/config, copy only the builder state needed for count()
                $counter = $this->createSubQueryBuilder();
                $counter->schema = $this->schema;
                $counter->table = $this->table;
                $counter->column = $this->column;
                $counter->distinct = $this->distinct;
                $counter->joins = $this->joins;
                $counter->where = $this->where;
                $counter->groupBy = $this->groupBy;
                $counter->having = $this->having;
                $counter->_binds = $this->_binds;
                $counter->_havingBinds = $this->_havingBinds;
                $counter->unions = $this->unions;
                $counter->indexHints = $this->indexHints;
                $counter->_buildSelectQuery();
                $totalRecords = $totalFiltered = $this->resolvePaginateCountCache(
                    'total',
                    $counter->_query,
                    $counter->getSelectQueryBindings(),
                    static fn() => $counter->count()
                );
                unset($counter);
            } else {
                // For raw queries, wrap in a subquery to count
                $countQuery = "SELECT COUNT(*) as count FROM ({$this->_query}) AS count_wrapper";
                $stmt = $this->_prepareStatement($countQuery);
                $bindings = $this->getSelectQueryBindings();
                
                if (!empty($bindings)) {
                    $this->_bindParams($stmt, $bindings);
                }
                
                $stmt->execute();
                $result = $stmt->fetch(\PDO::FETCH_ASSOC);
                if (method_exists($stmt, 'closeCursor')) {
                    $stmt->closeCursor();
                }
                unset($stmt);
                $totalRecords = $totalFiltered = (int)($result['count'] ?? 0);
            }
            $this->_setProfilerIdentifier(); // reset back to paginate profiler

            // Apply custom filter (advanced search)
            // Skip filtering for raw queries when columns cannot be determined
            if (!empty($this->_paginateFilterValue) && !$this->_isRawQuery) {
                $columns = $this->_paginateColumn;
                if (empty($columns)) {
                    // Query to get all columns from the table based on database type
                    $columns = $this->getTableColumns();
                }

                $searchValue = $this->_paginateFilterValue;

                // Build search conditions with OR logic (LIKE)
                $searchConditions = [];
                foreach ($columns as $column) {
                    $searchConditions[] = trim($column);
                }

                if (!empty($searchConditions)) {
                    $this->where(function ($query) use ($searchConditions, $searchValue) {
                        foreach ($searchConditions as $index => $column) {
                            if ($index === 0) {
                                $query->where($column, 'LIKE', '%' . $searchValue . '%');
                            } else {
                                $query->orWhere($column, 'LIKE', '%' . $searchValue . '%');
                            }
                        }
                    });
                }
            }

            if (!$this->_isRawQuery) {
                // Build the final SELECT query string
                $this->_buildSelectQuery();

                // Count total rows after filter
                if (!empty($this->_paginateFilterValue)) {
                    $this->_setProfilerIdentifier('count_filtered'); // set new profiler
                    $totalFiltered = $this->resolvePaginateCountCache(
                        'filtered',
                        $this->_query,
                        $this->getSelectQueryBindings(),
                        fn() => $this->count()
                    );
                    $this->_setProfilerIdentifier(); // reset back to paginate profiler
                }
            }

            // Add LIMIT and OFFSET clauses to the main query
            $this->_query = $this->_getLimitOffsetPaginate($this->_query, $limit, $start);

            // Start profiler for main datatable query
            $this->_startProfiler(__FUNCTION__);

            // Execute the main query
            $stmt = $this->_prepareStatement($this->_query);

            $bindings = $this->getSelectQueryBindings();

            // Bind parameters if any
            if (!empty($bindings)) {
                $this->_bindParams($stmt, $bindings);
            }

            $this->_captureExecutedQuery($bindings);

            // Execute the prepared statement
            $stmt->execute();

            // Fetch the result in associative array
            $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            if (method_exists($stmt, 'closeCursor')) {
                $stmt->closeCursor();
            }
            unset($stmt);
            
            // Stop profiler for main datatable query
            $this->_stopProfiler();

            $paginate = [
                'draw' => $draw,
                'recordsTotal' => $totalRecords,
                'recordsFiltered' => $totalFiltered,
                'data' => $this->_safeOutputSanitize($result) ?? null,
            ];
        } catch (\PDOException $e) {
            // Log database errors
            $this->db_error_log($e, __FUNCTION__);
            throw $e; // Re-throw the exception
        }

        // Save connection name and relations temporarily
        $_temp_connection = $this->connectionName;
        $_temp_relations = $this->relations;

        // Assign temporary return type before reset
        $_temp_returnType = $this->returnType;

        // Reset internal properties for next query
        $this->reset();

        // Process eager loading if implemented 
        if (!empty($paginate['data']) && !empty($_temp_relations)) {
            $paginate['data'] = $this->_processEagerLoading($paginate['data'], $_temp_relations, $_temp_connection, 'get');
        }

        // Reset safeOutput
        $this->safeOutput(false);

        // Assign return type to original state
        $this->returnType = $_temp_returnType;

        unset($_temp_connection, $_temp_relations, $_temp_returnType);

        return $this->_returnResult($paginate);
    }

    /**
     * Define which columns are searched by paginate_ajax().
     *
     * @param array $column
     * @return $this
     */
    public function setPaginateFilterColumn($column = [])
    {
        $this->_paginateColumn = is_array($column) ? $column : [];
        return $this;
    }

    /**
     * Restrict client-provided sort indexes to a safe list of columns.
     *
     * @param array $columns
     * @return $this
     */
    public function setAllowedSortColumns($columns = [])
    {
        $this->_paginateAllowedSortColumns = is_array($columns) ? array_values($columns) : [];
        return $this;
    }

    /**
     * Translate a DataTables request payload into paginate() arguments.
     *
     * @param array $dataPost
     * @return mixed
     */
    public function paginate_ajax($dataPost)
    {
        $dataPost = is_array($dataPost) ? $dataPost : [];

        $draw = max(1, (int) ($dataPost['draw'] ?? 1));
        $start = max(0, (int) ($dataPost['start'] ?? 0));

        $configuredMaxLimit = function_exists('config')
            ? (int) config('database.pagination.max_limit', self::MAX_PAGINATE_LIMIT)
            : self::MAX_PAGINATE_LIMIT;
        $configuredDefaultLimit = function_exists('config')
            ? (int) config('database.pagination.default_limit', self::DEFAULT_PAGINATE_LIMIT)
            : self::DEFAULT_PAGINATE_LIMIT;

        $maxLimit = max(1, $configuredMaxLimit);
        $defaultLimit = min(max(1, $configuredDefaultLimit), $maxLimit);

        $requestedLimit = (int) ($dataPost['length'] ?? $defaultLimit);
        if ($requestedLimit === -1) {
            $limit = $maxLimit;
        } elseif ($requestedLimit < 1) {
            $limit = $defaultLimit;
        } else {
            $limit = min($requestedLimit, $maxLimit);
        }

        $searchValue = trim((string) ($dataPost['search']['value'] ?? ''));
        if (strlen($searchValue) > self::MAX_PAGINATE_FILTER_LENGTH) {
            $searchValue = substr($searchValue, 0, self::MAX_PAGINATE_FILTER_LENGTH);
        }

        $this->_paginateFilterValue = $searchValue;
        $orderBy = is_array($dataPost['order'][0] ?? null) ? $dataPost['order'][0] : false;

        if (empty($this->_paginateColumn)) {
            // Query to get all columns from the table based on database type
            $this->_paginateColumn = $this->getTableColumns();
        }

        $sortColumns = !empty($this->_paginateAllowedSortColumns)
            ? $this->_paginateAllowedSortColumns
            : $this->_paginateColumn;

        // Only apply ordering if columns are available (skip for raw queries without column info)
        if ($orderBy && !empty($sortColumns)) {
            $columnIndex = max(0, (int) ($orderBy['column'] ?? 0));
            $direction = strtoupper((string) ($orderBy['dir'] ?? 'ASC'));
            if (!in_array($direction, ['ASC', 'DESC'], true)) {
                $direction = 'ASC';
            }

            $column = $sortColumns[$columnIndex] ?? $sortColumns[0];
            $this->orderBy($column, $direction);
        }

        return $this->paginate($start, $limit, $draw);
    }

    /**
     * Extract a single column's values from the query results.
     *
     * Supports dot notation for nested relationship payloads returned by eager
     * loading. Large result sets are processed in chunks to keep memory usage
     * bounded.
     *
     * @param string $column Column path to extract.
     * @param string|null $keyColumn Optional key path for the returned array.
     * @return array
     */
    public function pluck($column, $keyColumn = null)
    {
        $result = [];
        $hasDotNotation = strpos($column, '.') !== false || ($keyColumn && strpos($keyColumn, '.') !== false);

        if ($hasDotNotation) {
            $this->chunk(1000, function ($rows) use (&$result, $column, $keyColumn) {
                foreach ($rows as $row) {
                    $value = $this->_resolvePluckValue($row, $column);

                    if ($keyColumn !== null) {
                        $key = $this->_resolvePluckValue($row, $keyColumn);
                        
                        if ($key !== null) {
                            $result[$key] = $value;
                        }
                    } else {
                        $result[] = $value;
                    }
                }
            });
        } else {
            // Original optimized implementation for simple columns
            $this->select($keyColumn ? [$keyColumn, $column] : [$column])
                ->chunk(1000, function ($rows) use (&$result, $column, $keyColumn) {
                    foreach ($rows as $row) {
                        if ($keyColumn !== null && isset($row[$keyColumn])) {
                            $result[$row[$keyColumn]] = $row[$column] ?? null;
                        } else {
                            $result[] = $row[$column] ?? null;
                        }
                    }
                });
        }

        return $result;
    }

    /**
     * Resolve a scalar or nested value from a row for pluck()-style extraction.
     *
     * When traversal encounters a numerically indexed relation array before the
     * final segment, the first item is used so `relation.name` can resolve from
     * eager-loaded `with()` results.
     *
     * @param mixed $source Row array/object or nested payload.
     * @param string $path Column name or dot-notated path.
     * @return mixed
     */
    protected function _resolvePluckValue($source, string $path)
    {
        if (strpos($path, '.') === false) {
            if (is_array($source)) {
                return array_key_exists($path, $source) ? $source[$path] : null;
            }

            if (is_object($source)) {
                return $source->$path ?? null;
            }

            return null;
        }

        $value = $source;
        $segments = explode('.', $path);
        $lastIndex = count($segments) - 1;

        foreach ($segments as $segmentIndex => $segment) {
            if (is_array($value)) {
                if (!array_key_exists($segment, $value)) {
                    return null;
                }

                $value = $value[$segment];

                if (is_array($value) && !empty($value) && $segmentIndex < $lastIndex) {
                    $firstKey = array_key_first($value);
                    if (is_numeric($firstKey)) {
                        $value = $value[$firstKey] ?? null;
                    }
                }

                continue;
            }

            if (is_object($value)) {
                $value = $value->$segment ?? null;
                continue;
            }

            return null;
        }

        return $value;
    }

    /**
     * Driver-specific LIMIT/OFFSET SQL formatter for paginated statements.
     */
    abstract public function _getLimitOffsetPaginate($query, $limit, $offset);
    // Implement BuilderCrudInterface logic

    # CREATE NEW DATA OPERATION

    /**
     * Insert a single record into the current table.
     *
     * @param array $data
     * @return mixed
     */
    public function insert($data)
    {
        // Default response
        $response = ['code' => 400, 'message' => 'Failed to create data', 'action' => 'create'];

        if ($this->_isRawQuery) {
            throw new \InvalidArgumentException('Raw insert SQL statements are not allowed in insert(). Please use insert() function without any query or condition.');
        }

        // Check if string is empty
        if (empty($data) || !is_array($data)) {
            throw new \InvalidArgumentException('Invalid column data. Must be an associative array.');
        }

        if (empty($this->table)) {
            throw new \InvalidArgumentException('Please specify the table.');
        }

        // Start profiler for performance measurement 
        $this->_startProfiler(__FUNCTION__);

        // sanitize column to ensure column is exists.
        $sanitizeData = $this->sanitizeColumn($data);

        // Build the final INSERT query string
        $this->_buildInsertQuery($sanitizeData);

        // Prepare the query statement
        $stmt = $this->_prepareStatement($this->_query);

        // Bind parameters 
        $this->_bindParams($stmt, array_values($sanitizeData));

        try {
            $this->_captureExecutedQuery($this->_binds);

            // Execute the statement
            $success = $stmt->execute();

            // Get the number of affected rows
            $affectedRows = $stmt->rowCount();

            // Get the last inserted ID
            $lastInsertId = $success ? $this->pdo[$this->connectionName]->lastInsertId() : null;

            // Return information about the insertion operation
            $response = [
                'code' => $success ? 201 : 422,
                'id' => $lastInsertId,
                'message' => $success ? 'Data inserted' : 'Failed to insert data',
                'data' => $this->_safeOutputSanitize($sanitizeData),
                'action' => 'create'
            ];

            if ($success) {
                $this->flushPendingPaginateCountCacheRemovals();
            }
        } catch (\PDOException $e) {
            // Log database errors
            $this->db_error_log($e, __FUNCTION__);
            throw $e; // Re-throw the exception
        }

        // Stop profiler 
        $this->_stopProfiler();

        // Reset internal properties for next query
        $this->reset();

        return $this->_returnResult($response) ?? false;
    }

    /**
     * Compile an INSERT statement for the provided associative row payload.
     *
     * @param array $data
     * @return $this
     */
    protected function _buildInsertQuery($data)
    {
        // Check if data is empty or not an associative array (key-value pairs)
        if (empty($data) || !is_array($data)) {
            throw new \InvalidArgumentException('Invalid column data. Must be an associative array with column names as keys.');
        }

        // Construct column names string
        $columns = implode(', ', array_map(
            static fn ($column): string => '`' . str_replace('`', '``', (string) $column) . '`',
            array_keys($data)
        ));

        // Construct placeholders for values
        $placeholders = implode(', ', array_fill(0, count($data), '?'));

        // Construct the SQL insert statement
        $this->_query = "INSERT INTO ";

        // Append table name with schema (if provided)
        if (empty($this->schema)) {
            $this->_query .= "`$this->table` ($columns)";
        } else {
            $this->_query .= "`$this->schema`.`$this->table` ($columns)";
        }

        $this->_query .= " VALUES ($placeholders)";

        return $this;
    }

    /**
     * Update an existing row or insert a new one inside a transaction.
     *
     * @param array $conditions
     * @param array $data
     * @param string $primaryKey
     * @return mixed
     */
    public function insertOrUpdate($conditions, $data, $primaryKey = 'id')
    {
        // Default response
        $response = ['code' => 400, 'message' => 'Failed to insert or update data', 'action' => 'insertOrUpdate'];

        try {
            if (empty($this->table)) {
                throw new \InvalidArgumentException('Please specify the table.');
            }
            if (empty($conditions) || !is_array($conditions)) {
                throw new \InvalidArgumentException('Conditions must be a non-empty associative array.');
            }
            if (empty($data) || !is_array($data)) {
                throw new \InvalidArgumentException('Data must be a non-empty associative array.');
            }

            if (array_key_exists($primaryKey, $conditions) && empty($conditions[$primaryKey])) {
                unset($conditions[$primaryKey]); // removed from the conditions if exists
            }

            $records = array_merge($conditions, $data);

            // Wrap in transaction to prevent race conditions between check and insert/update
            $this->beginTransaction();

            try {
                if (isset($records[$primaryKey]) && !empty($records[$primaryKey])) {
                    $query = $this->createSubQueryBuilder();
                    $query->table = $this->table;
                    $existingByPk = $query->where($primaryKey, $records[$primaryKey])->fetch();
                    unset($query);

                    if ($existingByPk) {
                        $updateRecs = array_merge($data, ['updated_at' => date('Y-m-d H:i:s')]);
                        $result = $this->where($primaryKey, $records[$primaryKey])->update($updateRecs);
                        $this->commit();
                        return $result;
                    }

                    $insertRecs = array_merge($records, ['created_at' => date('Y-m-d H:i:s')]);
                    $result = $this->insert($insertRecs);
                    $this->commit();
                    return $result;
                }

                // If no condition to check, then insert as a new records
                if (empty($conditions)) {
                    $insertRecs = array_merge($records, ['created_at' => date('Y-m-d H:i:s')]);
                    $result = $this->insert($insertRecs);
                    $this->commit();
                    return $result;
                }

                // Check if record exists
                $query = $this->createSubQueryBuilder();
                $query->table = $this->table;
                $existing = $query->where($conditions)->fetch();
                unset($query);

                if ($existing) {
                    $updateRecs = array_merge($data, ['updated_at' => date('Y-m-d H:i:s')]);
                    $result = $this->where($conditions)->update($updateRecs);
                    $this->commit();
                    return $result;
                } else {
                    $insertRecs = array_merge($records, ['created_at' => date('Y-m-d H:i:s')]);
                    $result = $this->insert($insertRecs);
                    $this->commit();
                    return $result;
                }
            } catch (\Throwable $txException) {
                $this->rollback();
                throw $txException;
            }
        } catch (\Throwable $e) {
            $this->db_error_log($e, __FUNCTION__);
            $response['message'] = $e->getMessage();
        }

        // Reset internal properties for next query
        $this->reset();
        return $this->_returnResult($response);
    }

    /**
     * Create a new record or find existing one
     *
     * @param array $conditions Conditions to search for
     * @param array $data Data to insert if not found
     * @return array
     */
    public function firstOrCreate($conditions, $data = [])
    {
        try {
            if (empty($this->table)) {
                throw new \InvalidArgumentException('Please specify the table.');
            }
            if (empty($conditions) || !is_array($conditions)) {
                throw new \InvalidArgumentException('Conditions must be a non-empty associative array.');
            }

            // Try to find existing record using array support in where()
            $existing = $this->where($conditions)->fetch();
            
            if (!empty($existing)) {
                return ['code' => 200, 'message' => 'Record found', 'action' => 'found', 'data' => $existing];
            }

            // Record doesn't exist, create it
            $insertData = array_merge($conditions, $data);
            return $this->insert($insertData);

        } catch (\Exception $e) {
            $this->db_error_log($e, __FUNCTION__);
            return ['code' => 422, 'message' => $e->getMessage(), 'action' => 'firstOrCreate'];
        }
    }

    /**
     * Get the first record matching conditions or return an unsaved attribute array.
     *
     * @param array $conditions
     * @param array $data
     * @return array
     */
    public function firstOrNew(array $conditions, array $data = [])
    {
        if (empty($conditions)) {
            throw new \InvalidArgumentException('Conditions must be a non-empty associative array.');
        }

        $query = $this->createSubQueryBuilder();
        $query->table = $this->table;
        $existing = $query->where($conditions)->fetch();
        unset($query);

        return !empty($existing) ? $existing : array_merge($conditions, $data);
    }

    /**
     * Update a matching record or create a new one, then return the persisted row.
     *
     * @param array $conditions
     * @param array $data
     * @param string $primaryKey
     * @return mixed
     */
    public function updateOrCreate(array $conditions, array $data = [], string $primaryKey = 'id')
    {
        if (empty($conditions)) {
            throw new \InvalidArgumentException('Conditions must be a non-empty associative array.');
        }

        $table = $this->table;
        $this->insertOrUpdate($conditions, $data, $primaryKey);

        $query = $this->createSubQueryBuilder();
        $query->table = $table;
        $record = $query->where($conditions)->fetch();
        unset($query);

        return $record;
    }

    # UPDATE DATA OPERATION

    /**
     * Increment a column's value
     *
     * @param string $column Column name
     * @param int $amount Amount to increment (default 1)
     * @param array $extra Extra columns to update
     * @return array
     */
    public function increment($column, $amount = 1, $extra = [])
    {
        if (empty($this->table)) {
            throw new \InvalidArgumentException('Please specify the table.');
        }

        $this->validateColumn($column);
        $amount = abs((int)$amount);
        if ($amount < 1) $amount = 1;

        // Start profiler for performance measurement
        $this->_startProfiler(__FUNCTION__);
        
        // Sanitize extra columns
        $sanitizedExtra = !empty($extra) ? $this->sanitizeColumn($extra) : [];
        
        // Build SET clause - use parameterized binding for amount
        $safeCol = '`' . str_replace('`', '``', $column) . '`';
        $set = ["$safeCol = $safeCol + ?"];
        $bindValues = [$amount];
        foreach ($sanitizedExtra as $col => $val) {
            $set[] = '`' . str_replace('`', '``', $col) . '` = ?';
            $bindValues[] = $val;
        }
        
        // Build UPDATE query
        $this->_query = "UPDATE ";
        if (empty($this->schema)) {
            $this->_query .= "`$this->table` ";
        } else {
            $this->_query .= "`{$this->schema}`.`$this->table` ";
        }
        
        $this->_query .= "SET " . implode(', ', $set);
        
        if ($this->where) {
            $this->_query .= " WHERE " . $this->where;
        }

        $stmt = $this->_prepareStatement($this->_query);
        $this->_bindParams($stmt, array_merge($bindValues, $this->_binds));
        
        try {
            $this->_captureExecutedQuery($this->_binds);
            
            $success = $stmt->execute();
            $affectedRows = $stmt->rowCount();

            $response = [
                'code' => $success ? 200 : 422,
                'affected_rows' => $affectedRows,
                'message' => $success ? "Incremented successfully" : "Failed to increment",
                'action' => 'increment'
            ];
        } catch (\PDOException $e) {
            $this->db_error_log($e, __FUNCTION__);
            throw $e;
        }

        // Stop profiler
        $this->_stopProfiler();
        $this->reset();

        return $this->_returnResult($response);
    }

    /**
     * Decrement a column's value
     *
     * @param string $column Column name
     * @param int $amount Amount to decrement (default 1)
     * @param array $extra Extra columns to update
     * @return array
     */
    public function decrement($column, $amount = 1, $extra = [])
    {
        if (empty($this->table)) {
            throw new \InvalidArgumentException('Please specify the table.');
        }

        $this->validateColumn($column);
        $amount = abs((int)$amount);
        if ($amount < 1) $amount = 1;

        // Start profiler for performance measurement
        $this->_startProfiler(__FUNCTION__);

        // Sanitize extra columns
        $sanitizedExtra = !empty($extra) ? $this->sanitizeColumn($extra) : [];

        // Build SET clause - use parameterized binding for amount
        $safeCol = '`' . str_replace('`', '``', $column) . '`';
        $set = ["$safeCol = $safeCol - ?"];
        $bindValues = [$amount];
        foreach ($sanitizedExtra as $col => $val) {
            $set[] = '`' . str_replace('`', '``', $col) . '` = ?';
            $bindValues[] = $val;
        }
        
        // Build UPDATE query
        $this->_query = "UPDATE ";
        if (empty($this->schema)) {
            $this->_query .= "`$this->table` ";
        } else {
            $this->_query .= "`{$this->schema}`.`$this->table` ";
        }
        
        $this->_query .= "SET " . implode(', ', $set);
        
        if ($this->where) {
            $this->_query .= " WHERE " . $this->where;
        }

        $stmt = $this->_prepareStatement($this->_query);
        $this->_bindParams($stmt, array_merge($bindValues, $this->_binds));
        
        try {
            $this->_captureExecutedQuery($this->_binds);
            
            $success = $stmt->execute();
            $affectedRows = $stmt->rowCount();

            $response = [
                'code' => $success ? 200 : 422,
                'affected_rows' => $affectedRows,
                'message' => $success ? "Decremented successfully" : "Failed to decrement",
                'action' => 'decrement'
            ];
        } catch (\PDOException $e) {
            $this->db_error_log($e, __FUNCTION__);
            throw $e;
        }

        // Stop profiler
        $this->_stopProfiler();
        $this->reset();

        return $this->_returnResult($response);
    }

    /**
     * Update matching rows in the current table.
     *
     * @param array $data Associative column/value payload.
     * @return mixed
     * @throws \InvalidArgumentException
     */
    public function update($data)
    {
        // Default response
        $response = ['code' => 400, 'message' => 'Failed to update data', 'action' => 'update'];

        if ($this->_isRawQuery) {
            throw new \InvalidArgumentException('Raw update SQL statements are not allowed in update(). Please use update() function.');
        }

        // Check if string is empty
        if (empty($data) || !is_array($data)) {
            throw new \InvalidArgumentException('Invalid column data. Must be an associative array.');
        }

        if (empty($this->table)) {
            throw new \InvalidArgumentException('Please specify the table.');
        }

        // Start profiler for performance measurement 
        $this->_startProfiler(__FUNCTION__);

        // sanitize column to ensure column is exists.
        $sanitizeData = $this->sanitizeColumn($data);

        // Build the final UPDATE query string
        $this->_buildUpdateQuery($sanitizeData);

        // Prepare the query statement
        $stmt = $this->_prepareStatement($this->_query);

        // Bind parameters 
        $this->_bindParams($stmt, array_merge(array_values($sanitizeData), $this->_binds));

        try {
            $this->_captureExecutedQuery($this->_binds);

            // Execute the statement
            $success = $stmt->execute();

            // Get the number of affected rows
            $affectedRows = $stmt->rowCount();

            // Return information about the update operation
            $response = [
                'code' => $success ? 200 : 422,
                'affected_rows' => $affectedRows,
                'message' => $success ? 'Data updated' : 'Failed to update data',
                'data' => $this->_safeOutputSanitize($sanitizeData),
                'action' => 'update'
            ];

            if ($success) {
                $this->flushPendingPaginateCountCacheRemovals();
            }
        } catch (\PDOException $e) {
            // Log database errors
            $this->db_error_log($e, __FUNCTION__);
            throw $e; // Re-throw the exception
        }

        // Stop profiler 
        $this->_stopProfiler();

        // Reset internal properties for next query
        $this->reset();

        return $this->_returnResult($response) ?? false;
    }

    /**
     * Builds the SQL UPDATE query string based on the provided data.
     *
     * @param array $data An associative array containing column names as keys and new values as values.
    * @throws \InvalidArgumentException If the provided data is empty, not an array, or not an associative array with column names as keys.
    * @return $this
     */
    protected function _buildUpdateQuery($data)
    {
        // Check if data is empty or not an associative array (key-value pairs)
        if (empty($data) || !is_array($data)) {
            throw new \InvalidArgumentException('Invalid column data. Must be an associative array with column names as keys.');
        }

        // Construct a comma-separated list of SET clauses
        $set = [];
        foreach ($data as $column => $value) {
            $set[] = '`' . str_replace('`', '``', (string) $column) . '` = ?';
        }
        $set = implode(', ', $set);

        // Construct the SQL UPDATE statement with table name
        $this->_query = "UPDATE ";
        if (empty($this->schema)) {
            $this->_query .= "`$this->table` ";
        } else {
            $this->_query .= "`$this->schema`.`$this->table` ";
        }

        // Append SET clause and placeholder for values
        $this->_query .= "SET $set";

        // Add WHERE clause if conditions exist
        if ($this->where) {
            $this->_query .= " WHERE " . $this->where;
        }

        return $this;
    }

    # SOFT DELETE / DELETE / TRUNCATE DATA OPERATION

    /**
     * Soft-delete by updating one or more columns instead of removing the row.
     *
     * @param array|string $column
     * @param mixed $value
     * @return mixed
     */
    public function softDelete($column = 'deleted_at', $value = null)
    {
        try {
            $columns_table = $this->getTableColumns();
            $updateData = [];

            if (is_array($column)) {
                // $column is an associative array of columns and values
                foreach ($column as $col => $val) {
                    if (!in_array($col, $columns_table)) {
                        throw new \InvalidArgumentException("Column '$col' does not exist in the table.");
                    }
                    $updateData[$col] = $val;
                }
            } else {
                // $column is a string (single column)
                if (!in_array($column, $columns_table)) {
                    throw new \InvalidArgumentException("Column '$column' does not exist in the table.");
                }
                // If value is null and column is 'deleted_at', set to current timestamp
                if ($value === null && $column === 'deleted_at') {
                    $value = date('Y-m-d H:i:s');
                }
                $updateData[$column] = $value;
            }

            return $this->update($updateData);
        } catch (\Exception $e) {
            $this->db_error_log($e, __FUNCTION__);
            return [
                'code' => 400,
                'message' => $e->getMessage(),
                'action' => 'softDelete',
            ];
        }
    }

    /**
     * Delete matching rows or route through softDelete() when supported.
     *
     * @param bool $returnData
     * @return mixed
     */
    public function delete($returnData = false)
    {
        // Default response
        $response = ['code' => 400, 'message' => 'Failed to delete data', 'action' => 'delete'];
        $deletedData = null;

        if (!$this->_isRawQuery) {
            // Check for soft delete columns
            $columns = $this->getTableColumns();
            if (in_array('deleted_at', $columns)) {
                return $this->softDelete(); // Use soft delete
            }

            // Only fetch data before delete if explicitly requested
            if ($returnData) {
                $newDb = clone $this;
                $deletedData = $newDb->get();
                unset($newDb);
            }
        }

        // Start profiler for performance measurement 
        $this->_startProfiler(__FUNCTION__);

        if (!$this->_isRawQuery) {
            if (empty($this->table)) {
                throw new \InvalidArgumentException('Please specify the table.');
            }
            // Build the final DELETE query string
            $this->_buildDeleteQuery();
        }

        // Prepare the query statement
        $stmt = $this->_prepareStatement($this->_query);

        // Bind parameters if any
        if (!empty($this->_binds)) {
            $this->_bindParams($stmt, $this->_binds);
        }

        try {
            $this->_captureExecutedQuery($this->_binds);

            // Execute the SQL DELETE statement
            $success = $stmt->execute();

            // Get the number of affected rows
            $affectedRows = $stmt->rowCount();

            // Return information about the deletion operation
            $response = [
                'code' => $success ? 200 : 422,
                'affected_rows' => $affectedRows,
                'message' => $success ? 'Data deleted' : 'Failed to delete data',
                'action' => 'delete'
            ];

            if (!$this->_isRawQuery && $deletedData !== null) {
                $response['data'] = $deletedData;
            }

            if ($success) {
                $this->flushPendingPaginateCountCacheRemovals();
            }
        } catch (\PDOException $e) {
            // Log database errors
            $this->db_error_log($e, __FUNCTION__);
            throw $e; // Re-throw the exception
        }

        // Stop profiler 
        $this->_stopProfiler();

        // Reset internal properties for next query
        $this->reset();

        return $this->_returnResult($response) ?? false;
    }

    /**
     * Force a hard delete even when the table supports soft deletes.
     *
     * @param bool $returnData
     * @return mixed
     */
    public function forceDelete(bool $returnData = false)
    {
        $response = ['code' => 400, 'message' => 'Failed to delete data', 'action' => 'forceDelete'];
        $deletedData = null;

        if (!$this->_isRawQuery && $returnData) {
            $newDb = clone $this;
            $deletedData = $newDb->get();
            unset($newDb);
        }

        $this->_startProfiler(__FUNCTION__);

        if (!$this->_isRawQuery) {
            if (empty($this->table)) {
                throw new \InvalidArgumentException('Please specify the table.');
            }

            $this->_buildDeleteQuery();
        }

        $stmt = $this->_prepareStatement($this->_query);
        if (!empty($this->_binds)) {
            $this->_bindParams($stmt, $this->_binds);
        }

        try {
            $this->_captureExecutedQuery($this->_binds);
            $success = $stmt->execute();
            $affectedRows = $stmt->rowCount();

            $response = [
                'code' => $success ? 200 : 422,
                'affected_rows' => $affectedRows,
                'message' => $success ? 'Data deleted' : 'Failed to delete data',
                'action' => 'forceDelete'
            ];

            if (!$this->_isRawQuery && $deletedData !== null) {
                $response['data'] = $deletedData;
            }

            if ($success) {
                $this->flushPendingPaginateCountCacheRemovals();
            }
        } catch (\PDOException $e) {
            $this->db_error_log($e, __FUNCTION__);
            throw $e;
        }

        $this->_stopProfiler();
        $this->reset();

        return $this->_returnResult($response) ?? false;
    }

    /**
     * Restore a soft-deleted row by clearing the soft-delete marker column.
     *
     * @param string $column
     * @return mixed
     */
    public function restore(string $column = 'deleted_at')
    {
        $columns = $this->getTableColumns();
        if (!in_array($column, $columns, true)) {
            throw new \InvalidArgumentException("Column '{$column}' does not exist in the table.");
        }

        return $this->update([$column => null]);
    }

    /**
     * Build the current DELETE SQL statement.
     *
     * @return $this
     */
    protected function _buildDeleteQuery()
    {
        // Construct the SQL delete statement
        $this->_query = "DELETE FROM ";

        // Append table name with schema (if provided)
        if (empty($this->schema)) {
            $this->_query .= "`$this->table`";
        } else {
            $this->_query .= "`$this->schema`.`$this->table`";
        }

        // Add WHERE clause if conditions exist
        if ($this->where) {
            $this->_query .= " WHERE " . $this->where;
        }

        return $this;
    }

    /**
     * Truncate the current table or an explicitly provided table name.
     *
     * @param string|null $table
     * @return mixed
     */
    public function truncate($table = null)
    {
        // Determine the table to truncate
        $tableTruncate = $table ?? $this->table;

        if (empty($tableTruncate)) {
            throw new \InvalidArgumentException('Please specify the table.');
        }

        // Quote the table name to prevent SQL injection (if needed)
        if (empty($this->schema)) {
            $quotedTable = "`{$tableTruncate}`";
        } else {
            $quotedTable = "`{$this->schema}`.`{$tableTruncate}`";
        }


        $this->_query = "TRUNCATE {$quotedTable}";

        // Start profiler for performance measurement 
        $this->_startProfiler(__FUNCTION__);

        try {
            // Prepare the query statement
            $stmt = $this->_prepareStatement($this->_query);

            $this->_captureExecutedQuery([]);

            // Execute the SQL truncate statement
            $success = $stmt->execute();

            // Return information about the truncate operation
            $response = [
                'code' => $success ? 200 : 422,
                'message' => $success ? "Truncated {$tableTruncate} successfully" : "Failed to truncate table {$tableTruncate}",
                'action' => 'truncate'
            ];
        } catch (\PDOException $e) {
            // Log database errors
            $this->db_error_log($e, __FUNCTION__);
            throw $e; // Re-throw the exception
        }

        // Stop profiler 
        $this->_stopProfiler();

        // Reset internal properties for next query
        $this->reset();

        return $this->_returnResult($response) ?? false;
    }

    # BATCH INSERT/UPDATE OPERATION

    /**
     * Insert multiple rows in a single driver-optimized operation.
     *
     * @param array $data
     * @return mixed
     */
    abstract public function batchInsert($data);

    /**
     * Update multiple rows in a single driver-optimized operation.
     *
     * @param array $data
     * @return mixed
     */
    abstract public function batchUpdate($data);

    /**
     * Perform a bulk upsert using a unique key definition.
     *
     * @param mixed $values
     * @param string|array $uniqueBy
     * @param array|null $updateColumns
     * @return mixed
     */
    abstract public function upsert($values, $uniqueBy = 'id', $updateColumns = null);

    // Implement ResultInterface logic

    /**
     * Return query results as arrays.
     *
     * @return $this
     */
    public function toArray()
    {
        $this->returnType = 'array';
        return $this;
    }

    /**
     * Return query results as stdClass objects.
     *
     * @return $this
     */
    public function toObject()
    {
        $this->returnType = 'object';
        return $this;
    }

    /**
     * Return query results encoded as JSON.
     *
     * @return $this
     */
    public function toJson()
    {
        $this->returnType = 'json';
        return $this;
    }

    # HELPER

    /**
     * A method of returning the static instance to allow access to the
     * instantiated object from within another class.
     * Inheriting this class would require reloading connection info.
     *
     * @uses $db = Database::getInstance();
     *
     * @return Database Returns the current instance.
     */
    public static function getInstance()
    {
        return self::$_instance;
    }

    /**
     * Converts the result data to the specified return type.
     *
     * @param mixed $data The data to be converted.
     * @return mixed The converted data.
     */
    protected function _returnResult($data)
    {
        if (empty($data)) {
            return $data;
        }

        switch ($this->returnType) {
            case 'object':
                $data = json_decode(json_encode($data), false);
                break;

            case 'json':
                $data = json_encode($data);
                break;

            case 'array':
            default:
                // Data is already in array format, no conversion needed
                break;
        }

        $this->returnType = 'array'; // reset to original
        return $data;
    }

    /**
     * Enable or disable secure input.
     *
     * @return $this
     */
    public function safeInput()
    {
        $this->_secureInput = true;
        return $this;
    }

    /**
     * Enable or disable secure output sanitization.
     *
     * @param bool $enable Whether to enable secure output filtering.
     * @return $this
     */
    public function safeOutput($enable = true)
    {
        $this->_secureOutput = $enable;
        return $this;
    }

    /**
     * Exclude specific columns from safeOutput sanitization.
     *
     * @param array|string $data
     * @return $this
     */
    public function safeOutputWithException($data = [])
    {
        if (empty($data)) {
            return $this;
        }

        $data = is_array($data) ? $data : explode(',', $data);
        $this->_secureOutputExeception = $data;
        return $this;
    }

    /**
     * Sanitize column data to ensure that only valid columns are used.
     *
     * Applies a two-layer column guard before any INSERT / UPDATE reaches PDO:
     *
     * Layer 1 — Schema guard (always active):
     *   Strip any key that does not exist in the actual table, preventing
     *   phantom-column injection.
     *
     * Layer 2 — Application allowlist / denylist (opt-in):
     *   When $fillable is non-empty, only declared columns survive — identical
     *   to Eloquent's $fillable.  This blocks privilege-escalation payloads
     *   (e.g. is_admin=1, role_id=1) even when those columns exist in the schema.
     *   $guarded columns are always stripped, regardless of $fillable.
     *
     * @param array $data An associative array where keys represent column names and values represent corresponding data.
     * @return array The sanitized column data.
     * @throws \Exception If there's an error accessing the database or if the table does not exist.
     */
    protected function sanitizeColumn($data): array
    {
        $columns = $this->getTableColumns();

        // Layer 1: schema guard — drop any key not in the real table
        $data = array_intersect_key($data, array_flip($columns));

        // Layer 2a: fillable allowlist — when declared, keep only whitelisted columns
        if (!empty($this->fillable)) {
            $data = array_intersect_key($data, array_flip($this->fillable));
        }

        // Layer 2b: guarded denylist — always remove these regardless of fillable
        if (!empty($this->guarded)) {
            $data = array_diff_key($data, array_flip($this->guarded));
        }

        if ($this->_secureInput) {
            $data = array_map(function ($value) {
                return $value === '' ? null : $this->normalizeDatabaseValue($value);
            }, $data);
        } else {
            // Even without full sanitization, normalize empty string → null
            $data = array_map(static fn($value) => $value === '' ? null : $value, $data);
        }

        return $data;
    }

    /**
     * Sanitizes the output data to prevent XSS attacks by applying htmlspecialchars
     * and trimming values. It handles single values, arrays, and multidimensional arrays.
     *
     * @param mixed $data The data to be sanitized.
     * @return mixed The sanitized data.
     */
    protected function _safeOutputSanitize($data)
    {
        if (!$this->_secureOutput) {
            return $data;
        }

        // Early return if data is null or empty
        if (is_null($data) || $data === '') {
            return $data;
        }

        return $this->sanitize($data, $this->_secureOutputExeception);
    }

    # EAGER LOADER SECTION


    # PROFILER SECTION













    # HELPER SECTION

    /**
     * Begin a transaction.
     *
     * @return void
     */
    public function beginTransaction()
    {
        if (!$this->pdo[$this->connectionName]->inTransaction()) {
            $this->pdo[$this->connectionName]->beginTransaction();
        }
    }

    /**
     * Commit a transaction.
     *
     * @return void
     */
    public function commit()
    {
        if ($this->pdo[$this->connectionName]->inTransaction()) {
            $this->pdo[$this->connectionName]->commit();
        }
    }

    /**
     * Rollback a transaction.
     *
     * @return void
     */
    public function rollback()
    {
        if ($this->pdo[$this->connectionName]->inTransaction()) {
            $this->pdo[$this->connectionName]->rollBack();
        }
    }

    /**
     * Bind positional or named parameters onto a prepared statement.
     *
     * @param \PDOStatement $stmt
     * @param array $binds
     * @return void
     */
    protected function _bindParams(\PDOStatement $stmt, array $binds)
    {
        $query = $stmt->queryString;
        $trackProfilerBinds = $this->enableProfiling && isset($this->_profiler['profiling'][$this->_profilerActive]);

        // Fast-path: most queries use positional parameters
        $hasPositional = strpos($query, '?') !== false;

        // Reset
        $this->_binds = [];
        if ($trackProfilerBinds) {
            $this->_profiler['profiling'][$this->_profilerActive]['binds'] = [];
        }

        if ($hasPositional) {
            // Optimized path for positional parameters (most common case)
            foreach ($binds as $key => $value) {
                if (!is_numeric($key)) {
                    throw new \PDOException('Positional parameters require numeric keys', 400);
                }

                if ($value === null) {
                    $stmt->bindValue($key + 1, null, \PDO::PARAM_NULL);
                } else if (is_int($value)) {
                    $stmt->bindValue($key + 1, $value, \PDO::PARAM_INT);
                } else if (is_bool($value)) {
                    $stmt->bindValue($key + 1, $value, \PDO::PARAM_BOOL);
                } else {
                    $stmt->bindValue($key + 1, $value, \PDO::PARAM_STR);
                }

                $this->_binds[] = $value;
                if ($trackProfilerBinds) {
                    $this->_profiler['profiling'][$this->_profilerActive]['binds'][] = $value;
                }
            }
        } else {
            // Named parameters (rare case)
            $hasNamed = preg_match('/:\w+/', $query);
            if (!$hasNamed) {
                throw new \PDOException('Query must contain either positional (?) or named (:number, :param) placeholders', 400);
            }

            foreach ($binds as $key => $value) {
                $type = $value === null
                    ? \PDO::PARAM_NULL
                    : (is_int($value) ? \PDO::PARAM_INT : (is_bool($value) ? \PDO::PARAM_BOOL : \PDO::PARAM_STR));
                $placeholder = str_starts_with((string) $key, ':') ? (string) $key : ':' . $key;
                $stmt->bindValue($placeholder, $value, $type);
                $this->_binds[] = $value;
                if ($trackProfilerBinds) {
                    $this->_profiler['profiling'][$this->_profilerActive]['binds'][] = $value;
                }
            }
        }
    }

    /**
     * Expand a parameterized SQL string into a debug-safe full SQL preview.
     *
     * @param string $query
     * @param array|null $binds
     * @param bool $storeInProfiler
     * @return string
     */
    protected function _generateFullQuery($query, $binds = null, bool $storeInProfiler = true)
    {
        if (!empty($binds)) {
            // Check if positional or named parameters are used
            $hasPositional = strpos($query, '?') !== false;
            $hasNamed = preg_match('/:\w+/', $query);

            foreach ($binds as $key => $value) {
                if ($value === null) {
                    $quotedValue = 'NULL';
                } elseif (is_numeric($value)) {
                    $quotedValue = $value;
                } elseif (is_string($value)) {
                    $quotedValue = $this->pdo[$this->connectionName]->quote($value, \PDO::PARAM_STR);
                } else {
                    $quotedValue = $this->pdo[$this->connectionName]->quote((string)$value, \PDO::PARAM_STR);
                }

                if ($hasPositional) {
                    // Positional parameter: replace with quoted value
                    if (is_numeric($key)) {
                        $query = preg_replace('/\?/', $quotedValue, $query, 1);
                    } else {
                        throw new \PDOException('Positional parameters require numeric keys', 400);
                    }
                } else if ($hasNamed) {
                    // Named parameter: replace with quoted value
                    $query = str_replace(':' . $key, $quotedValue, $query);
                } else {
                    throw new \PDOException('Query must contain either positional (?) or named (:number, :param) placeholders', 400);
                }
            }
        }

        if ($storeInProfiler) {
            $this->_profiler['profiling'][$this->_profilerActive]['full_query'] = $query;
        }

        return $query;
    }

    /**
     * Expands asterisks (*) in the SELECT clause to include all table columns.
     * Optimized: only runs regex when the query actually contains a standalone asterisk.
     *
     * @param string $query The SQL query string.
     * @return string The modified query string with expanded columns.
     */
    protected function _expandAsterisksInQuery($query)
    {
        // Fast path: skip regex entirely if no standalone asterisk in SELECT portion
        // This avoids expensive regex on queries that already have explicit columns
        $fromPos = stripos($query, ' FROM ');
        if ($fromPos === false) {
            return $query;
        }

        $selectPortion = substr($query, 0, $fromPos);
        
        // Only process if SELECT portion contains a standalone * (not table.*)
        if (strpos($selectPortion, '*') === false) {
            return $query;
        }

        // Scenario 1: SELECT * FROM table
        if (preg_match('/SELECT\s+\*\s+FROM\s+([\w]+)/i', $query, $matches)) {
            $tables = [$matches[1]];

            // Add JOINed tables if present
            if (preg_match_all('/JOIN\s+([\w]+)\s+/i', $query, $joinMatches)) {
                $tables = array_merge($tables, $joinMatches[1]);
            }

            // Construct new SELECT part with table.*
            $selectPart = implode(', ', array_map(fn($table) => "`$table`.*", $tables));
            $query = preg_replace('/SELECT\s+\*\s+FROM/i', "SELECT $selectPart FROM", $query, 1);
        } else {
            return $query;
        }

        return $query;
    }

    /**
     * @var array Static cache for table columns to avoid repeated DESCRIBE queries
     */
    protected static $_tableColumnsCache = [];

    /**
     * Get all column names for the current table.
     * Results are cached statically to avoid repeated DESCRIBE queries per request.
     *
     * @return array List of column names, or empty array on error.
     */
    protected function getTableColumns()
    {
        // Return empty array if table is not set (e.g., when using raw queries)
        if (empty($this->table)) {
            return [];
        }

        // Build cache key from connection + schema + table
        $cacheKey = ($this->connectionName ?? 'default') . '.' . ($this->schema ?? '') . '.' . $this->table;

        // Return cached result if available
        if (isset(self::$_tableColumnsCache[$cacheKey])) {
            return self::$_tableColumnsCache[$cacheKey];
        }
        
        $columns = [];
        try {
            $query = !empty($this->schema) 
                ? "DESCRIBE `{$this->schema}`.`{$this->table}`" 
                : "DESCRIBE `{$this->table}`";
            $stmt = $this->_prepareStatement($query);
            $stmt->execute();
            $columns = $stmt->fetchAll(\PDO::FETCH_COLUMN);

            // Cache the result
            self::$_tableColumnsCache[$cacheKey] = $columns;
        } catch (\PDOException $e) {
            $this->db_error_log($e, __FUNCTION__);
            return [];
        }
        return $columns;
    }

    /**
     * Clear the table columns cache (useful after schema changes)
     *
     * @param string|null $table Specific table to clear, or null to clear all
     * @return void
     */
    public static function clearTableColumnsCache($table = null)
    {
        if ($table === null) {
            self::$_tableColumnsCache = [];
        } else {
            foreach (array_keys(self::$_tableColumnsCache) as $key) {
                if (str_ends_with($key, '.' . $table)) {
                    unset(self::$_tableColumnsCache[$key]);
                }
            }
        }
    }

    /**
     * Check if a column exists in the current table.
     *
     * @param string $column The column name to check.
     * @return bool True if the column exists, false otherwise.
     */
    public function hasColumn($column)
    {
        if (empty($column) || !is_string($column)) {
            return false;
        }
        $columns = $this->getTableColumns();
        return in_array($column, $columns, true);
    }


    /**
     * Analyzes the currently selected table.
     *
     * @return bool True on success, false on failure.
     */
    public function analyze()
    {
        try {
            if (empty($this->table)) {
                throw new \InvalidArgumentException('No table selected. Please set $this->table before calling analyze().');
            }

            $stmt = $this->_prepareStatement("ANALYZE TABLE `{$this->table}`");
            $stmt->execute();

            $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            return isset($result[0]['Msg_text']) && strtolower($result[0]['Msg_text']) === 'ok';
        } catch (\PDOException $e) {
            $this->db_error_log($e, __FUNCTION__);
            return false;
        }
    }

    /**
     * Normalize, log, and optionally rethrow database-related failures.
     *
     * @param \Throwable $e
     * @param string $function
     * @param string $customMessage
     * @param array $context
     * @param bool $rethrow
     * @return void
     */
    protected function db_error_log(
        \Throwable $e,
        string $function = '',
        string $customMessage = 'Database error occurred',
        array $context = [],
        bool $rethrow = true
    ) {
        try {
            // Normalize error code to ensure it's an integer
            $errorCode = is_numeric($e->getCode()) ? (int) $e->getCode() : crc32((string) $e->getCode());

            // Format error message consistently
            $functionPart = $function ? "'{$function}()'" : 'unknown function';
            $formattedMessage = "{$customMessage} in {$functionPart}: " . $e->getMessage();

            // Extract PDO specific information if available
            $pdoErrorInfo = null;
            if ($e instanceof \PDOException && isset($e->errorInfo)) {
                $pdoErrorInfo = [
                    'sqlstate' => $e->errorInfo[0] ?? null,
                    'driver_code' => $e->errorInfo[1] ?? null,
                    'driver_message' => $e->errorInfo[2] ?? null,
                ];
            }

            // Get formatted stack trace with limited depth
            $trace = $e->getTrace();
            $formattedTrace = [];
            foreach (array_slice($trace, 0, 5) as $index => $frame) {
                $formattedTrace[] = [
                    'step' => $index + 1,
                    'file' => $frame['file'] ?? 'unknown',
                    'line' => $frame['line'] ?? 'unknown',
                    'function' => $frame['function'] ?? 'unknown',
                    'class' => $frame['class'] ?? null,
                ];
            }

            // Build comprehensive error information
            $this->_error = [
                'timestamp' => date('Y-m-d H:i:s'),
                'type' => get_class($e),
                'code' => $errorCode,
                'message' => $formattedMessage,
                'original_message' => $e->getMessage(),
                'function' => $function,
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $formattedTrace,
                'context' => $context,
            ];

            // Add PDO specific information if available
            if ($pdoErrorInfo) {
                $this->_error['pdo_error_info'] = $pdoErrorInfo;
            }

            // Determine log level based on exception type
            $logLevel = match (true) {
                $e instanceof \PDOException => 'CRITICAL',
                $e instanceof \Error => 'FATAL',
                $e instanceof \InvalidArgumentException => 'WARNING',
                default => 'ERROR'
            };

            // Check if error should be considered critical
            $isCritical = $e instanceof \PDOException ||
                $e instanceof \Error ||
                stripos($e->getMessage(), 'connection') !== false ||
                stripos($e->getMessage(), 'timeout') !== false;

            // Log the error with comprehensive details
            try {
                $rootDir = defined('ROOT_DIR') ? ROOT_DIR : dirname(__DIR__, 3) . DIRECTORY_SEPARATOR;
                $logger = new Logger($rootDir . 'logs' . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'error.log');

                $logData = [
                    'level' => $logLevel,
                    'error_details' => $this->_error,
                    'server_info' => [
                        'php_version' => PHP_VERSION,
                        'memory_usage' => memory_get_usage(true),
                        'request_uri' => $_SERVER['REQUEST_URI'] ?? 'CLI',
                        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
                    ],
                ];

                $logMessage = sprintf(
                    "[%s] %s - %s\nDetails: %s",
                    $this->_error['type'],
                    $this->_error['message'],
                    $this->_error['file'] . ':' . $this->_error['line'],
                    json_encode($logData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                );

                $logger->log_error($logMessage);

                // Also log to system error log for critical errors
                if ($isCritical) {
                    $dbLogDir = (defined('ROOT_DIR') ? ROOT_DIR : dirname(__DIR__, 3) . DIRECTORY_SEPARATOR) . 'logs' . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR;
                    if (!is_dir($dbLogDir)) { @mkdir($dbLogDir, 0775, true); }
                    error_log("Critical Database Error: " . $this->_error['message'] . PHP_EOL, 3, $dbLogDir . 'error.log');
                }
            } catch (\Throwable $logException) {
                // Fallback to system error log if custom logger fails
                $dbLogDir = (defined('ROOT_DIR') ? ROOT_DIR : dirname(__DIR__, 3) . DIRECTORY_SEPARATOR) . 'logs' . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR;
                if (!is_dir($dbLogDir)) { @mkdir($dbLogDir, 0775, true); }
                error_log("Database error logging failed: " . $logException->getMessage() . PHP_EOL, 3, $dbLogDir . 'error.log');
                error_log("Original error: " . $e->getMessage() . PHP_EOL, 3, $dbLogDir . 'error.log');
            }

            // Optionally rethrow the exception with appropriate type
            if ($rethrow) {
                // Preserve original exception type when possible
                switch (true) {
                    case $e instanceof \PDOException:
                        throw new \PDOException($formattedMessage, $errorCode, $e);

                    case $e instanceof \InvalidArgumentException:
                        throw new \InvalidArgumentException($formattedMessage, $errorCode, $e);

                    case $e instanceof \RuntimeException:
                        throw new \RuntimeException($formattedMessage, $errorCode, $e);

                    case $e instanceof \Error:
                        throw new \Exception($formattedMessage, $errorCode, $e);

                    default:
                        throw new \Exception($formattedMessage, $errorCode, $e);
                }
            }
        } catch (\Throwable $loggingError) {
            // Fallback error handling if main processing fails
            $this->_error = [
                'timestamp' => date('Y-m-d H:i:s'),
                'type' => get_class($e),
                'code' => is_numeric($e->getCode()) ? (int) $e->getCode() : 0,
                'message' => 'Database error occurred (processing failed): ' . $e->getMessage(),
                'processing_error' => $loggingError->getMessage(),
            ];

            // Fallback to system error log
            $dbLogDir = (defined('ROOT_DIR') ? ROOT_DIR : dirname(__DIR__, 3) . DIRECTORY_SEPARATOR) . 'logs' . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR;
            if (!is_dir($dbLogDir)) { @mkdir($dbLogDir, 0775, true); }
            error_log("Database error processing failed: " . $loggingError->getMessage() . PHP_EOL, 3, $dbLogDir . 'error.log');
            error_log("Original database error: " . $e->getMessage() . PHP_EOL, 3, $dbLogDir . 'error.log');

            // Still throw the original exception if rethrowing is enabled
            if ($rethrow) {
                throw new \Exception(
                    'Database error occurred (processing failed): ' . $e->getMessage(),
                    is_numeric($e->getCode()) ? (int) $e->getCode() : 0,
                    $e
                );
            }
        }
    }

    /**
     * Prepare a statement using the statement cache for better performance
     *
     * @param string $query SQL query
     * @return \PDOStatement
     */
    protected function _prepareStatement($query)
    {
        return StatementCache::get(
            $this->pdo[$this->connectionName],
            $query,
            $this->connectionName
        );
    }

    /**
     * Get comprehensive performance statistics
     *
     * @return array
     */
    public function getPerformanceReport(array $options = [])
    {
        return PerformanceMonitor::generateReport($options);
    }

    /**
     * Enable query caching
     *
     * @param int $ttl Time to live in seconds
     * @return $this
     */
    public function enableQueryCache($ttl = 3600)
    {
        QueryCache::enable();
        QueryCache::setDefaultTTL($ttl);
        return $this;
    }

    /**
     * Disable query caching
     *
     * @return $this
     */
    public function disableQueryCache()
    {
        QueryCache::disable();
        return $this;
    }
}