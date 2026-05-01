<?php

namespace Core\Database\Schema;

use Core\Database\Schema\Grammars\SchemaGrammar;
use Core\Database\Schema\Grammars\MySQLGrammar;

/**
 * Schema — Main façade for database schema operations.
 *
 * Provides a convenient, fluent API for creating tables, modifying columns,
 * managing indexes/foreign keys, and creating stored procedures/functions/triggers/views.
 *
 * Usage:
 *   // Create a table
 *   Schema::create('users', function (Blueprint $table) {
 *       $table->id();
 *       $table->string('name');
 *       $table->string('email')->unique();
 *       $table->timestamp('created_at')->nullable()->useCurrent();
 *   });
 *
 *   // Modify a table
 *   Schema::table('users', function (Blueprint $table) {
 *       $table->string('phone', 20)->nullable()->after('email');
 *       $table->dropColumn('legacy_field');
 *   });
 *
 *   // Drop a table
 *   Schema::dropIfExists('users');
 *
 *   // Create a stored procedure
 *   Schema::createProcedure('get_active_users', [
 *       ['direction' => 'IN', 'name' => 'status_val', 'type' => 'VARCHAR(20)'],
 *   ], 'SELECT * FROM users WHERE status = status_val;');
 *
 *   // Create a function
 *   Schema::createFunction('calc_tax', [
 *       ['name' => 'amount', 'type' => 'DECIMAL(10,2)'],
 *   ], 'DECIMAL(10,2)', 'RETURN amount * 0.1;', ['deterministic' => true]);
 *
 *   // Create a view
 *   Schema::createView('active_users', 'SELECT * FROM users WHERE status = "active"');
 *
 *   // Create a trigger
 *   Schema::createTrigger('before_user_insert', 'users', 'BEFORE', 'INSERT',
 *       'SET NEW.created_at = NOW();');
 *
 * @category  Database
 * @package   Core\Database\Schema
 * @author    Mohd Fahmy Izwan Zulkhafri <faizzul14@gmail.com>
 * @license   http://opensource.org/licenses/gpl-3.0.html GNU Public License
 * @version   1.0.0
 */
class Schema
{
    /**
     * @var SchemaGrammar The grammar instance for compiling SQL.
     */
    protected SchemaGrammar $grammar;

    /**
     * @var string The database connection name to use.
     */
    protected string $connection;

    /**
     * @var Schema|null Singleton instance for static calls.
     */
    protected static ?Schema $instance = null;

    /**
     * @var string|null The database name (for introspection queries).
     */
    protected ?string $database = null;

    /**
     * Create a new Schema instance.
     *
     * @param string $connection  Connection name (default: 'default')
     * @param string $driver      Database driver (mysql, mariadb)
     */
    public function __construct(string $connection = 'default', string $driver = 'mysql')
    {
        $this->connection = $connection;
        $this->grammar = $this->resolveGrammar($driver);
    }

    /**
     * Get or create the singleton instance.
     */
    protected static function getInstance(): self
    {
        if (self::$instance === null) {
            // Auto-detect driver from the current db() connection
            $driver = 'mysql';
            if (function_exists('db') && db() !== null) {
                $driverProp = db()->getDriver();
                if ($driverProp) {
                    $driver = strtolower($driverProp);
                }
            }
            self::$instance = new self('default', $driver);
        }

        return self::$instance;
    }

    /**
     * Reset the singleton (useful when switching connections).
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * Use a specific connection for schema operations.
     *
     * @return self A new Schema instance bound to the given connection
     */
    public static function connection(string $name, string $driver = 'mysql'): self
    {
        return new self($name, $driver);
    }

    // ─── Table Operations (Static API) ───────────────────────

    /**
     * Create a new table.
     *
     * @param string   $table    Table name
     * @param callable $callback Receives a Blueprint instance
     */
    public static function create(string $table, callable $callback): void
    {
        $instance = static::getInstance();

        $blueprint = new Blueprint($table);
        $callback($blueprint);

        $statements = $instance->grammar->compileCreate($blueprint);
        $instance->executeStatements($statements);
    }

    /**
     * Create a new table only if it doesn't exist.
     *
     * @param string   $table    Table name
     * @param callable $callback Receives a Blueprint instance
     */
    public static function createIfNotExists(string $table, callable $callback): void
    {
        $instance = static::getInstance();

        $blueprint = new Blueprint($table);
        $blueprint->ifNotExists();
        $callback($blueprint);

        $statements = $instance->grammar->compileCreate($blueprint);
        $instance->executeStatements($statements);
    }

    /**
     * Modify an existing table.
     *
     * @param string   $table    Table name
     * @param callable $callback Receives a Blueprint instance
     */
    public static function table(string $table, callable $callback): void
    {
        $instance = static::getInstance();

        $blueprint = new Blueprint($table);
        $callback($blueprint);

        $statements = $instance->grammar->compileAlter($blueprint);
        $instance->executeStatements($statements);
    }

    /**
     * Drop a table.
     */
    public static function drop(string $table): void
    {
        $instance = static::getInstance();
        $sql = $instance->grammar->compileDrop($table);
        $instance->executeStatement($sql);
    }

    /**
     * Drop a table if it exists.
     */
    public static function dropIfExists(string $table): void
    {
        $instance = static::getInstance();
        $sql = $instance->grammar->compileDropIfExists($table);
        $instance->executeStatement($sql);
    }

    /**
     * Rename a table.
     */
    public static function rename(string $from, string $to): void
    {
        $instance = static::getInstance();
        $sql = $instance->grammar->compileRename($from, $to);
        $instance->executeStatement($sql);
    }

    /**
     * Truncate a table.
     */
    public static function truncate(string $table): void
    {
        $instance = static::getInstance();
        $sql = $instance->grammar->compileTruncate($table);
        $instance->executeStatement($sql);
    }

    // ─── Introspection ───────────────────────────────────────

    /**
     * Check if a table exists.
     */
    public static function hasTable(string $table): bool
    {
        $instance = static::getInstance();
        $db = $instance->getDb();
        $database = $instance->getDatabaseName();

        $result = $db->query($instance->grammar->compileTableExists(), [$database, $table])->execute();

        return is_array($result) && !empty($result);
    }

    /**
     * Check if a column exists on a table.
     */
    public static function hasColumn(string $table, string $column): bool
    {
        $columns = static::getColumnListing($table);
        return in_array(strtolower($column), array_map('strtolower', $columns));
    }

    /**
     * Check if multiple columns exist on a table.
     *
     * @param string $table
     * @param array  $columns
     */
    public static function hasColumns(string $table, array $columns): bool
    {
        $existing = array_flip(array_map('strtolower', static::getColumnListing($table)));
        foreach ($columns as $col) {
            if (!isset($existing[strtolower($col)])) {
                return false;
            }
        }
        return true;
    }

    /**
     * Get the column names for a table.
     *
     * @return string[]
     */
    public static function getColumnListing(string $table): array
    {
        $instance = static::getInstance();
        $db = $instance->getDb();

        $sql = $instance->grammar->compileColumnListing($table);
        $result = $db->query($sql)->execute();

        $columns = [];
        if (is_array($result) && !empty($result)) {
            foreach ($result as $row) {
                $columns[] = $row['COLUMN_NAME'] ?? $row['column_name'] ?? '';
            }
        }

        return $columns;
    }

    /**
     * Get detailed column information for a table.
     *
     * @return array[] Each element has: name, type, nullable, default, key, extra, comment
     */
    public static function getColumns(string $table): array
    {
        $instance = static::getInstance();
        $db = $instance->getDb();

        $sql = $instance->grammar->compileColumnListing($table);
        $result = $db->query($sql)->execute();

        return is_array($result) && !empty($result) ? $result : [];
    }

    /**
     * Get index information for a table.
     */
    public static function getIndexes(string $table): array
    {
        $instance = static::getInstance();
        $db = $instance->getDb();

        $sql = $instance->grammar->compileIndexListing($table);
        $result = $db->query($sql)->execute();

        return is_array($result) && !empty($result) ? $result : [];
    }

    /**
     * Get foreign key information for a table.
     */
    public static function getForeignKeys(string $table): array
    {
        $instance = static::getInstance();
        $db = $instance->getDb();

        $sql = $instance->grammar->compileForeignKeyListing($table);
        $result = $db->query($sql)->execute();

        return is_array($result) && !empty($result) ? $result : [];
    }

    // ─── Stored Procedures ───────────────────────────────────

    /**
     * Create a stored procedure.
     *
     * @param string $name       Procedure name
     * @param array  $parameters Array of ['direction' => 'IN|OUT|INOUT', 'name' => ..., 'type' => ...]
     * @param string $body       The procedure body
     * @param array  $options    Optional: replace, definer, comment, deterministic, sql_security
     */
    public static function createProcedure(string $name, array $parameters, string $body, array $options = []): void
    {
        $instance = static::getInstance();

        // Handle replace option by dropping first (avoids multi-statement SQL issues)
        if (!empty($options['replace'])) {
            $dropSql = $instance->grammar->compileDropProcedure($name);
            $instance->executeStatement($dropSql);
            unset($options['replace']);
        }

        $sql = $instance->grammar->compileCreateProcedure($name, $parameters, $body, $options);
        $instance->executeRawStatement($sql);
    }

    /**
     * Drop a stored procedure.
     */
    public static function dropProcedure(string $name, bool $ifExists = true): void
    {
        $instance = static::getInstance();
        $sql = $instance->grammar->compileDropProcedure($name, $ifExists);
        $instance->executeStatement($sql);
    }

    /**
     * List all stored procedures in the current database.
     */
    public static function getProcedures(?string $database = null): array
    {
        $instance = static::getInstance();
        $db = $instance->getDb();

        $sql = $instance->grammar->compileProcedureListing($database);
        $result = $db->query($sql)->execute();

        return is_array($result) && !empty($result['data']) ? $result['data'] : [];
    }

    // ─── Stored Functions ────────────────────────────────────

    /**
     * Create a stored function.
     *
     * @param string $name       Function name
     * @param array  $parameters Array of ['name' => ..., 'type' => ...]
     * @param string $returnType Return data type (e.g., 'DECIMAL(10,2)', 'VARCHAR(255)')
     * @param string $body       The function body
     * @param array  $options    Optional: replace, definer, comment, deterministic, sql_security
     */
    public static function createFunction(string $name, array $parameters, string $returnType, string $body, array $options = []): void
    {
        $instance = static::getInstance();

        // Handle replace option by dropping first (avoids multi-statement SQL issues)
        if (!empty($options['replace'])) {
            $dropSql = $instance->grammar->compileDropFunction($name);
            $instance->executeStatement($dropSql);
            unset($options['replace']);
        }

        $sql = $instance->grammar->compileCreateFunction($name, $parameters, $returnType, $body, $options);
        $instance->executeRawStatement($sql);
    }

    /**
     * Drop a stored function.
     */
    public static function dropFunction(string $name, bool $ifExists = true): void
    {
        $instance = static::getInstance();
        $sql = $instance->grammar->compileDropFunction($name, $ifExists);
        $instance->executeStatement($sql);
    }

    /**
     * List all stored functions in the current database.
     */
    public static function getFunctions(?string $database = null): array
    {
        $instance = static::getInstance();
        $db = $instance->getDb();

        $sql = $instance->grammar->compileFunctionListing($database);
        $result = $db->query($sql)->execute();

        return is_array($result) && !empty($result['data']) ? $result['data'] : [];
    }

    // ─── Triggers ────────────────────────────────────────────

    /**
     * Create a trigger.
     *
     * @param string $name    Trigger name
     * @param string $table   Table the trigger acts on
     * @param string $timing  'BEFORE' or 'AFTER'
     * @param string $event   'INSERT', 'UPDATE', or 'DELETE'
     * @param string $body    Trigger body
     * @param array  $options Optional: replace, definer
     */
    public static function createTrigger(string $name, string $table, string $timing, string $event, string $body, array $options = []): void
    {
        $instance = static::getInstance();

        // Handle replace option by dropping first (avoids multi-statement SQL issues)
        if (!empty($options['replace'])) {
            $dropSql = $instance->grammar->compileDropTrigger($name);
            $instance->executeStatement($dropSql);
            unset($options['replace']);
        }

        $sql = $instance->grammar->compileCreateTrigger($name, $table, $timing, $event, $body, $options);
        $instance->executeRawStatement($sql);
    }

    /**
     * Drop a trigger.
     */
    public static function dropTrigger(string $name, bool $ifExists = true): void
    {
        $instance = static::getInstance();
        $sql = $instance->grammar->compileDropTrigger($name, $ifExists);
        $instance->executeStatement($sql);
    }

    // ─── Views ───────────────────────────────────────────────

    /**
     * Create a view.
     */
    public static function createView(string $name, string $selectSql, bool $orReplace = false): void
    {
        $instance = static::getInstance();
        $sql = $instance->grammar->compileCreateView($name, $selectSql, $orReplace);
        $instance->executeStatement($sql);
    }

    /**
     * Drop a view.
     */
    public static function dropView(string $name, bool $ifExists = true): void
    {
        $instance = static::getInstance();
        $sql = $instance->grammar->compileDropView($name, $ifExists);
        $instance->executeStatement($sql);
    }

    // ─── Raw DDL ─────────────────────────────────────────────

    /**
     * Execute a raw DDL statement.
     */
    public static function statement(string $sql): void
    {
        $instance = static::getInstance();
        $instance->executeStatement($sql);
    }

    // ─── SQL Preview (Dry-Run) ───────────────────────────────

    /**
     * Preview the SQL for a CREATE TABLE without executing.
     *
     * @return string[]
     */
    public static function previewCreate(string $table, callable $callback): array
    {
        $instance = static::getInstance();
        $blueprint = new Blueprint($table);
        $callback($blueprint);
        return $instance->grammar->compileCreate($blueprint);
    }

    /**
     * Preview the SQL for an ALTER TABLE without executing.
     *
     * @return string[]
     */
    public static function previewAlter(string $table, callable $callback): array
    {
        $instance = static::getInstance();
        $blueprint = new Blueprint($table);
        $callback($blueprint);
        return $instance->grammar->compileAlter($blueprint);
    }

    // ─── Internal Helpers ────────────────────────────────────

    /**
     * Resolve the appropriate grammar for a driver.
     */
    protected function resolveGrammar(string $driver): SchemaGrammar
    {
        return \Core\Database\DriverRegistry::schemaGrammar($driver);
    }

    /**
     * Get the database connection instance.
     *
     * @return mixed The database connection from db()
     */
    protected function getDb(): mixed
    {
        if (!function_exists('db')) {
            throw new \RuntimeException('Schema requires the db() helper function to be available.');
        }

        $db = db($this->connection);

        if ($db === null) {
            throw new \RuntimeException("Database connection [{$this->connection}] is not available.");
        }

        return $db;
    }

    /**
     * Get the current database name.
     */
    protected function getDatabaseName(): string
    {
        if ($this->database !== null && $this->database !== '') {
            return $this->database;
        }

        $db = $this->getDb();

        // Try to get database name from the connection
        if (method_exists($db, 'getDatabase')) {
            $this->database = $db->getDatabase();
        } else {
            // Fallback: query the database
            $result = $db->query('SELECT DATABASE() as db_name')->execute();
            $this->database = (is_array($result) && !empty($result)) ? ($result[0]['db_name'] ?? '') : '';
        }

        return $this->database;
    }

    /**
     * Execute one or more SQL statements via the database query builder.
     *
     * @param string[] $statements
     */
    protected function executeStatements(array $statements): void
    {
        foreach ($statements as $sql) {
            $this->executeStatement($sql);
        }
    }

    /**
     * Execute a single SQL statement.
     */
    protected function executeStatement(string $sql): void
    {
        $db = $this->getDb();
        $db->query($sql)->execute();
    }

    /**
     * Execute a raw SQL statement that may contain delimiter-separated blocks.
     * Used for procedures/functions/triggers that contain multi-statement bodies.
     */
    protected function executeRawStatement(string $sql): void
    {
        $db = $this->getDb();

        // For compound statements (procedures/functions/triggers),
        // we need to use the PDO directly if available
        if (method_exists($db, 'getPdo')) {
            $pdo = $db->getPdo();
            $pdo->exec($sql);
        } else {
            $db->query($sql)->execute();
        }
    }
}
