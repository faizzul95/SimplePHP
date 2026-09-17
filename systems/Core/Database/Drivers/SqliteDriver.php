<?php

declare(strict_types=1);

namespace Core\Database\Drivers;

use Core\Database\BaseDatabase;
use Core\Database\ConnectionPool;
use Core\Database\DriverCapabilities;
use Core\Database\DriverRegistry;
use InvalidArgumentException;
use RuntimeException;

/**
 * SQLite.
 *
 * The second engine. Its point is not that anyone ships on SQLite — it is that
 * the query grammars had nothing to run against, so every portability claim in
 * this framework was a claim about generated strings. With this driver the
 * builder can be exercised end to end against a real database in a test, with
 * no server to install.
 *
 * Most of the dialect lives in SqliteGrammar. What is here is the connection
 * and the handful of builder methods BaseDatabase leaves abstract.
 *
 * `count()` and `exists()` wrap the built query in a subquery rather than
 * rewriting it with regular expressions the way MySQLDriver does. Wrapping is
 * correct for GROUP BY, HAVING, JOINs and DISTINCT without special-casing any
 * of them, and it is portable.
 */
class SqliteDriver extends BaseDatabase
{
    use \Core\Database\Concerns\HasBatchWrites;

    public function capabilities(): DriverCapabilities
    {
        return DriverRegistry::capabilities((string) ($this->driver ?: 'sqlite'));
    }

    public function connect($connectionID = null)
    {
        $connectionName = !empty($connectionID) ? $connectionID : $this->connectionName;

        if (!isset($this->config[$connectionName])) {
            throw new InvalidArgumentException("Configuration for {$connectionName} not found.");
        }

        $this->setConnection($connectionName);

        /*
        | Declare the engine, or the grammar seam silently resolves to MySQL.
        |
        | BaseDatabase::$driver defaults to 'mysql' and nothing populates it
        | from the connection config, so every lookup of the form
        | `DriverRegistry::queryGrammar($this->driver ?: 'mysql')` returns the
        | MySQL grammar whatever engine is actually connected. On SQLite that
        | produced `YEAR(created_at)` — no such function — from whereYear().
        |
        | Setting it here fixes this driver. The same gap affects MariaDB
        | connections, which quietly use MySQLGrammar rather than MariaDBGrammar;
        | that is noted in 10-audit-findings.md rather than changed blind, since
        | there is no MariaDB server here to verify against.
        */
        $this->driver = 'sqlite';

        /*
        | No schema, deliberately.
        |
        | setDatabase() populates $this->schema, which the builder emits as a
        | `schema`.`table` prefix. For a server engine that is the database
        | name; for SQLite the configured "database" is a filesystem path, so
        | the prefix came out as "C:\...\app.sqlite"."users" and every query
        | failed to parse. SQLite's only real schema names are main, temp and
        | whatever has been ATTACHed — none of which this setting carries.
        */
        $this->setDatabase(null);

        if (!isset($this->pdo[$connectionName])) {
            try {
                $this->pdo[$connectionName] = ConnectionPool::getConnection(
                    $connectionName,
                    $this->config[$connectionName]
                );

                $this->applyPragmas($this->pdo[$connectionName], $this->config[$connectionName]);
            } catch (\Exception $e) {
                throw new RuntimeException($e->getMessage(), 0, $e);
            }
        }

        return $this;
    }

    /**
     * Session settings SQLite does not default sensibly.
     *
     * `foreign_keys` is **off** by default — every declared foreign key is
     * inert until it is switched on, per connection, which makes a schema look
     * enforced when it is not. `busy_timeout` turns an immediate
     * SQLITE_BUSY on a concurrent write into a wait, which is almost always
     * what the caller wanted.
     *
     * @param array<string, mixed> $config
     */
    private function applyPragmas(\PDO $pdo, array $config): void
    {
        $pragmas = [
            'foreign_keys' => ($config['foreign_keys'] ?? true) ? 'ON' : 'OFF',
            'busy_timeout' => (string) max(0, (int) ($config['busy_timeout'] ?? 5000)),
        ];

        // WAL is a file-level mode and meaningless for :memory:.
        $database = (string) ($config['database'] ?? '');
        if ($database !== '' && $database !== ':memory:' && ($config['journal_mode'] ?? 'WAL') !== null) {
            $mode = strtoupper((string) ($config['journal_mode'] ?? 'WAL'));
            if (in_array($mode, ['DELETE', 'TRUNCATE', 'PERSIST', 'MEMORY', 'WAL', 'OFF'], true)) {
                $pragmas['journal_mode'] = $mode;
            }
        }

        foreach ($pragmas as $name => $value) {
            try {
                // Values are from the allowlists above, never from user input.
                $pdo->exec("PRAGMA {$name} = {$value}");
            } catch (\Throwable $e) {
                \Core\Support\SafeLog::warning(sprintf(
                    'Could not apply SQLite PRAGMA %s = %s: %s',
                    $name,
                    $value,
                    $e->getMessage()
                ));
            }
        }
    }

    // ─── Temporal predicates ─────────────────────────────────────────

    public function whereDate($column, $operator = null, $value = null)
    {
        return $this->applyTemporalWhereClause('date', $column, $operator, $value, 'AND');
    }

    public function orWhereDate($column, $operator = null, $value = null)
    {
        return $this->applyTemporalWhereClause('date', $column, $operator, $value, 'OR');
    }

    public function whereDay($column, $operator = null, $value = null)
    {
        return $this->applyTemporalWhereClause('day', $column, $operator, $value, 'AND');
    }

    public function orWhereDay($column, $operator = null, $value = null)
    {
        return $this->applyTemporalWhereClause('day', $column, $operator, $value, 'OR');
    }

    public function whereMonth($column, $operator = null, $value = null)
    {
        return $this->applyTemporalWhereClause('month', $column, $operator, $value, 'AND');
    }

    public function orWhereMonth($column, $operator = null, $value = null)
    {
        return $this->applyTemporalWhereClause('month', $column, $operator, $value, 'OR');
    }

    public function whereYear($column, $operator = null, $value = null)
    {
        return $this->applyTemporalWhereClause('year', $column, $operator, $value, 'AND');
    }

    public function orWhereYear($column, $operator = null, $value = null)
    {
        return $this->applyTemporalWhereClause('year', $column, $operator, $value, 'OR');
    }

    public function whereTime($column, $operator = null, $value = null)
    {
        return $this->applyTemporalWhereClause('time', $column, $operator, $value, 'AND');
    }

    public function orWhereTime($column, $operator = null, $value = null)
    {
        return $this->applyTemporalWhereClause('time', $column, $operator, $value, 'OR');
    }

    /**
     * SQLite has no JSON_CONTAINS. The JSON1 extension — compiled in by default
     * since 3.38 — gives json_extract(), which answers the same question for
     * the `path => value` shape this method accepts.
     */
    public function whereJsonContains($columnName, $jsonPath, $value)
    {
        $this->validateColumn($columnName);
        $this->_forbidRawQuery($columnName, 'Full/Sub SQL statements are not allowed in whereJsonContains().');

        $this->whereNotNull($columnName);

        $path = '$.' . ltrim((string) $jsonPath, '$.');
        $wrapped = $this->wrapIdentifier((string) $columnName);

        // Both the path and the value are bound.
        $this->whereRaw("json_extract({$wrapped}, ?) = ?", [$path, $value], 'AND');

        return $this;
    }

    // ─── Row limiting ────────────────────────────────────────────────

    public function limit($limit)
    {
        $limit = filter_var($limit, FILTER_VALIDATE_INT);

        if ($limit === false) {
            throw new InvalidArgumentException('Limit must be an integer.');
        }

        if ($limit < 1) {
            throw new InvalidArgumentException('Limit must be integer with higher then zero');
        }

        $this->limit = " LIMIT $limit";

        return $this;
    }

    public function offset($offset)
    {
        $offset = filter_var($offset, FILTER_VALIDATE_INT);

        if ($offset === false) {
            throw new InvalidArgumentException('Offset must be an integer.');
        }

        if ($offset < 0) {
            throw new InvalidArgumentException('Offset must be integer with higher or equal to zero');
        }

        /*
        | `OFFSET n` alone is a syntax error in SQLite — it exists only as a
        | suffix to LIMIT — so an offset with no limit carries the documented
        | "no limit" sentinel with it. Without this, paginating from an offset
        | without also setting a limit fails to parse.
        */
        $this->offset = $this->limit === null || $this->limit === ''
            ? " LIMIT -1 OFFSET $offset"
            : " OFFSET $offset";

        return $this;
    }

    public function _getLimitOffsetPaginate($query, $limit, $offset)
    {
        $limit = filter_var($limit, FILTER_VALIDATE_INT);
        $offset = filter_var($offset, FILTER_VALIDATE_INT);

        if ($offset === false || $offset < 0) {
            throw new InvalidArgumentException('Offset must be integer with higher or equal to zero');
        }

        if ($limit === false || $limit < 1) {
            throw new InvalidArgumentException('Limit must be integer with higher then zero');
        }

        return "$query LIMIT $limit OFFSET $offset";
    }

    // ─── Aggregates ──────────────────────────────────────────────────

    /**
     * Wrapping the built query beats rewriting it.
     *
     * MySQLDriver extracts the FROM clause with a regular expression and
     * special-cases GROUP BY and HAVING. A subquery needs none of that: it is
     * right for grouping, joins and DISTINCT alike, and there is no pattern to
     * get wrong on a query shape nobody anticipated.
     */
    public function count($table = null)
    {
        if (!empty($table)) {
            $this->table = $table;
        }

        try {
            if ($this->enableProfiling) {
                $this->_startProfiler(__FUNCTION__);
            }

            if (empty($this->_query)) {
                $this->_buildSelectQuery();
            }

            $inner = $this->stripTrailingRowLimits($this->_query);
            $statement = $this->_prepareStatement("SELECT COUNT(*) AS total FROM ({$inner}) AS myth_count");

            $bindings = $this->getSelectQueryBindings();
            if (!empty($bindings)) {
                $this->_bindParams($statement, $bindings);
            }

            $statement->execute();
            $row = $statement->fetch(\PDO::FETCH_ASSOC);

            if (method_exists($statement, 'closeCursor')) {
                $statement->closeCursor();
            }
            unset($statement);

            if ($this->enableProfiling) {
                $this->_stopProfiler();
            }

            $this->reset();

            return (int) ($row['total'] ?? 0);
        } catch (\PDOException $e) {
            $this->logDatabaseError($e, __FUNCTION__);
            throw $e;
        }
    }

    public function exists($table = null)
    {
        if (!empty($table)) {
            $this->table = $table;
        }

        try {
            if ($this->enableProfiling) {
                $this->_startProfiler(__FUNCTION__);
            }

            if (empty($this->_query)) {
                $this->_buildSelectQuery();
            }

            $inner = $this->stripTrailingRowLimits($this->_query);
            $statement = $this->_prepareStatement(
                "SELECT EXISTS(SELECT 1 FROM ({$inner}) AS myth_exists LIMIT 1) AS row_exists"
            );

            $bindings = $this->getSelectQueryBindings();
            if (!empty($bindings)) {
                $this->_bindParams($statement, $bindings);
            }

            $statement->execute();
            $row = $statement->fetch(\PDO::FETCH_ASSOC);

            if (method_exists($statement, 'closeCursor')) {
                $statement->closeCursor();
            }
            unset($statement);

            if ($this->enableProfiling) {
                $this->_stopProfiler();
            }

            $this->reset();

            return (bool) ($row['row_exists'] ?? false);
        } catch (\PDOException $e) {
            $this->logDatabaseError($e, __FUNCTION__);
            throw $e;
        }
    }

    /**
     * Drop a trailing LIMIT/OFFSET before wrapping.
     *
     * Keeping them would count the page rather than the result set. Only a
     * trailing clause is removed, so a LIMIT inside a subquery in the SELECT
     * list is left alone.
     */
    private function stripTrailingRowLimits(string $query): string
    {
        return (string) preg_replace(
            '/\s+LIMIT\s+-?\d+(\s+OFFSET\s+\d+)?\s*;?\s*$/i',
            '',
            rtrim($query, "; \t\n\r")
        );
    }

    // ─── Writes ──────────────────────────────────────────────────────

    /**
     * ON CONFLICT … DO UPDATE, which SQLite has had since 3.24.
     *
     * The clause itself comes from the grammar, so the shape is the one the
     * grammar tests cover rather than a second copy written here.
     *
     * @param array<int|string, mixed> $values
     * @param string|list<string> $uniqueBy
     * @param list<string>|null $updateColumns
     */
    public function upsert($values, $uniqueBy = 'id', $updateColumns = null, $batchSize = 2000)
    {
        if (empty($values) || empty($uniqueBy)) {
            throw new InvalidArgumentException('Values and uniqueBy are required');
        }

        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', (string) $this->table)) {
            throw new InvalidArgumentException('Invalid table name');
        }

        $rows = isset($values[0]) && is_array($values[0]) ? $values : [$values];
        $validColumns = $this->getTableColumns();

        if (empty($validColumns)) {
            throw new InvalidArgumentException('Unable to retrieve table columns');
        }

        $conflict = is_array($uniqueBy) ? $uniqueBy : array_map('trim', explode(',', (string) $uniqueBy));

        foreach ($conflict as $column) {
            if (!in_array($column, $validColumns, true)) {
                throw new InvalidArgumentException("Invalid uniqueBy column: {$column}");
            }
        }

        $columns = array_values(array_filter(
            array_keys($rows[0]),
            static fn($column): bool => in_array($column, $validColumns, true)
        ));

        if ($columns === []) {
            throw new InvalidArgumentException('No valid columns to upsert');
        }

        $updates = $updateColumns === null
            ? array_values(array_diff($columns, $conflict))
            : array_values(array_intersect($updateColumns, $columns));

        $grammar = $this->grammar();
        $clause = $grammar->compileUpsertClause($updates, $conflict);

        $wrappedColumns = implode(', ', array_map(
            static fn(string $c): string => $grammar->wrap($c),
            $columns
        ));
        $placeholders = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';

        $affected = 0;
        $this->_startProfiler(__FUNCTION__);

        try {
            foreach (array_chunk($rows, max(1, (int) $batchSize)) as $chunk) {
                $bindings = [];
                foreach ($chunk as $row) {
                    foreach ($columns as $column) {
                        $bindings[] = $row[$column] ?? null;
                    }
                }

                $sql = 'INSERT INTO ' . $grammar->wrap((string) $this->table)
                    . ' (' . $wrappedColumns . ') VALUES '
                    . implode(', ', array_fill(0, count($chunk), $placeholders))
                    . ($clause === null ? '' : ' ' . $clause);

                $this->_query = $sql;

                // Not just profiling: this is also where a write drops the
                // cached reads of the table it changed. Skipping it left
                // upsert()'s new values invisible to the next read.
                $this->_captureExecutedQuery($bindings);

                $statement = $this->_prepareStatement($sql);
                $statement->execute($bindings);
                $affected += $statement->rowCount();

                if (method_exists($statement, 'closeCursor')) {
                    $statement->closeCursor();
                }
                unset($statement);
            }
        } catch (\PDOException $e) {
            $this->logDatabaseError($e, __FUNCTION__);
            throw $e;
        } finally {
            $this->_stopProfiler();
            $this->reset();
        }

        return ['code' => 200, 'message' => 'Upsert completed', 'affected' => $affected];
    }
}
