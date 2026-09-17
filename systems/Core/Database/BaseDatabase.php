<?php

namespace Core\Database;

/**
 * Database Class
 *
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
    use Macroable, Scopeable;
    use HasDebugHelpers, HasStreaming;
    use HasWhereConditions, HasJoins, HasAggregates;
    use HasEagerLoading, HasPaginateCountCache, HasProfiling;

    protected const DEFAULT_PAGINATE_LIMIT = 10;
    protected const MAX_PAGINATE_LIMIT = 500;

    /** Total transaction attempts when db.retry.attempts is unset. */
    protected const DEFAULT_TRANSACTION_ATTEMPTS = 3;

    /** First backoff step, in milliseconds, when db.retry.delay_ms is unset. */
    protected const DEFAULT_RETRY_DELAY_MS = 50;

    /** Ceiling on the exponential backoff so a retry storm cannot stall a worker. */
    protected const MAX_RETRY_DELAY_MS = 2000;

    /**
     * Query grammars keyed by driver name.
     *
     * Static because a grammar is stateless and the builder asks for one on every
     * identifier it wraps; per-instance would rebuild it for each query object.
     *
     * @var array<string, \Core\Database\Query\Grammars\QueryGrammar>
     */
    private static array $grammarCache = [];

    /**
     * Driver error codes worth replaying a transaction for.
     *
     * @var list<int>
     */
    protected const RETRYABLE_DRIVER_CODES = [
        1213, // ER_LOCK_DEADLOCK
        1205, // ER_LOCK_WAIT_TIMEOUT
        1479, // ER_XA_RBROLLBACK style branch rollback
        1614, // ER_XA_RBDEADLOCK
        3058, // Galera / Group Replication certification conflict
        3572, // ER_LOCK_NOWAIT — row locked and NOWAIT was requested
        2006, // CR_SERVER_GONE_ERROR
        2013, // CR_SERVER_LOST
    ];
    /**
     * Driver codes meaning "the server gave up on this statement", not "the query
     * was wrong". Retrying is pointless — the same query will take the same time —
     * so these are surfaced with an explanation instead.
     *
     * @var list<int>
     */
    protected const STATEMENT_TIMEOUT_CODES = [
        3024, // MySQL   ER_QUERY_TIMEOUT      — max_execution_time exceeded
        1969, // MariaDB ER_STATEMENT_TIMEOUT  — max_statement_time exceeded
    ];

    protected const MAX_PAGINATE_FILTER_LENGTH = 255;
    protected const DEFAULT_ITERABLE_WRITE_BATCH_SIZE = 2000;

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

    /** @var array<string, array{read: array<int, string>, write: string, sticky: bool}> */
    protected array $readWriteRouting = [];

    /** @var array<string, bool> */
    protected array $stickyWriteState = [];

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
        'sqlite' => 'SQLite',
        '-' => 'Unknown Driver'
    ];

    /**
     * @var string The return type for return result.
     */
    protected $returnType = 'array';

    /**
     * The format the caller asked for, surviving reset().
     *
     * reset() runs inside the select pipeline before _returnResult() reads
     * $returnType, so toJson() and toObject() were erased before they could
     * take effect. This field is cleared only once the result is formatted.
     *
     * @var string|null
     */
    protected $requestedReturnType = null;

    /**
     * @var array|string The list of columns used for pagination filtering.
     */
    protected $_paginateColumn = [];

    /**
     * @var array|string The list of allowed columns for pagination ordering
     */
    protected $_paginateAllowedSortColumns = [];

    /**
     * @var array<string> Positive allowlist for dynamic ORDER BY columns.
     */
    protected array $sortableColumns = [];

    /**
     * @var array<string> Positive allowlist for dynamic WHERE columns.
     */
    protected array $filterableColumns = [];

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
     * @var bool Whether the next insert should skip rows that violate a unique key
     */
    protected $_insertIgnore = false;

    /**
     * @var bool Transaction state flag
     */
    protected $inTransaction = false;

    protected int $savepointDepth = 0;

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
     * @return void
     */
    public function setConnection($connectionID)
    {
        $this->connectionName = $connectionID;
    }

    /**
     * Return the active connection name.
     *
     * @return string|null
     */
    public function getConnection($connectionID = null)
    {
        return $this->connectionName;
    }

    /**
     * Override the schema/database used when qualifying table names.
     *
     * @return void
     */
    public function setDatabase($databaseName = null)
    {
        $this->schema = $databaseName;
    }

    /**
     * The tables a cached SELECT depends on.
     *
     * QueryCache mixes a per-table version stamp into the cache key so a write
     * invalidates the reads that touched that table. Every call site passed
     * three arguments and left the fourth — the tables — defaulted to `[]`, so
     * the stamp was never part of any key and the whole mechanism did nothing.
     *
     * Joined tables count: a SELECT across users and orders has to be dropped
     * when either one changes.
     *
     * @return list<string>
     */
    protected function _tablesForCacheKey(): array
    {
        $tables = [];

        if ((string) $this->table !== '') {
            $tables[] = (string) $this->table;
        }

        // $joins is the rendered SQL fragment, so the table names are read back
        // out of it rather than from a structured list the builder does not keep.
        if (is_string($this->joins) && $this->joins !== '') {
            if (preg_match_all('/\bJOIN\s+[`"\[]?([A-Za-z0-9_.]+)[`"\]]?/i', $this->joins, $matches)) {
                foreach ($matches[1] as $joined) {
                    $tables[] = str_contains($joined, '.')
                        ? substr($joined, strrpos($joined, '.') + 1)
                        : $joined;
                }
            }
        }

        return array_values(array_unique(array_filter($tables)));
    }

    /**
     * Drop cached reads of the tables a write just changed.
     *
     * QueryCache caches SELECT results and keys them partly on a per-table
     * version, and `invalidateTable()` — the thing that bumps that version —
     * had **no callers anywhere**. The cache is enabled by default, so any
     * read repeated after a write in the same request served the pre-write
     * rows: update() then fetch() returned the old value, delete() then get()
     * still returned the deleted row.
     *
     * Called from _captureExecutedQuery(), which every execution path already
     * goes through, so a write added later is covered without remembering to
     * wire it up.
     */
    protected function _invalidateQueryCacheForWrite(): void
    {
        $query = (string) ($this->_query ?? '');

        if ($query === '' || !preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE|TRUNCATE|MERGE)\b/i', $query)) {
            return;
        }

        try {
            QueryCache::invalidateTable($this->_tablesTouchedByWrite($query));
        } catch (\Throwable) {
            // Cache bookkeeping must not fail the write it is bookkeeping for.
        }
    }

    /**
     * The tables a write statement names, plus the builder's own.
     *
     * Only matters for the cross-request APCu layer, which versions per table;
     * invalidateTable() clears the whole in-process cache regardless of what
     * it is handed.
     *
     * @return list<string>
     */
    private function _tablesTouchedByWrite(string $query): array
    {
        $tables = [];

        if ((string) $this->table !== '') {
            $tables[] = (string) $this->table;
        }

        if (preg_match(
            '/^\s*(?:INSERT(?:\s+\w+)*\s+INTO|UPDATE|DELETE\s+FROM|REPLACE\s+INTO|TRUNCATE(?:\s+TABLE)?|MERGE\s+INTO)\s+[`"\[]?([A-Za-z0-9_.]+)[`"\]]?/i',
            $query,
            $matches
        )) {
            // Strip any schema qualifier: the version is keyed on the table.
            $named = $matches[1];
            $tables[] = str_contains($named, '.') ? substr($named, strrpos($named, '.') + 1) : $named;
        }

        return array_values(array_unique(array_filter($tables)));
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
     * The grammar for the active connection.
     *
     * Every engine-specific fragment the builder emits should come from here
     * rather than be written inline, so adding PostgreSQL or SQL Server means
     * writing a grammar rather than auditing the builder for backticks.
     *
     * Resolved lazily and memoised per driver: this is called from the middle of
     * query construction, where a registry lookup per identifier would be felt.
     */
    protected function grammar(): \Core\Database\Query\Grammars\QueryGrammar
    {
        $driver = $this->resolveGrammarDriverName();

        return self::$grammarCache[$driver] ??= DriverRegistry::queryGrammar($driver);
    }

    /**
     * MariaDB reports itself as the `mysql` PDO driver, and the two grammars
     * differ on temporal expressions and RETURNING — so the banner decides.
     */
    private function resolveGrammarDriverName(): string
    {
        // Checked rather than caught: the builder compiles SQL before it ever
        // connects, and reaching into $this->pdo for a connection that does not
        // exist emits a warning on the way to the exception.
        if (!isset($this->pdo[$this->connectionName])) {
            return 'mysql';
        }

        try {
            $driver = strtolower((string) $this->getDriver());
        } catch (\Throwable) {
            // Connection present but unusable; MySQL is the shipped default.
            return 'mysql';
        }

        if ($driver === 'mysql') {
            try {
                if (stripos((string) $this->getVersion(), 'mariadb') !== false) {
                    return 'mariadb';
                }
            } catch (\Throwable) {
                // Version probe failed; the MySQL grammar is the safe answer.
            }
        }

        return $driver;
    }

    /** Quote one identifier for the active engine. */
    protected function wrapIdentifier(string $identifier): string
    {
        return $this->grammar()->wrap($identifier);
    }

    /** Quote the current table, schema-qualified when one is set. */
    protected function wrapCurrentTable(): string
    {
        return $this->grammar()->wrapTable((string) $this->table, (string) ($this->schema ?? ''));
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
        return $this->resolvePdo($this->isReadOnlyStatement($this->_query ?? null) ? 'read' : 'write');
    }

    /**
     * Register read/write aliases for a logical connection name.
     *
     * @param array<int, string> $readAliases
     */
    public function configureReadWriteRouting(string $connectionName, array $readAliases, string $writeAlias, bool $sticky = true): static
    {
        $normalized = strtolower(trim($connectionName));
        if ($normalized === '') {
            $normalized = 'default';
        }

        $readAliases = array_values(array_filter(array_map(static function ($alias): string {
            return strtolower(trim((string) $alias));
        }, $readAliases), static function (string $alias): bool {
            return $alias !== '';
        }));

        $writeAlias = strtolower(trim($writeAlias));
        if ($writeAlias === '') {
            $writeAlias = $normalized;
        }

        $this->readWriteRouting[$normalized] = [
            'read' => $readAliases,
            'write' => $writeAlias,
            'sticky' => $sticky,
        ];

        return $this;
    }

    /**
     * Close an existing connection and optionally drop its config entry.
     *
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
            'sortableColumns' => $this->sortableColumns,
            'filterableColumns' => $this->filterableColumns,
        ];
    }

    /**
     * Restore query state from a previously saved state array.
     *
     * @param array $state Saved state from _saveQueryState()
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
        $this->sortableColumns = $state['sortableColumns'] ?? [];
        $this->filterableColumns = $state['filterableColumns'] ?? [];
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
     * Create a lightweight builder that preserves write-relevant state.
     *
     * @return static
     */
    protected function createBatchedCrudBuilder()
    {
        $builder = clone $this;
        $builder->reset();
        $builder->connectionName = $this->connectionName;
        $builder->schema = $this->schema;
        $builder->table = $this->table;
        $builder->driver = $this->driver;
        $builder->fillable = $this->fillable;
        $builder->guarded = $this->guarded;

        if ($this->_secureInput) {
            $builder->safeInput();
        }

        return $builder;
    }

    /**
     * Run the callback inside a transaction and commit or rollback automatically.
     *
     * @param callable $callback
     * @return mixed
     */
    /**
     * Run a callback inside a transaction, retrying on deadlock or lock-wait timeout.
     *
     * Nested calls use savepoints — beginTransaction() cannot nest, so a service that
     * wrapped a repository already in a transaction used to throw outright. Only the
     * outermost call retries: an inner rollback leaves the outer work intact, so
     * replaying just the inner block would commit a half-applied change.
     *
     * @param int $attempts Total tries for the outermost transaction.
     */
    /**
     * Run a callback inside a transaction.
     *
     * Defaults to a SINGLE attempt, deliberately. A retry re-executes the whole
     * callback; InnoDB has rolled the database writes back, but anything the
     * callback did outside the database — sending mail, calling an API, writing a
     * file — happens again. Silently replaying that is worse than surfacing the
     * deadlock, so opting in is the caller's decision.
     *
     * Use retryOnDeadlock() when the callback is genuinely idempotent.
     *
     * @param  int|null $attempts Total attempts including the first. Null = 1.
     */
    public function transaction(callable $callback, ?int $attempts = null)
    {
        if ($this->inTransaction()) {
            // A nested call cannot retry: the outer transaction owns the retry
            // decision, and replaying only the inner block would re-apply work on
            // top of state the outer rollback has not undone.
            return $this->transactionWithSavepoint($callback);
        }

        $attempts = max(1, $attempts ?? 1);

        for ($attempt = 1; ; $attempt++) {
            $this->beginTransaction();

            try {
                $result = $callback($this);
                $this->commit();

                return $result;
            } catch (\Throwable $e) {
                $this->rollbackQuietly();

                if ($attempt >= $attempts || !$this->isRetryableTransactionError($e)) {
                    throw $e;
                }

                $this->recordTransactionRetry($e, $attempt);
                $this->sleepBeforeRetry($attempt);
            }
        }
    }

    /**
     * Sort key values so concurrent writers acquire row locks in the same order.
     *
     * This is deadlock *prevention* rather than recovery, and it removes the most
     * common cause outright. Two transactions that touch the same rows in opposite
     * orders will deadlock every time:
     *
     *   T1: lock 1 → lock 2      T2: lock 2 → lock 1
     *
     * Both hold what the other wants. Give every writer the same ordering and the
     * cycle cannot form — the second writer simply waits. Sorting a batch costs
     * O(n log n) on an array that is about to become a round trip to the database,
     * which is not a measurable cost.
     *
     * Mixed-type keys are compared as strings so the order is at least total and
     * stable across processes; the guarantee that matters is that every writer
     * agrees, not what the order actually is.
     *
     * @param  list<mixed> $keys
     * @return list<mixed>
     */
    protected function orderKeysForLocking(array $keys): array
    {
        if (count($keys) < 2) {
            return $keys;
        }

        $allNumeric = true;

        foreach ($keys as $key) {
            if (!is_int($key) && !(is_string($key) && ctype_digit($key))) {
                $allNumeric = false;
                break;
            }
        }

        if ($allNumeric) {
            usort($keys, static fn($a, $b): int => (int) $a <=> (int) $b);

            return $keys;
        }

        usort($keys, static function ($a, $b): int {
            return strcmp((string) $a, (string) $b);
        });

        return $keys;
    }

    /**
     * Sort a batch of rows by their key column so concurrent writers lock in the
     * same order. See orderKeysForLocking() for why this eliminates rather than
     * merely retries the most common deadlock.
     *
     * Rows without the key column keep their relative position at the end: they
     * cannot participate in a key-ordered lock sequence anyway, and reordering
     * them would change behaviour for no benefit.
     *
     * @param  list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    protected function orderRowsForLocking(array $rows, string $keyColumn = 'id'): array
    {
        if (count($rows) < 2) {
            return $rows;
        }

        $keyed = [];
        $unkeyed = [];

        foreach ($rows as $row) {
            if (is_array($row) && array_key_exists($keyColumn, $row) && $row[$keyColumn] !== null) {
                $keyed[] = $row;
                continue;
            }

            $unkeyed[] = $row;
        }

        if (count($keyed) < 2) {
            return array_merge($keyed, $unkeyed);
        }

        $order = $this->orderKeysForLocking(array_column($keyed, $keyColumn));
        $position = array_flip(array_map(static fn($value): string => (string) $value, $order));

        usort($keyed, static function (array $a, array $b) use ($keyColumn, $position): int {
            return ($position[(string) $a[$keyColumn]] ?? 0) <=> ($position[(string) $b[$keyColumn]] ?? 0);
        });

        return array_merge($keyed, $unkeyed);
    }

    /**
     * Run an idempotent callback in a transaction, replaying it on lock contention.
     *
     * A deadlock is not a fault condition in InnoDB — it is how the engine breaks a
     * wait cycle. It picks a victim, rolls that transaction back completely, and
     * expects the client to try again. Treating it as a hard failure is what turns
     * normal concurrency into lost work.
     *
     * Only use this where replaying the callback is safe: no mail, no outbound HTTP,
     * no file writes, no queue dispatch. Everything it touches must be inside the
     * transaction that just got rolled back.
     *
     * Attempts and the backoff base come from `db.retry`
     * (attempts: 3, delay_ms: 50), and honour `db.retry.enabled`.
     */
    public function retryOnDeadlock(callable $callback, ?int $attempts = null)
    {
        return $this->transaction($callback, $attempts ?? $this->configuredTransactionAttempts());
    }

    /**
     * Roll back without letting the rollback itself mask the original failure.
     *
     * When the connection has already dropped, rollback() throws — and that
     * exception would replace the deadlock we actually want to inspect and retry.
     */
    protected function rollbackQuietly(): void
    {
        try {
            $this->rollback();
        } catch (\Throwable $rollbackError) {
            $this->logTransactionWarning('Rollback failed: ' . $rollbackError->getMessage());
        }
    }

    /** Total attempts from db.retry, honouring db.retry.enabled. */
    protected function configuredTransactionAttempts(): int
    {
        if (!function_exists('config')) {
            return self::DEFAULT_TRANSACTION_ATTEMPTS;
        }

        $retry = (array) config('db.retry', []);

        if (array_key_exists('enabled', $retry) && $retry['enabled'] !== true) {
            return 1;
        }

        return max(1, (int) ($retry['attempts'] ?? self::DEFAULT_TRANSACTION_ATTEMPTS));
    }

    /**
     * Exponential backoff with full jitter.
     *
     * Full jitter — a uniform draw from [0, cap] rather than a fixed delay plus a
     * small wobble — is what actually decorrelates competing workers. With N
     * workers deadlocking on the same rows, a fixed backoff makes all N wake up
     * together and deadlock again.
     */
    protected function sleepBeforeRetry(int $attempt): void
    {
        $baseMs = self::DEFAULT_RETRY_DELAY_MS;

        if (function_exists('config')) {
            $baseMs = max(1, (int) config('db.retry.delay_ms', self::DEFAULT_RETRY_DELAY_MS));
        }

        $capMs = min($baseMs * (2 ** ($attempt - 1)), self::MAX_RETRY_DELAY_MS);

        $this->usleepFor(random_int(0, (int) $capMs * 1000));
    }

    /** Seam so tests can assert on backoff without actually sleeping. */
    protected function usleepFor(int $microseconds): void
    {
        if ($microseconds > 0) {
            usleep($microseconds);
        }
    }

    /**
     * Whether a failed transaction is worth replaying.
     *
     * Covers, in order of how often they actually occur:
     *   1213  ER_LOCK_DEADLOCK          — InnoDB broke a cycle and picked us
     *   1205  ER_LOCK_WAIT_TIMEOUT      — innodb_lock_wait_timeout elapsed
     *   1479 / 1614                     — transaction branch rolled back (XA, group replication)
     *   3058 / 3572                     — Galera / Group Replication certification conflict
     *   2006  CR_SERVER_GONE_ERROR      — connection dropped mid-transaction
     *   2013  CR_SERVER_LOST            — same, while waiting on a result
     *
     * SQLSTATE 40001 is checked as well: it is the standard serialization-failure
     * class and MariaDB and Galera report conflicts there with driver codes that
     * differ from MySQL's.
     *
     * The whole previous chain is walked, because the query builder wraps PDO
     * failures in its own exceptions in several code paths and the original
     * PDOException would otherwise never be seen.
     */
    protected function isRetryableTransactionError(\Throwable $e): bool
    {
        for ($error = $e; $error !== null; $error = $error->getPrevious()) {
            if (!$error instanceof \PDOException) {
                continue;
            }

            $sqlState = (string) ($error->errorInfo[0] ?? '');
            $driverCode = (int) ($error->errorInfo[1] ?? 0);

            // 40001 is the SQL standard serialization-failure class and 40P01 is
            // PostgreSQL's deadlock. Both are portable; the numeric tables below
            // are engine-specific fallbacks.
            if ($sqlState === '40001' || $sqlState === '40P01') {
                return true;
            }

            if (in_array($driverCode, self::RETRYABLE_DRIVER_CODES, true)) {
                return true;
            }

            if ($this->activeTimeoutDialect()?->isRetryableCode($driverCode) === true) {
                return true;
            }
        }

        return false;
    }

    protected function recordTransactionRetry(\Throwable $e, int $attempt): void
    {
        $this->logTransactionWarning(sprintf(
            'Transaction attempt %d failed with a retryable lock error and will be replayed: %s',
            $attempt,
            $e->getMessage()
        ));
    }

    protected function logTransactionWarning(string $message): void
    {
        // SafeLog degrades to error_log() rather than throwing, so a logging
        // failure cannot break the retry loop it is reporting on.
        \Core\Support\SafeLog::warning($message);
    }

    public function inTransaction(): bool
    {
        return $this->resolvePdo('write')->inTransaction();
    }

    protected function transactionWithSavepoint(callable $callback)
    {
        $name = 'sp_' . $this->savepointDepth++;

        $this->executeSavepointStatement('SAVEPOINT ' . $name);

        try {
            $result = $callback($this);
            $this->executeSavepointStatement('RELEASE SAVEPOINT ' . $name);

            return $result;
        } catch (\Throwable $e) {
            $this->executeSavepointStatement('ROLLBACK TO SAVEPOINT ' . $name);

            throw $e;
        } finally {
            $this->savepointDepth--;
        }
    }

    /** Savepoints are not prepared statements, so they bypass the query builder. */
    protected function executeSavepointStatement(string $sql): void
    {
        $this->resolvePdo('write')->exec($sql);
    }

    /**
     * Insert rows from any iterable source in bounded batches.
     *
     * @param iterable<array<string, mixed>> $rows
     */
    public function insertInBatches(iterable $rows, ?callable $progress = null, ?int $batchSize = null): int
    {
        return $this->processIterableWriteBatches(
            $rows,
            $batchSize,
            fn(array $batch): mixed => $this->createBatchedCrudBuilder()->batchInsert($batch),
            'insert',
            $progress
        );
    }

    /**
     * Update rows from any iterable source in bounded batches.
     *
     * @param iterable<array<string, mixed>> $rows
     */
    public function updateInBatches(iterable $rows, ?callable $progress = null, ?int $batchSize = null): int
    {
        return $this->processIterableWriteBatches(
            $rows,
            $batchSize,
            fn(array $batch): mixed => $this->createBatchedCrudBuilder()->batchUpdate($batch),
            'update',
            $progress
        );
    }

    /**
     * Upsert rows from any iterable source in bounded batches.
     *
     * @param iterable<array<string, mixed>> $rows
     */
    public function upsertInBatches(iterable $rows, string|array $uniqueBy = 'id', ?array $updateColumns = null, ?callable $progress = null, ?int $batchSize = null): int
    {
        return $this->processIterableWriteBatches(
            $rows,
            $batchSize,
            fn(array $batch): mixed => $this->createBatchedCrudBuilder()->upsert($batch, $uniqueBy, $updateColumns),
            'upsert',
            $progress
        );
    }

    /**
     * Delete rows by column values from any iterable source in bounded batches.
     *
     * @param iterable<scalar> $values
     */
    public function deleteInBatches(iterable $values, string $column = 'id', ?callable $progress = null, ?int $batchSize = null): int
    {
        $this->validateColumn($column);

        $resolvedBatchSize = $this->normalizeIterableWriteBatchSize($batchSize);
        $buffer = [];
        $processedRows = 0;
        $processedBatches = 0;

        $flush = function () use (&$buffer, &$processedRows, &$processedBatches, $column, $progress): bool {
            if ($buffer === []) {
                return true;
            }

            $chunk = $this->orderKeysForLocking(array_values(array_unique($buffer, SORT_REGULAR)));
            $buffer = [];

            if ($chunk === []) {
                return true;
            }

            $result = $this->createBatchedCrudBuilder()->whereIn($column, $chunk)->delete();
            if ($result === false) {
                throw new \RuntimeException('Bulk delete batch failed.');
            }

            $processedRows += count($chunk);
            $processedBatches++;

            if ($progress !== null) {
                $shouldContinue = $progress([
                    'operation' => 'delete',
                    'processed_rows' => $processedRows,
                    'batches_processed' => $processedBatches,
                    'last_batch_rows' => count($chunk),
                ]);

                if ($shouldContinue === false) {
                    return false;
                }
            }

            return true;
        };

        foreach ($values as $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $buffer[] = $value;

            if (count($buffer) >= $resolvedBatchSize && !$flush()) {
                return $processedRows;
            }
        }

        $flush();

        return $processedRows;
    }

    protected function processIterableWriteBatches(iterable $rows, ?int $batchSize, callable $writeBatch, string $operation, ?callable $progress = null): int
    {
        $resolvedBatchSize = $batchSize !== null ? $this->normalizeIterableWriteBatchSize($batchSize) : null;
        $buffer = [];
        $processedRows = 0;
        $processedBatches = 0;

        $flush = function () use (&$buffer, &$processedRows, &$processedBatches, $writeBatch, $operation, $progress): bool {
            if ($buffer === []) {
                return true;
            }

            $batch = $buffer;
            $buffer = [];

            $result = $writeBatch($batch);
            if (!$this->iterableWriteBatchSucceeded($result)) {
                throw new \RuntimeException('Bulk ' . $operation . ' batch failed.');
            }

            $processedRows += $this->resolveIterableWriteBatchCount($result, count($batch));
            $processedBatches++;

            if ($progress !== null) {
                $shouldContinue = $progress([
                    'operation' => $operation,
                    'processed_rows' => $processedRows,
                    'batches_processed' => $processedBatches,
                    'last_batch_rows' => count($batch),
                ]);

                if ($shouldContinue === false) {
                    return false;
                }
            }

            return true;
        };

        foreach ($rows as $row) {
            if (!is_array($row) || $row === []) {
                continue;
            }

            if ($resolvedBatchSize === null) {
                $resolvedBatchSize = $this->normalizeIterableWriteBatchSize(null, $row);
            }

            $buffer[] = $row;

            if (count($buffer) >= $resolvedBatchSize && !$flush()) {
                return $processedRows;
            }
        }

        $flush();

        return $processedRows;
    }

    protected function normalizeIterableWriteBatchSize(?int $batchSize, ?array $sampleRow = null): int
    {
        $batchSize ??= $this->recommendedIterableWriteBatchSize($sampleRow);

        if ($batchSize < 1) {
            throw new \InvalidArgumentException('Batch size must be greater than zero.');
        }

        return $batchSize;
    }

    /**
     * Recommend an iterable write batch size based on row width.
     *
     * Narrow rows keep the higher default throughput. Wider rows use smaller
     * batches to reduce statement size, memory pressure, and per-batch latency.
     */
    protected function recommendedIterableWriteBatchSize(?array $sampleRow = null): int
    {
        $default = self::DEFAULT_ITERABLE_WRITE_BATCH_SIZE;
        if ($sampleRow === null || $sampleRow === []) {
            return $default;
        }

        $columnCount = count($sampleRow);

        return match (true) {
            $columnCount <= 4 => $default,
            $columnCount <= 12 => min($default, 1000),
            $columnCount <= 24 => min($default, 500),
            default => min($default, 250),
        };
    }

    /**
     * Recommend a read-side chunk size based on row width.
     *
     * Narrow rows keep the caller-requested throughput. Wider rows use smaller
     * chunks to reduce memory pressure, hydration overhead, and response
     * latency while preserving the requested size as an upper bound.
     */
    protected function recommendedReadChunkSize(?array $sampleRow = null, int $requestedSize = 1000): int
    {
        $requestedSize = max(1, $requestedSize);
        if ($sampleRow === null || $sampleRow === []) {
            return $requestedSize;
        }

        $columnCount = count($sampleRow);

        return match (true) {
            $columnCount <= 4 => $requestedSize,
            $columnCount <= 12 => min($requestedSize, 1000),
            $columnCount <= 24 => min($requestedSize, 500),
            default => min($requestedSize, 250),
        };
    }

    protected function iterableWriteBatchSucceeded($result): bool
    {
        if ($result === false) {
            return false;
        }

        if (is_array($result) && isset($result['code']) && (int) $result['code'] >= 400) {
            return false;
        }

        if (is_object($result) && isset($result->code) && (int) $result->code >= 400) {
            return false;
        }

        return true;
    }

    protected function resolveIterableWriteBatchCount($result, int $fallback): int
    {
        if (is_array($result) && isset($result['affected_rows']) && is_numeric($result['affected_rows'])) {
            return (int) $result['affected_rows'];
        }

        if (is_object($result) && isset($result->affected_rows) && is_numeric($result->affected_rows)) {
            return (int) $result->affected_rows;
        }

        return $fallback;
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
        $this->_insertIgnore = false;
        $this->_paginateColumn = [];
        $this->_paginateAllowedSortColumns = [];
        $this->_paginateFilterValue = null;
        $this->sortableColumns = [];
        $this->filterableColumns = [];
        $this->paginateCountCacheNamespace = null;
        $this->paginateCountCacheTtl = 0;
        $this->pendingPaginateCountCacheRemovals = [];
        return $this;
    }

    /**
     * Start a new builder scope for the given table.
     *
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
    /**
     * Single-argument functions permitted in a select list.
     *
     * Anything taking multiple arguments, or a literal, belongs in selectRaw()
     * where the caller is explicitly accepting responsibility for the SQL.
     *
     * @var list<string>
     */
    protected const SELECT_FUNCTIONS = [
        'COUNT', 'SUM', 'AVG', 'MIN', 'MAX',
        'ABS', 'CEIL', 'CEILING', 'FLOOR', 'ROUND', 'SQRT',
        'LENGTH', 'CHAR_LENGTH', 'LOWER', 'UPPER', 'TRIM', 'LTRIM', 'RTRIM',
        'DATE', 'TIME', 'YEAR', 'MONTH', 'DAY', 'HOUR', 'MINUTE', 'SECOND',
        'UNIX_TIMESTAMP',
    ];

    public function select($columns = ['*'])
    {
        if (!is_array($columns)) {
            $columns = $this->splitSelectList((string) $columns);
        }

        $parsed = array_map(
            fn($column): string => $this->parseSelectColumn((string) $column),
            $columns
        );

        // select([]) and select('') mean "everything", not "nothing" — an empty
        // column list would compile to `SELECT  FROM`.
        $this->column = $parsed === [] ? '*' : implode(', ', $parsed);

        return $this;
    }

    /**
     * Split a select list on commas that separate entries.
     *
     * Paren-aware, so `ROUND(x, 2)` arrives at the parser whole and is refused
     * with a message about multi-argument functions rather than being torn into
     * two unintelligible halves.
     *
     * @return list<string>
     */
    protected function splitSelectList(string $list): array
    {
        $entries = [];
        $current = '';
        $depth = 0;
        $length = strlen($list);

        for ($i = 0; $i < $length; $i++) {
            $char = $list[$i];

            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth = max(0, $depth - 1);
            } elseif ($char === ',' && $depth === 0) {
                $entries[] = $current;
                $current = '';
                continue;
            }

            $current .= $char;
        }

        $entries[] = $current;

        return array_values(array_filter(
            array_map('trim', $entries),
            static fn(string $entry): bool => $entry !== ''
        ));
    }

    /**
     * Turn one select-list entry into safely quoted SQL.
     *
     * This used to return the entry untouched whenever it contained a dot, an
     * " as ", or a pair of parentheses — the three shapes that are hardest to
     * validate and the three that matter most. A `fields=` parameter reaching
     * select() could therefore carry
     *
     *     (SELECT password FROM users LIMIT 1) AS x
     *
     * straight into the query: arbitrary reads through a select list, and a
     * SLEEP() away from a blind timing oracle.
     *
     * Each shape is now parsed and rebuilt from validated parts. Anything that
     * does not fit is refused rather than guessed at, and the message names
     * selectRaw(), which is where deliberately raw SQL belongs.
     */
    protected function parseSelectColumn(string $column): string
    {
        $column = trim($column);

        if ($column === '' || $column === '*') {
            return '*';
        }

        // `<expression> AS <alias>` — the alias is an identifier like any other.
        if (preg_match('/^(.*?)\s+AS\s+`?([A-Za-z_][A-Za-z0-9_]*)`?$/i', $column, $parts) === 1) {
            return $this->parseSelectColumn($parts[1]) . ' AS ' . $this->wrapIdentifier($parts[2]);
        }

        // `FUNC(...)`, restricted to one argument over a column or a wildcard.
        if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s*\((.*)\)$/s', $column, $parts) === 1) {
            $function = strtoupper($parts[1]);

            if (!in_array($function, self::SELECT_FUNCTIONS, true)) {
                throw new \InvalidArgumentException(sprintf(
                    'select(): %s() is not an allowed function. Use selectRaw() for expressions.',
                    $parts[1]
                ));
            }

            $argument = trim($parts[2]);

            // One argument only. Naming the function in the message matters:
            // "total, 2 is not a column name" tells the caller nothing about
            // which call they need to move to selectRaw().
            if (count($this->splitSelectList($argument)) > 1) {
                throw new \InvalidArgumentException(sprintf(
                    'select(): %s() takes more than one argument here. Use selectRaw() for expressions.',
                    $function
                ));
            }

            $distinct = '';

            if (preg_match('/^DISTINCT\s+(.*)$/i', $argument, $inner) === 1) {
                $distinct = 'DISTINCT ';
                $argument = trim($inner[1]);
            }

            return $function . '(' . $distinct . $this->parseSelectColumn($argument) . ')';
        }

        // `table.*`
        if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\.\*$/', $column, $parts) === 1) {
            return $this->wrapIdentifier($parts[1]) . '.*';
        }

        // A bare or qualified identifier. The pattern is the boundary; quoting
        // is the grammar's, so a second engine changes one class not this one.
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)*$/', $column) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'select(): "%s" is not a column name. Use selectRaw() for expressions.',
                $column
            ));
        }

        // Unqualified names are scoped to the current table so a join cannot
        // make them ambiguous.
        if (!str_contains($column, '.') && !empty($this->table)) {
            $column = $this->table . '.' . $column;
        }

        return $this->wrapIdentifier($column);
    }

    /**
     * Set the SELECT clause to a raw SQL expression, with optional positional bindings.
     *
     * Guards against null bytes, stacked queries, and comment injection.
     * SQL keywords such as SELECT, COUNT, and SUM are intentionally permitted
     * because aggregate expressions and correlated subqueries are valid here.
     *
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
     * @return $this
     */
    abstract public function whereDate($column, $operator, $value);

    /**
     * Add a driver-specific OR date predicate.
     *
     * @return $this
     */
    abstract public function orWhereDate($column, $operator, $value);

    /**
     * Add a driver-specific day predicate.
     *
     * @return $this
     */
    abstract public function whereDay($column, $operator, $value);

    /**
     * Add a driver-specific OR day predicate.
     *
     * @return $this
     */
    abstract public function orWhereDay($column, $operator, $value);

    /**
     * Add a driver-specific month predicate.
     *
     * @return $this
     */
    abstract public function whereMonth($column, $operator, $value);

    /**
     * Add a driver-specific OR month predicate.
     *
     * @return $this
     */
    abstract public function orWhereMonth($column, $operator, $value);

    /**
     * Add a driver-specific year predicate.
     *
     * @return $this
     */
    abstract public function whereYear($column, $operator, $value);

    /**
     * Add a driver-specific time predicate.
     *
     * @return $this
     */
    abstract public function whereTime($column, $operator, $value);

    /**
     * Add a driver-specific OR time predicate.
     *
     * @return $this
     */
    abstract public function orWhereTime($column, $operator, $value);

    /**
     * Add a driver-specific JSON contains predicate.
     *
     * @return $this
     */
    abstract public function whereJsonContains($columnName, $jsonPath, $value);

    /**
     * Alias for insertOrUpdate() - matches Laravel's updateOrInsert() naming.
     *
     * @return mixed
     */
    public function updateOrInsert(array $conditions, array $data)
    {
        return $this->insertOrUpdate($conditions, $data);
    }

    /** @return $this */
    abstract public function limit($limit);

    /** @return $this */
    abstract public function offset($offset);

    /** @return $this */
    public function skip($offset)
    {
        return $this->offset($offset);
    }

    /** @return $this */
    public function take($limit)
    {
        return $this->limit($limit);
    }

    /**
     * Simple pagination helper - set offset and limit for a page
     *
     * @param int $page Page number (1-indexed)
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
        if (empty($this->table)) {
            throw new \InvalidArgumentException('Please specify the table.');
        }

        // Build the basic SELECT clause with fields
        $this->_query = "SELECT " . ($this->distinct ? "DISTINCT " : "") . ($this->column === '*' ? '*' : $this->column) . " FROM ";

        $this->_query .= $this->wrapCurrentTable();

        // Add index hints if specified (MySQL optimization)
        if (!empty($this->indexHints)) {
            foreach ($this->indexHints as $type => $indexes) {
                $indexList = implode(', ', array_map(function($idx) { return "`$idx`"; }, $indexes));
                $this->_query .= " $type INDEX ($indexList)";
            }
        }

        if ($this->joins) {
            $this->_query .= $this->joins;
        }

        if ($this->where) {
            $this->_query .= " WHERE " . $this->where;
        }

        if ($this->groupBy) {
            $this->_query .= " GROUP BY " . $this->groupBy;
        }

        if ($this->having) {
            $having = implode(' AND ', $this->having);
            $this->_query .= " HAVING " . $having;
        }

        if ($this->orderBy) {
            $orderBy = implode(', ', $this->orderBy);
            $this->_query .= " ORDER BY " . $orderBy;
        }

        if (!empty($this->unions)) {
            foreach ($this->unions as $union) {
                $this->_query .= $union['all'] ? ' UNION ALL ' : ' UNION ';
                $this->_query .= $union['query'];
            }
        }

        if ($this->limit) {
            if (!isset($this->listDatabaseDriverSupport[$this->driver])) {
                throw new \Exception("LIMIT clause not supported for driver: " . $this->driver);
            }

            $this->_query .= $this->limit;
        }

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
     * @return mixed
     */
    public function selectQuery($statement, $binds = null, $fetchType = 'get')
    {
        if (empty($statement)) {
            throw new \InvalidArgumentException('Query statement cannot be null in `selectQuery()` function.');
        }

        if (strtoupper(strtok(trim($statement), " \t\n\r")) !== 'SELECT') {
            throw new \InvalidArgumentException('Only SELECT statements are allowed in `selectQuery()` function.');
        }

        try {
            $this->connectForOperation('read');

            $stmt = $this->_prepareStatement($statement);

            if (!empty($binds)) {
                $this->_bindParams($stmt, $binds);
            }

            $stmt->execute();

            switch ($fetchType) {
                case 'fetch':
                    // Fetch only the first result as an associative array
                    $result = $stmt->fetch(\PDO::FETCH_ASSOC);
                    break;
                default:
                    $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                    break;
            }

            $stmt->closeCursor();
            unset($stmt);
        } catch (\PDOException $e) {
            $this->logDatabaseError($e, __FUNCTION__);
            throw $e; // Re-throw the exception
        }

        // Reset safeOutput
        $this->safeOutput(false);

        return $this->_returnResult($result);
    }

    /**
     * Register a raw SQL statement for later execution through execute().
     *
     * @return $this
     */
    public function query($statement, $bindParams = [])
    {
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
        $operationType = in_array($queryType, ['SELECT', 'SHOW', 'DESCRIBE', 'EXPLAIN'], true) ? 'read' : 'write';

        try {
            $this->connectForOperation($operationType);

            $stmt = $this->_prepareStatement($this->_query);

            if (!empty($this->_binds)) {
                $this->_bindParams($stmt, $this->_binds);
            }

            $this->_captureExecutedQuery($this->_binds);

            $success = $stmt->execute();

            // Handle different query types
            if ($queryType === 'SELECT' || $queryType === 'SHOW' || $queryType === 'DESCRIBE' || $queryType === 'EXPLAIN') {
                // For SELECT and other data-returning queries, return the fetched results
                $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);

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
            $this->logDatabaseError($e, __FUNCTION__);
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
     * @return mixed
     */
    public function get($table = null)
    {
        $result = null;

        if (!empty($table)) {
            $this->table = $table;
        }

        if (!$this->_isRawQuery) {
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
            $cacheKey = QueryCache::generateKey($this->_query, $this->getSelectQueryBindings(), $this->connectionName, $this->_tablesForCacheKey());
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

            $this->_buildSelectQuery();
        }

        $cachePrefix = 'fetch_';
        if (!empty($this->cacheFile)) {
            $result = $this->_getCacheData($cachePrefix . $this->cacheFile);
        }

        $queryCacheEnabled = $this->shouldUseQueryCache();

        // Check QueryCache if enabled and old cache system not used
        if (empty($result) && $queryCacheEnabled) {
            $cacheKey = QueryCache::generateKey($this->_query, $this->getSelectQueryBindings(), $this->connectionName, $this->_tablesForCacheKey());
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

        return $this->_returnResult($result);
    }

    /**
     * Determine whether QueryCache should participate in the current read.
     */
    protected function shouldUseQueryCache(): bool
    {
        return empty($this->cacheFile) && !$this->suppressQueryCache && QueryCache::isEnabled();
    }

    /**
     * Prepare, bind, and execute a SELECT statement for get() or fetch().
     *
     * @return mixed
     */
    protected function executeSelectOperation(string $fetchType, string $methodName)
    {
        if ($this->enableProfiling) {
            $this->_startProfiler($methodName);
        }

        $this->connectForOperation('read');

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
            $this->logDatabaseError($e, $methodName);
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
            $_temp_queryCacheKey = $cacheKey ?? QueryCache::generateKey($this->_query, $this->getSelectQueryBindings(), $this->connectionName, $this->_tablesForCacheKey());
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
     * @return int
     */
    abstract public function count($table = null);

    /**
     * Determine whether at least one row matches the current builder state.
     *
     * @return bool
     */
    abstract public function exists($table = null);

    /**
     * Determine if no records exist
     *
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

    /** Determine whether an explicit ORDER BY remains compatible with ascending keyset scans. */
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
     * Skip rows another transaction already holds instead of waiting for them.
     *
     * This is what turns a table into a work queue: N workers can each claim a
     * different batch without serialising on the first locked row. Without it a
     * second worker blocks until the first commits, so adding workers adds no
     * throughput. The queue worker already used it through hand-written SQL;
     * this is the builder form.
     *
     * @return $this
     */
    public function skipLocked()
    {
        if (!str_contains((string) $this->_lock, 'FOR UPDATE')) {
            throw new \LogicException('skipLocked() applies to lockForUpdate(); call it first.');
        }

        // Silently ignored where unsupported: the query still returns the right
        // rows, it just waits for them. Emitting the clause anyway is a syntax
        // error, and refusing outright would break older MySQL for no reason.
        if ($this->grammar()->supportsSkipLocked()) {
            $this->_lock = 'FOR UPDATE SKIP LOCKED';
        }

        return $this;
    }

    /**
     * Fail immediately rather than wait when a row is already locked.
     *
     * The opposite trade to skipLocked(): use it where a caller would rather see
     * an error now than hold a request open for the lock timeout.
     *
     * @return $this
     */
    public function noWait()
    {
        if (!str_contains((string) $this->_lock, 'FOR UPDATE')) {
            throw new \LogicException('noWait() applies to lockForUpdate(); call it first.');
        }

        $this->_lock = 'FOR UPDATE NOWAIT';

        return $this;
    }

    /**
     * Insert a row and return the lastInsertId directly.
     *
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
            return $this->resolvePdo('write')->lastInsertId($sequence);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * INSERT INTO ... SELECT ... from a sub-query builder.
     *
     * Example:
     * $db->table('archive_users')->insertUsing(
     * ['id', 'name', 'email'],
     * );
     *
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

        $columnList = implode(', ', array_map(
            fn ($c): string => $this->wrapIdentifier((string) $c),
            $columns
        ));

        $table = $this->wrapCurrentTable();

        $sql = "INSERT INTO {$table} ({$columnList}) {$selectSql}";

        unset($sub);

        // Execute via query() so profiling/binds are handled consistently
        return $this->query($sql, $selectBinds)->execute();
    }

    /**
     * Execute a DataTables-style paginated query with exact total counts.
     *
     * @return mixed
     */
    /**
     * Page without counting the whole table.
     *
     * paginate() runs a COUNT(*) so it can report a total and a last page. On a
     * large table that count is usually most of the query cost, and a
     * "next / previous" UI never displays the number it paid for.
     *
     * This fetches one row more than asked for instead: if the extra row comes
     * back there is another page, and it is discarded. One query, no count.
     *
     * Use paginate() when the UI shows numbered pages or a total. Use this when
     * it shows next and previous.
     *
     * @return array{data: array<int, mixed>, per_page: int, current_page: int, has_more: bool, from: int, to: int}
     */
    public function simplePaginate(int $perPage = 15, int $page = 1): array
    {
        $perPage = max(1, $perPage);
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        // The +1 is the probe. It never reaches the caller.
        $rows = $this->limit($perPage + 1)->offset($offset)->get();

        if (!is_array($rows)) {
            $rows = $rows === null ? [] : [$rows];
        }

        $hasMore = count($rows) > $perPage;
        if ($hasMore) {
            array_pop($rows);
        }

        $count = count($rows);

        return [
            'data' => array_values($rows),
            'per_page' => $perPage,
            'current_page' => $page,
            'has_more' => $hasMore,
            'from' => $count === 0 ? 0 : $offset + 1,
            'to' => $count === 0 ? 0 : $offset + $count,
        ];
    }

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

            // Skip filtering for raw queries when columns cannot be determined
            if (!$this->_isRawQuery) {
                $this->applyPaginateSearchFilter();
            }

            if (!$this->_isRawQuery) {
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

            $this->_startProfiler(__FUNCTION__);

            // Execute the main query
            $stmt = $this->_prepareStatement($this->_query);

            $bindings = $this->getSelectQueryBindings();

            if (!empty($bindings)) {
                $this->_bindParams($stmt, $bindings);
            }

            $this->_captureExecutedQuery($bindings);

            $stmt->execute();

            $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            if (method_exists($stmt, 'closeCursor')) {
                $stmt->closeCursor();
            }
            unset($stmt);

            $this->_stopProfiler();

            $paginate = [
                'draw' => $draw,
                'recordsTotal' => $totalRecords,
                'recordsFiltered' => $totalFiltered,
                'data' => $this->_safeOutputSanitize($result) ?? null,
            ];
        } catch (\PDOException $e) {
            $this->logDatabaseError($e, __FUNCTION__);
            throw $e; // Re-throw the exception
        }

        // Save connection name and relations temporarily
        $_temp_connection = $this->connectionName;
        $_temp_relations = $this->relations;

        // Assign temporary return type before reset
        $_temp_returnType = $this->returnType;

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
     * @return $this
     */
    public function setPaginateFilterColumn($column = [])
    {
        $this->_paginateColumn = is_array($column) ? $column : [];
        return $this;
    }

    /**
     * Neutralise LIKE wildcards so a search for "50%" matches the literal text
     * instead of everything. Backslash first, or it would escape the escapes.
     *
     * Relies on the default LIKE escape character; a server running with
     * sql_mode=NO_BACKSLASH_ESCAPES would need an explicit ESCAPE clause.
     */
    protected function escapeLikeWildcards(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * OR a LIKE across the columns declared by setPaginateFilterColumn().
     *
     * @throws \RuntimeException when a search term arrives with no declared columns
     */
    protected function applyPaginateSearchFilter(): void
    {
        if (empty($this->_paginateFilterValue)) {
            return;
        }

        $columns = array_values(array_filter(array_map('trim', $this->_paginateColumn)));

        if (empty($columns)) {
            throw new \RuntimeException(
                'paginate() received a search value but no searchable columns. Call '
                . 'setPaginateFilterColumn([...]) with the indexed columns you want searched. '
                . 'The previous fallback searched every column returned by DESCRIBE with a '
                . 'leading-wildcard LIKE, which cannot use an index.'
            );
        }

        $value = '%' . $this->escapeLikeWildcards($this->_paginateFilterValue) . '%';

        $this->where(function ($query) use ($columns, $value) {
            foreach ($columns as $index => $column) {
                $method = $index === 0 ? 'where' : 'orWhere';
                $query->{$method}($column, 'LIKE', $value);
            }
        });
    }

    /**
     * Restrict client-provided sort indexes to a safe list of columns.
     *
     * @return $this
     */
    public function setAllowedSortColumns($columns = [])
    {
        $this->_paginateAllowedSortColumns = is_array($columns) ? array_values($columns) : [];
        return $this;
    }

    /**
     * Restrict dynamic ORDER BY columns to a positive allowlist.
     *
     * @param array<string> $columns
     */
    public function setSortableColumns(array $columns = []): static
    {
        $this->sortableColumns = array_values(array_filter($columns, 'is_string'));
        return $this;
    }

    /**
     * Return the active ORDER BY allowlist.
     *
     * @return array<string>
     */
    public function getSortableColumns(): array
    {
        if ($this->sortableColumns !== []) {
            return $this->sortableColumns;
        }

        return is_array($this->_paginateAllowedSortColumns) ? array_values($this->_paginateAllowedSortColumns) : [];
    }

    /**
     * Restrict dynamic WHERE columns to a positive allowlist.
     *
     * @param array<string> $columns
     */
    public function setFilterableColumns(array $columns = []): static
    {
        $this->filterableColumns = array_values(array_filter($columns, 'is_string'));
        return $this;
    }

    /**
     * Return the active WHERE allowlist.
     *
     * @return array<string>
     */
    public function getFilterableColumns(): array
    {
        return $this->filterableColumns;
    }

    /**
     * Translate a DataTables request payload into paginate() arguments.
     *
     * @return mixed
     */
    public function paginate_ajax($dataPost)
    {
        $dataPost = is_array($dataPost) ? $dataPost : [];

        $draw = max(1, (int) ($dataPost['draw'] ?? 1));
        $start = max(0, (int) ($dataPost['start'] ?? 0));

        $configuredMaxLimit = function_exists('config')
            ? (int) config('db.pagination.max_limit', self::MAX_PAGINATE_LIMIT)
            : self::MAX_PAGINATE_LIMIT;
        $configuredDefaultLimit = function_exists('config')
            ? (int) config('db.pagination.default_limit', self::DEFAULT_PAGINATE_LIMIT)
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

        $sortColumns = !empty($this->_paginateAllowedSortColumns)
            ? $this->_paginateAllowedSortColumns
            : $this->_paginateColumn;

        // No declared columns means no ordering. Deriving them from DESCRIBE would let a
        // client sort by any column in the table, including unindexed ones.
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
     * @return mixed
     */
    public function insert($data)
    {
        // Default response
        $response = ['code' => 400, 'message' => 'Failed to create data', 'action' => 'create'];

        if ($this->_isRawQuery) {
            throw new \InvalidArgumentException('Raw insert SQL statements are not allowed in insert(). Please use insert() function without any query or condition.');
        }

        if (empty($data) || !is_array($data)) {
            throw new \InvalidArgumentException('Invalid column data. Must be an associative array.');
        }

        if (empty($this->table)) {
            throw new \InvalidArgumentException('Please specify the table.');
        }

        $this->_startProfiler(__FUNCTION__);

        $sanitizeData = $this->sanitizeColumn($data);

        $this->_buildInsertQuery($sanitizeData);

        $this->connectForOperation('write');

        $stmt = $this->_prepareStatement($this->_query);

        $this->_bindParams($stmt, array_values($sanitizeData));

        try {
            $this->_captureExecutedQuery($this->_binds);

            $success = $stmt->execute();

            // Get the number of affected rows
            $affectedRows = $stmt->rowCount();

            // Get the last inserted ID
            $lastInsertId = $success ? $this->resolvePdo('write')->lastInsertId() : null;

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
            $this->logDatabaseError($e, __FUNCTION__);
            throw $e; // Re-throw the exception
        }

        $this->_stopProfiler();

        $this->reset();

        return $this->_returnResult($response) ?? false;
    }

    /**
     * Compile an INSERT statement for the provided associative row payload.
     *
     * @return $this
     */
    protected function _buildInsertQuery($data)
    {
        // Check if data is empty or not an associative array (key-value pairs)
        if (empty($data) || !is_array($data)) {
            throw new \InvalidArgumentException('Invalid column data. Must be an associative array with column names as keys.');
        }

        $columns = implode(', ', array_map(
            fn ($column): string => $this->wrapIdentifier((string) $column),
            array_keys($data)
        ));

        $placeholders = implode(', ', array_fill(0, count($data), '?'));

        $grammar = $this->grammar();

        // INSERT IGNORE is a statement modifier on MySQL and a trailing
        // ON CONFLICT DO NOTHING on PostgreSQL, so the grammar decides both the
        // word and where it goes.
        $modifier = $this->_insertIgnore && method_exists($grammar, 'insertIgnoreModifier')
            ? ' ' . $grammar->insertIgnoreModifier()
            : '';

        $this->_query = 'INSERT' . $modifier . ' INTO '
            . $this->wrapCurrentTable() . " ({$columns}) VALUES ({$placeholders})";

        if ($this->_insertIgnore && $modifier === '') {
            $clause = $grammar->compileInsertIgnoreClause();
            if ($clause === null) {
                throw new \RuntimeException(
                    'insertOrIgnore() is not supported on this database engine; '
                    . 'catch the duplicate-key error or use updateOrInsert() instead.'
                );
            }

            $this->_query .= ' ' . $clause;
        }

        return $this;
    }

    /**
     * Insert a row, doing nothing if it collides with a unique constraint.
     *
     * The alternative was catching SQLSTATE 23000 at the call site, which also
     * swallows the foreign-key and not-null violations that share that class —
     * so a genuine data bug was silently discarded as "already exists".
     *
     * Returns the same shape as insert(); `code` is 201 when a row was written
     * and 200 when one already existed.
     *
     * @param  array<string, mixed> $data
     * @return mixed
     */
    public function insertOrIgnore($data)
    {
        $this->_insertIgnore = true;

        try {
            $result = $this->insert($data);
        } finally {
            $this->_insertIgnore = false;
        }

        // rowCount() is 0 when the row was skipped, and insert() maps that to a
        // 422 "failed". Skipping is the documented outcome here, not a failure.
        if (is_array($result) && ($result['code'] ?? null) === 422) {
            $result['code'] = 200;
            $result['message'] = 'Row already exists; insert ignored';
        }

        return $result;
    }

    /**
     * Update an existing row or insert a new one inside a transaction.
     *
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
            $this->logDatabaseError($e, __FUNCTION__);
            $response['message'] = $e->getMessage();
        }

        $this->reset();
        return $this->_returnResult($response);
    }

    /**
     * Create a new record or find existing one
     *
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
            $this->logDatabaseError($e, __FUNCTION__);
            return ['code' => 422, 'message' => $e->getMessage(), 'action' => 'firstOrCreate'];
        }
    }

    /**
     * Get the first record matching conditions or return an unsaved attribute array.
     *
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

    /** @return array */
    public function increment($column, $amount = 1, $extra = [])
    {
        if (empty($this->table)) {
            throw new \InvalidArgumentException('Please specify the table.');
        }

        $this->validateColumn($column);
        $amount = abs((int)$amount);
        if ($amount < 1) $amount = 1;

        $this->_startProfiler(__FUNCTION__);

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

        $this->connectForOperation('write');
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
            $this->logDatabaseError($e, __FUNCTION__);
            throw $e;
        }

        $this->_stopProfiler();
        $this->reset();

        return $this->_returnResult($response);
    }

    /** @return array */
    public function decrement($column, $amount = 1, $extra = [])
    {
        if (empty($this->table)) {
            throw new \InvalidArgumentException('Please specify the table.');
        }

        $this->validateColumn($column);
        $amount = abs((int)$amount);
        if ($amount < 1) $amount = 1;

        $this->_startProfiler(__FUNCTION__);

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

        $this->connectForOperation('write');
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
            $this->logDatabaseError($e, __FUNCTION__);
            throw $e;
        }

        $this->_stopProfiler();
        $this->reset();

        return $this->_returnResult($response);
    }

    /**
     * Update matching rows in the current table.
     *
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

        if (empty($data) || !is_array($data)) {
            throw new \InvalidArgumentException('Invalid column data. Must be an associative array.');
        }

        if (empty($this->table)) {
            throw new \InvalidArgumentException('Please specify the table.');
        }

        $this->_startProfiler(__FUNCTION__);

        $sanitizeData = $this->sanitizeColumn($data);

        $this->_buildUpdateQuery($sanitizeData);

        $this->connectForOperation('write');

        $stmt = $this->_prepareStatement($this->_query);

        $this->_bindParams($stmt, array_merge(array_values($sanitizeData), $this->_binds));

        try {
            $this->_captureExecutedQuery($this->_binds);

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
            $this->logDatabaseError($e, __FUNCTION__);
            throw $e; // Re-throw the exception
        }

        $this->_stopProfiler();

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

        if ($this->where) {
            $this->_query .= " WHERE " . $this->where;
        }

        return $this;
    }

    # SOFT DELETE / DELETE / TRUNCATE DATA OPERATION

    /**
     * Soft-delete by updating one or more columns instead of removing the row.
     *
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
            $this->logDatabaseError($e, __FUNCTION__);
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
            $this->logDatabaseError($e, __FUNCTION__);
            throw $e; // Re-throw the exception
        }

        $this->_stopProfiler();

        $this->reset();

        return $this->_returnResult($response) ?? false;
    }

    /**
     * Force a hard delete even when the table supports soft deletes.
     *
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
            $this->logDatabaseError($e, __FUNCTION__);
            throw $e;
        }

        $this->_stopProfiler();
        $this->reset();

        return $this->_returnResult($response) ?? false;
    }

    /**
     * Restore a soft-deleted row by clearing the soft-delete marker column.
     *
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

        if ($this->where) {
            $this->_query .= " WHERE " . $this->where;
        }

        return $this;
    }

    /**
     * Truncate the current table or an explicitly provided table name.
     *
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


        // SQLite has no TRUNCATE statement at all, so the spelling is the
        // grammar's to choose rather than a constant here.
        $this->_query = $this->grammar()->compileTruncate($quotedTable);

        $this->_startProfiler(__FUNCTION__);

        try {
            $stmt = $this->_prepareStatement($this->_query);

            $this->_captureExecutedQuery([]);

            $success = $stmt->execute();

            // Return information about the truncate operation
            $response = [
                'code' => $success ? 200 : 422,
                'message' => $success ? "Truncated {$tableTruncate} successfully" : "Failed to truncate table {$tableTruncate}",
                'action' => 'truncate'
            ];
        } catch (\PDOException $e) {
            $this->logDatabaseError($e, __FUNCTION__);
            throw $e; // Re-throw the exception
        }

        $this->_stopProfiler();

        $this->reset();

        return $this->_returnResult($response) ?? false;
    }

    # BATCH INSERT/UPDATE OPERATION

    /**
     * Insert multiple rows in a single driver-optimized operation.
     *
     * @return mixed
     */
    abstract public function batchInsert($data);

    /**
     * Update multiple rows in a single driver-optimized operation.
     *
     * @return mixed
     */
    abstract public function batchUpdate($data);

    /**
     * Perform a bulk upsert using a unique key definition.
     *
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
        $this->requestedReturnType = 'array';

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
        $this->requestedReturnType = 'object';

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
        $this->requestedReturnType = 'json';

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
     * @return mixed The converted data.
     */
    protected function _returnResult($data)
    {
        // Read and clear before the early return, or a format asked for on an
        // empty result would leak into the next query on this builder.
        $format = $this->requestedReturnType ?? $this->returnType;
        $this->requestedReturnType = null;

        if (empty($data)) {
            return $data;
        }

        switch ($format) {
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
        $pdo = $this->resolvePdo('write');
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $this->markStickyWrite($this->connectionName);
        }
    }

    /**
     * Commit a transaction.
     *
     * @return void
     */
    public function commit()
    {
        $pdo = $this->resolvePdo('write');
        if ($pdo->inTransaction()) {
            $pdo->commit();
        }
    }

    /**
     * Rollback a transaction.
     *
     * @return void
     */
    public function rollback()
    {
        $pdo = $this->resolvePdo('write');
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }

    /**
     * Bind positional or named parameters onto a prepared statement.
     *
     * @return void
     */
    protected function _bindParams(\PDOStatement $stmt, array $binds)
    {
        $query = $stmt->queryString;
        $trackProfilerBinds = $this->enableProfiling && isset($this->_profiler['profiling'][$this->_profilerActive]);

        // Fast-path: most queries use positional parameters
        $hasPositional = strpos($query, '?') !== false;

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
     * @return string
     */
    protected function _generateFullQuery($query, $binds = null, bool $storeInProfiler = true)
    {
        if (!empty($binds)) {
            $hasPositional = strpos($query, '?') !== false;
            $hasNamed = preg_match('/:\w+/', $query);

            foreach ($binds as $key => $value) {
                if ($value === null) {
                    $quotedValue = 'NULL';
                } elseif (is_numeric($value)) {
                    $quotedValue = $value;
                } elseif (is_string($value)) {
                    $quotedValue = $this->quoteDebugBinding($query, $value);
                } else {
                    $quotedValue = $this->quoteDebugBinding($query, (string) $value);
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

    protected function quoteDebugBinding(string $query, string $value): string
    {
        try {
            $quoted = $this->resolvePdo($this->isReadOnlyStatement($query) ? 'read' : 'write')->quote($value, \PDO::PARAM_STR);
            if ($quoted !== false) {
                return $quoted;
            }
        } catch (\Throwable) {
            // Fall back to local escaping for diagnostic-only query previews.
        }

        return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $value) . "'";
    }

    /**
     * Expands asterisks (*) in the SELECT clause to include all table columns.
     * Optimized: only runs regex when the query actually contains a standalone asterisk.
     *
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
     * Cached per request to avoid repeated DESCRIBE queries.
     *
     * @return array List of column names, or empty array on error.
     */
    protected function getTableColumns()
    {
        // Return empty array if table is not set (e.g., when using raw queries)
        if (empty($this->table)) {
            return [];
        }

        $cacheKey = ($this->connectionName ?? 'default') . '.' . ($this->schema ?? '') . '.' . $this->table;

        if (isset(self::$_tableColumnsCache[$cacheKey])) {
            return self::$_tableColumnsCache[$cacheKey];
        }

        try {
            // DESCRIBE is MySQL-only, and interpolated the table name into the
            // SQL because it cannot be bound. The grammar's listing is a prepared
            // statement against information_schema, which every engine has.
            $listing = $this->grammar()->compileColumnListing(
                (string) $this->table,
                (string) ($this->schema ?? '')
            );

            $stmt = $this->_prepareStatement($listing['sql']);
            $stmt->execute($listing['bindings']);
            $columns = $stmt->fetchAll(\PDO::FETCH_COLUMN);

            self::$_tableColumnsCache[$cacheKey] = $columns;
        } catch (\PDOException $e) {
            $this->logDatabaseError($e, __FUNCTION__);
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

            $grammar = $this->grammar();

            $stmt = $this->_prepareStatement($grammar->compileAnalyze($this->wrapCurrentTable()));
            $stmt->execute();

            /*
            | Only MySQL reports the outcome as a row. Elsewhere ANALYZE
            | returns nothing, and reading Msg_text off an empty result made
            | a successful analyze report false.
            */
            if (!$grammar->analyzeReturnsStatusRows()) {
                return true;
            }

            $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            return isset($result[0]['Msg_text']) && strtolower($result[0]['Msg_text']) === 'ok';
        } catch (\PDOException $e) {
            $this->logDatabaseError($e, __FUNCTION__);
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
    /**
     * Whether the server cancelled the statement for exceeding its time limit.
     *
     * Distinct from a deadlock: retrying will not help, because the query will
     * take just as long the second time.
     */
    public function isStatementTimeout(\Throwable $e): bool
    {
        for ($error = $e; $error !== null; $error = $error->getPrevious()) {
            if (!$error instanceof \PDOException) {
                continue;
            }

            // SQLSTATE first: 57014 (query_canceled) is how PostgreSQL and several
            // other engines report this, and it needs no driver-specific table.
            if ((string) ($error->errorInfo[0] ?? '') === '57014') {
                return true;
            }

            $driverCode = (int) ($error->errorInfo[1] ?? 0);

            if (in_array($driverCode, self::STATEMENT_TIMEOUT_CODES, true)) {
                return true;
            }

            // Then whatever the connected engine uses, so adding a driver is a
            // TimeoutDialect entry rather than an edit here.
            if ($this->activeTimeoutDialect()?->isTimeoutCode($driverCode) === true) {
                return true;
            }
        }

        return false;
    }

    /** The timeout dialect for the live connection, or null if not connected. */
    protected function activeTimeoutDialect(): ?TimeoutDialect
    {
        $pdo = $this->pdo[$this->connectionName] ?? null;

        return $pdo instanceof \PDO ? $this->timeoutDialect($pdo) : null;
    }

    protected function configuredStatementTimeoutMs(): int
    {
        if (!function_exists('config')) {
            return 0;
        }

        return max(0, (int) config('db.performance.timeouts.statement_timeout_ms', 0));
    }

    protected function logDatabaseError(
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

            // A statement timeout reads as an unexplained failure a few seconds
            // into a query. Naming the setting that killed it turns "the app
            // errors sometimes" into a one-line fix.
            if ($this->isStatementTimeout($e)) {
                $formattedMessage .= sprintf(
                    ' — the server cancelled this statement after %dms because it exceeded'
                    . ' db.performance.timeouts.statement_timeout_ms. Either optimise the query'
                    . ' (check `php myth db:slow`), stream it with chunkById()/cursor(), or raise'
                    . ' DB_STATEMENT_TIMEOUT_MS for this workload.',
                    $this->configuredStatementTimeoutMs()
                );
            }

            // Extract PDO specific information if available
            $pdoErrorInfo = null;
            if ($e instanceof \PDOException && isset($e->errorInfo)) {
                $pdoErrorInfo = [
                    'sqlstate' => $e->errorInfo[0] ?? null,
                    'driver_code' => $e->errorInfo[1] ?? null,
                    'driver_message' => $e->errorInfo[2] ?? null,
                ];
            }

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
                    Logger::instance($dbLogDir . 'error.log')->log_error("Critical Database Error: " . $this->_error['message']);
                }
            } catch (\Throwable $logException) {
                // Fallback to system error log if custom logger fails
                $dbLogDir = (defined('ROOT_DIR') ? ROOT_DIR : dirname(__DIR__, 3) . DIRECTORY_SEPARATOR) . 'logs' . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR;
                if (!is_dir($dbLogDir)) { @mkdir($dbLogDir, 0775, true); }
                Logger::instance($dbLogDir . 'error.log')->log_error("Database error logging failed: " . $logException->getMessage());
                Logger::instance($dbLogDir . 'error.log')->log_error("Original error: " . $e->getMessage());
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
            Logger::instance($dbLogDir . 'error.log')->log_error("Database error processing failed: " . $loggingError->getMessage());
            Logger::instance($dbLogDir . 'error.log')->log_error("Original database error: " . $e->getMessage());

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
     * @return \PDOStatement
     */
    protected function _prepareStatement($query)
    {
        $operation = $this->isReadOnlyStatement($query) ? 'read' : 'write';

        return StatementCache::get(
            $this->resolvePdo($operation),
            $query,
            $this->resolveConnectionName($operation)
        );
    }

    protected function connectForOperation(string $operation): void
    {
        $connectionName = $this->resolveConnectionName($operation);

        if (isset($this->pdo[$connectionName]) && $this->pdo[$connectionName] instanceof \PDO) {
            return;
        }

        if (!isset($this->config[$connectionName]) && isset($this->pdo[$this->connectionName]) && $this->pdo[$this->connectionName] instanceof \PDO) {
            return;
        }

        $this->connect($connectionName);
    }

    protected function resolvePdo(string $operation = 'write'): \PDO
    {
        $connectionName = $this->resolveConnectionName($operation);
        if (!isset($this->pdo[$connectionName]) || !$this->pdo[$connectionName] instanceof \PDO) {
            if (isset($this->pdo[$this->connectionName]) && $this->pdo[$this->connectionName] instanceof \PDO && !isset($this->config[$connectionName])) {
                return $this->pdo[$this->connectionName];
            }

            $this->connect($connectionName);
        }

        return $this->pdo[$connectionName];
    }

    protected function resolveConnectionName(string $operation = 'write'): string
    {
        $baseConnection = $this->baseConnectionName($this->connectionName);
        $routing = $this->readWriteRouting[$baseConnection] ?? null;

        if (!is_array($routing)) {
            return $this->connectionName;
        }

        if ($operation === 'read') {
            if (($routing['sticky'] ?? true) && ($this->stickyWriteState[$baseConnection] ?? false) === true) {
                return (string) ($routing['write'] ?? $this->connectionName);
            }

            $reads = (array) ($routing['read'] ?? []);
            if ($reads !== []) {
                return (string) $reads[array_rand($reads)];
            }
        }

        return (string) ($routing['write'] ?? $this->connectionName);
    }

    protected function markStickyWrite(string $connectionName): void
    {
        $this->stickyWriteState[$this->baseConnectionName($connectionName)] = true;
    }

    protected function baseConnectionName(string $connectionName): string
    {
        $normalized = strtolower(trim($connectionName));
        if ($normalized === '') {
            return 'default';
        }

        if (str_contains($normalized, '::')) {
            return (string) strstr($normalized, '::', true);
        }

        return $normalized;
    }

    protected function isReadOnlyStatement(?string $statement): bool
    {
        $statement = trim((string) $statement);
        if ($statement === '') {
            return false;
        }

        $firstWord = strtoupper((string) strtok($statement, " \t\n\r"));

        return in_array($firstWord, ['SELECT', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN'], true);
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