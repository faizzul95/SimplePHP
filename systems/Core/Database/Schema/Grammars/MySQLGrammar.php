<?php

namespace Core\Database\Schema\Grammars;

use Core\Database\Schema\Blueprint;
use Core\Database\Schema\ColumnDefinition;
use Core\Database\Schema\ForeignKeyDefinition;

/**
 * MySQLGrammar — Compiles Blueprint definitions into MySQL/MariaDB DDL.
 *
 * Supports MySQL 5.7+ and MariaDB 10.2+.
 *
 * @category  Database
 * @package   Core\Database\Schema\Grammars
 * @author    Mohd Fahmy Izwan Zulkhafri <faizzul14@gmail.com>
 * @license   http://opensource.org/licenses/gpl-3.0.html GNU Public License
 * @version   1.0.0
 */
class MySQLGrammar extends SchemaGrammar
{
    /**
     * Map Blueprint column types to MySQL data types.
     */
    protected array $typeMap = [
        'bigInteger'    => 'BIGINT',
        'integer'       => 'INT',
        'mediumInteger' => 'MEDIUMINT',
        'smallInteger'  => 'SMALLINT',
        'tinyInteger'   => 'TINYINT',
        'decimal'       => 'DECIMAL',
        'float'         => 'FLOAT',
        'double'        => 'DOUBLE',
        'string'        => 'VARCHAR',
        'char'          => 'CHAR',
        'text'          => 'TEXT',
        'mediumText'    => 'MEDIUMTEXT',
        'longText'      => 'LONGTEXT',
        'tinyText'      => 'TINYTEXT',
        'boolean'       => 'TINYINT(1)',
        'enum'          => 'ENUM',
        'set'           => 'SET',
        'json'          => 'JSON',
        'jsonb'         => 'JSON',
        'date'          => 'DATE',
        'dateTime'      => 'DATETIME',
        'time'          => 'TIME',
        'timestamp'     => 'TIMESTAMP',
        'year'          => 'YEAR',
        'binary'        => 'BINARY',
        'blob'          => 'BLOB',
        'uuid'          => 'CHAR(36)',
    ];

    // ─── Table Operations ────────────────────────────────────

    /**
     * {@inheritdoc}
     */
    public function compileCreate(Blueprint $blueprint): array
    {
        $statements = [];

        // Guard: empty blueprint
        if (empty($blueprint->getColumns()) && empty($blueprint->getCommands())) {
            throw new \InvalidArgumentException(
                "Cannot create table '{$blueprint->getTable()}' with no columns or constraints defined."
            );
        }

        // Build column definitions
        $columnsSql = [];
        $inlineConstraints = [];

        foreach ($blueprint->getColumns() as $col) {
            $columnsSql[] = $this->compileColumn($col);

            // Collect inline primary/unique/index for CREATE TABLE
            if ($col->isPrimary && !$col->isAutoIncrement) {
                // Auto-increment columns already have PRIMARY KEY inline
                $inlineConstraints[] = "PRIMARY KEY ({$this->wrap($col->name)})";
            }
            if ($col->isUnique) {
                $inlineConstraints[] = "UNIQUE KEY {$this->wrap($col->name . '_unique')} ({$this->wrap($col->name)})";
            }
            if ($col->isIndex) {
                $inlineConstraints[] = "INDEX {$this->wrap($col->name . '_index')} ({$this->wrap($col->name)})";
            }

            // Handle foreignId()->constrained() shortcut
            if ($col->constrainedTable !== null) {
                $fkName = $this->wrap(strtolower($blueprint->getTable() . '_' . $col->name . '_fk'));
                $inlineConstraints[] = "CONSTRAINT {$fkName} FOREIGN KEY ({$this->wrap($col->name)}) "
                    . "REFERENCES {$this->wrap($col->constrainedTable)} ({$this->wrap($col->constrainedColumn ?? 'id')})"
                    . ($col->onDelete ? " ON DELETE {$col->onDelete}" : '')
                    . ($col->onUpdate ? " ON UPDATE {$col->onUpdate}" : '');
            }
        }

        // Process commands (composite indexes, foreign keys, etc.)
        foreach ($blueprint->getCommands() as $cmd) {
            switch ($cmd['type']) {
                case 'primary':
                    $inlineConstraints[] = "PRIMARY KEY ({$this->columnize($cmd['columns'])})";
                    break;
                case 'unique':
                    $name = $cmd['name'] ?? strtolower($blueprint->getTable() . '_' . implode('_', $cmd['columns']) . '_unique');
                    $inlineConstraints[] = "UNIQUE KEY {$this->wrap($name)} ({$this->columnize($cmd['columns'])})";
                    break;
                case 'index':
                    $name = $cmd['name'] ?? strtolower($blueprint->getTable() . '_' . implode('_', $cmd['columns']) . '_index');
                    $inlineConstraints[] = "INDEX {$this->wrap($name)} ({$this->columnize($cmd['columns'])})";
                    break;
                case 'fulltext':
                    $name = $cmd['name'] ?? strtolower($blueprint->getTable() . '_' . implode('_', $cmd['columns']) . '_fulltext');
                    $inlineConstraints[] = "FULLTEXT INDEX {$this->wrap($name)} ({$this->columnize($cmd['columns'])})";
                    break;
                case 'spatialIndex':
                    $name = $cmd['name'] ?? strtolower($blueprint->getTable() . '_' . implode('_', $cmd['columns']) . '_spatial');
                    $inlineConstraints[] = "SPATIAL INDEX {$this->wrap($name)} ({$this->columnize($cmd['columns'])})";
                    break;
                case 'foreign':
                    /** @var ForeignKeyDefinition $fk */
                    $fk = $cmd['definition'];
                    $inlineConstraints[] = $this->compileForeignKey($fk, $blueprint->getTable());
                    break;
            }
        }

        // Combine columns and constraints
        $definitions = array_merge($columnsSql, $inlineConstraints);

        $sql = 'CREATE';
        if ($blueprint->isTemporary()) {
            $sql .= ' TEMPORARY';
        }
        $sql .= ' TABLE';
        if ($blueprint->isIfNotExists()) {
            $sql .= ' IF NOT EXISTS';
        }
        $sql .= ' ' . $this->wrap($blueprint->getTable());
        $sql .= " (\n  " . implode(",\n  ", $definitions) . "\n)";

        // Table options
        $options = [];
        if ($engine = $blueprint->getEngine()) {
            $options[] = "ENGINE={$engine}";
        } else {
            $options[] = 'ENGINE=InnoDB';
        }
        if ($charset = $blueprint->getCharset()) {
            $options[] = "DEFAULT CHARSET={$charset}";
        } else {
            $options[] = 'DEFAULT CHARSET=utf8mb4';
        }
        if ($collation = $blueprint->getCollation()) {
            $options[] = "COLLATE={$collation}";
        } else {
            $options[] = 'COLLATE=utf8mb4_unicode_ci';
        }
        if ($comment = $blueprint->getComment()) {
            $options[] = "COMMENT='" . str_replace(["'", "\\"], ["''", "\\\\"], $comment) . "'";
        }

        $sql .= ' ' . implode(' ', $options);

        $statements[] = $sql;

        return $statements;
    }

    /**
     * {@inheritdoc}
     */
    public function compileAlter(Blueprint $blueprint): array
    {
        $statements = [];
        $table = $this->wrap($blueprint->getTable());

        // Add new columns
        foreach ($blueprint->getColumns() as $col) {
            if ($col->change) {
                $sql = "ALTER TABLE {$table} MODIFY COLUMN " . $this->compileColumn($col);
            } else {
                $sql = "ALTER TABLE {$table} ADD COLUMN " . $this->compileColumn($col);
            }

            if ($col->after) {
                $sql .= " AFTER {$this->wrap($col->after)}";
            } elseif ($col->first) {
                $sql .= ' FIRST';
            }

            $statements[] = $sql;

            // Handle column-level constraint flags (unique, index, primary)
            if ($col->isPrimary && !$col->isAutoIncrement) {
                $statements[] = "ALTER TABLE {$table} ADD PRIMARY KEY ({$this->wrap($col->name)})";
            }
            if ($col->isUnique) {
                $name = $this->wrap(strtolower($blueprint->getTable() . '_' . $col->name . '_unique'));
                $statements[] = "ALTER TABLE {$table} ADD UNIQUE KEY {$name} ({$this->wrap($col->name)})";
            }
            if ($col->isIndex) {
                $name = $this->wrap(strtolower($blueprint->getTable() . '_' . $col->name . '_index'));
                $statements[] = "ALTER TABLE {$table} ADD INDEX {$name} ({$this->wrap($col->name)})";
            }

            // Handle foreignId()->constrained() shortcut during alter
            if ($col->constrainedTable !== null) {
                $fkName = strtolower($blueprint->getTable() . '_' . $col->name . '_fk');
                $statements[] = "ALTER TABLE {$table} ADD CONSTRAINT {$this->wrap($fkName)} "
                    . "FOREIGN KEY ({$this->wrap($col->name)}) "
                    . "REFERENCES {$this->wrap($col->constrainedTable)} ({$this->wrap($col->constrainedColumn ?? 'id')})"
                    . ($col->onDelete ? " ON DELETE {$col->onDelete}" : '')
                    . ($col->onUpdate ? " ON UPDATE {$col->onUpdate}" : '');
            }
        }

        // Process commands
        foreach ($blueprint->getCommands() as $cmd) {
            switch ($cmd['type']) {
                case 'primary':
                    $statements[] = "ALTER TABLE {$table} ADD PRIMARY KEY ({$this->columnize($cmd['columns'])})";
                    break;

                case 'unique':
                    $name = $cmd['name'] ?? strtolower($blueprint->getTable() . '_' . implode('_', $cmd['columns']) . '_unique');
                    $statements[] = "ALTER TABLE {$table} ADD UNIQUE KEY {$this->wrap($name)} ({$this->columnize($cmd['columns'])})";
                    break;

                case 'index':
                    $name = $cmd['name'] ?? strtolower($blueprint->getTable() . '_' . implode('_', $cmd['columns']) . '_index');
                    $statements[] = "ALTER TABLE {$table} ADD INDEX {$this->wrap($name)} ({$this->columnize($cmd['columns'])})";
                    break;

                case 'fulltext':
                    $name = $cmd['name'] ?? strtolower($blueprint->getTable() . '_' . implode('_', $cmd['columns']) . '_fulltext');
                    $statements[] = "ALTER TABLE {$table} ADD FULLTEXT INDEX {$this->wrap($name)} ({$this->columnize($cmd['columns'])})";
                    break;

                case 'spatialIndex':
                    $name = $cmd['name'] ?? strtolower($blueprint->getTable() . '_' . implode('_', $cmd['columns']) . '_spatial');
                    $statements[] = "ALTER TABLE {$table} ADD SPATIAL INDEX {$this->wrap($name)} ({$this->columnize($cmd['columns'])})";
                    break;

                case 'dropColumn':
                    foreach ($cmd['columns'] as $col) {
                        $statements[] = "ALTER TABLE {$table} DROP COLUMN {$this->wrap($col)}";
                    }
                    break;

                case 'renameColumn':
                    $statements[] = "ALTER TABLE {$table} RENAME COLUMN {$this->wrap($cmd['from'])} TO {$this->wrap($cmd['to'])}";
                    break;

                case 'dropIndex':
                case 'dropUnique':
                case 'dropFulltext':
                    $statements[] = "ALTER TABLE {$table} DROP INDEX {$this->wrap($cmd['name'])}";
                    break;

                case 'dropPrimary':
                    $statements[] = "ALTER TABLE {$table} DROP PRIMARY KEY";
                    break;

                case 'foreign':
                    /** @var ForeignKeyDefinition $fk */
                    $fk = $cmd['definition'];
                    $statements[] = "ALTER TABLE {$table} ADD " . $this->compileForeignKey($fk, $blueprint->getTable());
                    break;

                case 'dropForeign':
                    $statements[] = "ALTER TABLE {$table} DROP FOREIGN KEY {$this->wrap($cmd['name'])}";
                    break;

                case 'rename':
                    $statements[] = "ALTER TABLE {$table} RENAME TO {$this->wrap($cmd['to'])}";
                    break;
            }
        }

        return $statements;
    }

    /**
     * {@inheritdoc}
     */
    public function compileDrop(string $table): string
    {
        return "DROP TABLE {$this->wrap($table)}";
    }

    /**
     * {@inheritdoc}
     */
    public function compileDropIfExists(string $table): string
    {
        return "DROP TABLE IF EXISTS {$this->wrap($table)}";
    }

    /**
     * {@inheritdoc}
     */
    public function compileRename(string $from, string $to): string
    {
        return "RENAME TABLE {$this->wrap($from)} TO {$this->wrap($to)}";
    }

    /**
     * {@inheritdoc}
     */
    public function compileTruncate(string $table): string
    {
        return "TRUNCATE TABLE {$this->wrap($table)}";
    }

    // ─── Introspection ───────────────────────────────────────

    /**
     * {@inheritdoc}
     */
    public function compileTableExists(): string
    {
        return "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?";
    }

    /**
     * {@inheritdoc}
     */
    public function compileColumnExists(string $table): string
    {
        return "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?";
    }

    /**
     * {@inheritdoc}
     */
    public function compileColumnListing(string $table): string
    {
        return "SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_KEY, EXTRA, COLUMN_COMMENT "
            . "FROM INFORMATION_SCHEMA.COLUMNS "
            . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $this->getDefaultValue($table) . " "
            . "ORDER BY ORDINAL_POSITION";
    }

    /**
     * {@inheritdoc}
     */
    public function compileIndexListing(string $table): string
    {
        return "SHOW INDEX FROM {$this->wrap($table)}";
    }

    /**
     * {@inheritdoc}
     */
    public function compileForeignKeyListing(string $table): string
    {
        return "SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME "
            . "FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE "
            . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $this->getDefaultValue($table) . " "
            . "AND REFERENCED_TABLE_NAME IS NOT NULL";
    }

    // ─── Stored Procedures / Functions ───────────────────────

    /**
     * {@inheritdoc}
     */
    public function compileCreateProcedure(string $name, array $parameters, string $body, array $options = []): string
    {
        $params = [];
        foreach ($parameters as $param) {
            $direction = strtoupper($param['direction'] ?? 'IN');
            if (!in_array($direction, ['IN', 'OUT', 'INOUT'], true)) {
                throw new \InvalidArgumentException("Invalid parameter direction '{$direction}'. Allowed: IN, OUT, INOUT");
            }
            $paramName = $this->validateIdentifier($param['name']);
            $paramType = $param['type'];
            $params[] = "{$direction} {$paramName} {$paramType}";
        }

        $sql = '';

        // Drop existing procedure first if requested
        if (!empty($options['replace'])) {
            $sql .= "DROP PROCEDURE IF EXISTS {$this->wrap($name)};\n";
        }

        $sql .= "CREATE";
        if (!empty($options['definer'])) {
            $sql .= " DEFINER=" . $this->validateDefiner($options['definer']);
        }
        $sql .= " PROCEDURE {$this->wrap($name)}(" . implode(', ', $params) . ")\n";

        if (!empty($options['comment'])) {
            $sql .= "COMMENT '" . str_replace(["'", "\\"], ["''", "\\\\"], $options['comment']) . "'\n";
        }

        if (!empty($options['deterministic'])) {
            $sql .= "DETERMINISTIC\n";
        } else {
            $sql .= "NOT DETERMINISTIC\n";
        }

        if (!empty($options['sql_security'])) {
            $sql .= "SQL SECURITY " . $this->validateSqlSecurity($options['sql_security']) . "\n";
        }

        $sql .= "BEGIN\n{$body}\nEND";

        return $sql;
    }

    /**
     * {@inheritdoc}
     */
    public function compileDropProcedure(string $name, bool $ifExists = true): string
    {
        return "DROP PROCEDURE " . ($ifExists ? 'IF EXISTS ' : '') . $this->wrap($name);
    }

    /**
     * {@inheritdoc}
     */
    public function compileCreateFunction(string $name, array $parameters, string $returnType, string $body, array $options = []): string
    {
        $params = [];
        foreach ($parameters as $param) {
            $paramName = $this->validateIdentifier($param['name']);
            $paramType = $param['type'];
            $params[] = "{$paramName} {$paramType}";
        }

        $sql = '';

        if (!empty($options['replace'])) {
            $sql .= "DROP FUNCTION IF EXISTS {$this->wrap($name)};\n";
        }

        $sql .= "CREATE";
        if (!empty($options['definer'])) {
            $sql .= " DEFINER=" . $this->validateDefiner($options['definer']);
        }
        $sql .= " FUNCTION {$this->wrap($name)}(" . implode(', ', $params) . ")\n";
        $sql .= "RETURNS {$returnType}\n";

        if (!empty($options['comment'])) {
            $sql .= "COMMENT '" . str_replace(["'", "\\"], ["''", "\\\\"], $options['comment']) . "'\n";
        }

        if (!empty($options['deterministic'])) {
            $sql .= "DETERMINISTIC\n";
        } else {
            $sql .= "NOT DETERMINISTIC\n";
        }

        if (!empty($options['sql_security'])) {
            $sql .= "SQL SECURITY " . $this->validateSqlSecurity($options['sql_security']) . "\n";
        }

        $sql .= "BEGIN\n{$body}\nEND";

        return $sql;
    }

    /**
     * {@inheritdoc}
     */
    public function compileDropFunction(string $name, bool $ifExists = true): string
    {
        return "DROP FUNCTION " . ($ifExists ? 'IF EXISTS ' : '') . $this->wrap($name);
    }

    /**
     * {@inheritdoc}
     */
    public function compileProcedureListing(?string $database = null): string
    {
        $db = $database ? $this->getDefaultValue($database) : 'DATABASE()';
        return "SELECT ROUTINE_NAME, ROUTINE_TYPE, CREATED, LAST_ALTERED, ROUTINE_COMMENT "
            . "FROM INFORMATION_SCHEMA.ROUTINES "
            . "WHERE ROUTINE_SCHEMA = {$db} AND ROUTINE_TYPE = 'PROCEDURE'";
    }

    /**
     * {@inheritdoc}
     */
    public function compileFunctionListing(?string $database = null): string
    {
        $db = $database ? $this->getDefaultValue($database) : 'DATABASE()';
        return "SELECT ROUTINE_NAME, ROUTINE_TYPE, CREATED, LAST_ALTERED, ROUTINE_COMMENT "
            . "FROM INFORMATION_SCHEMA.ROUTINES "
            . "WHERE ROUTINE_SCHEMA = {$db} AND ROUTINE_TYPE = 'FUNCTION'";
    }

    // ─── Triggers ────────────────────────────────────────────

    /**
     * {@inheritdoc}
     */
    public function compileCreateTrigger(string $name, string $table, string $timing, string $event, string $body, array $options = []): string
    {
        $timing = $this->validateTriggerTiming($timing);
        $event = $this->validateTriggerEvent($event);

        $sql = '';

        if (!empty($options['replace'])) {
            $sql .= "DROP TRIGGER IF EXISTS {$this->wrap($name)};\n";
        }

        $sql .= "CREATE";
        if (!empty($options['definer'])) {
            $sql .= " DEFINER=" . $this->validateDefiner($options['definer']);
        }
        $sql .= " TRIGGER {$this->wrap($name)} {$timing} {$event} ON {$this->wrap($table)}\nFOR EACH ROW\n";
        $sql .= "BEGIN\n{$body}\nEND";

        return $sql;
    }

    /**
     * {@inheritdoc}
     */
    public function compileDropTrigger(string $name, bool $ifExists = true): string
    {
        return "DROP TRIGGER " . ($ifExists ? 'IF EXISTS ' : '') . $this->wrap($name);
    }

    // ─── Views ───────────────────────────────────────────────

    /**
     * {@inheritdoc}
     */
    public function compileCreateView(string $name, string $selectSql, bool $orReplace = false): string
    {
        $prefix = $orReplace ? 'CREATE OR REPLACE' : 'CREATE';
        return "{$prefix} VIEW {$this->wrap($name)} AS {$selectSql}";
    }

    /**
     * {@inheritdoc}
     */
    public function compileDropView(string $name, bool $ifExists = true): string
    {
        return "DROP VIEW " . ($ifExists ? 'IF EXISTS ' : '') . $this->wrap($name);
    }

    // ─── Column Compilation ──────────────────────────────────

    /**
     * {@inheritdoc}
     */
    public function compileColumn(ColumnDefinition $column): string
    {
        $sql = $this->wrap($column->name) . ' ' . $this->getColumnType($column);

        // UNSIGNED
        if ($column->isUnsigned && $this->isNumericType($column->type)) {
            $sql .= ' UNSIGNED';
        }

        // CHARACTER SET / COLLATION (inline for string columns)
        if ($column->charset && $this->isStringType($column->type)) {
            $sql .= " CHARACTER SET {$column->charset}";
        }
        if ($column->collation && $this->isStringType($column->type)) {
            $sql .= " COLLATE {$column->collation}";
        }

        // Generated column
        if ($column->virtualAs !== null) {
            $sql .= " AS ({$column->virtualAs}) VIRTUAL";
        } elseif ($column->storedAs !== null) {
            $sql .= " AS ({$column->storedAs}) STORED";
        } else {
            // NULL / NOT NULL
            if ($column->isNullable) {
                $sql .= ' NULL';
            } else {
                $sql .= ' NOT NULL';
            }

            // AUTO_INCREMENT
            if ($column->isAutoIncrement) {
                $sql .= ' AUTO_INCREMENT PRIMARY KEY';
            }

            // DEFAULT
            if ($column->useCurrent) {
                $sql .= ' DEFAULT CURRENT_TIMESTAMP';
            } elseif ($column->hasDefault) {
                $sql .= ' DEFAULT ' . $this->getDefaultValue($column->default);
            }

            // ON UPDATE CURRENT_TIMESTAMP
            if ($column->useCurrentOnUpdate) {
                $sql .= ' ON UPDATE CURRENT_TIMESTAMP';
            }
        }

        // COMMENT
        if ($column->comment !== null) {
            $sql .= " COMMENT '" . str_replace(["'", "\\"], ["''", "\\\\"], $column->comment) . "'";
        }

        return $sql;
    }

    /**
     * {@inheritdoc}
     */
    public function compileForeignKey(ForeignKeyDefinition $fk, string $table): string
    {
        if ($fk->referencedTable === null) {
            throw new \InvalidArgumentException(
                "Foreign key '{$fk->name}' is missing referenced table. Call ->on('table_name') to set it."
            );
        }
        if (empty($fk->referencedColumns)) {
            throw new \InvalidArgumentException(
                "Foreign key '{$fk->name}' is missing referenced columns. Call ->references('column') to set them."
            );
        }

        $sql = "CONSTRAINT {$this->wrap($fk->name)} FOREIGN KEY ({$this->columnize($fk->columns)})";
        $sql .= " REFERENCES {$this->wrap($fk->referencedTable)} ({$this->columnize($fk->referencedColumns)})";
        $sql .= " ON DELETE " . $this->validateForeignKeyAction($fk->onDelete);
        $sql .= " ON UPDATE " . $this->validateForeignKeyAction($fk->onUpdate);

        return $sql;
    }

    // ─── Type Helpers ────────────────────────────────────────

    /**
     * Get the MySQL column type string for a ColumnDefinition.
     */
    protected function getColumnType(ColumnDefinition $column): string
    {
        $type = $this->typeMap[$column->type] ?? 'VARCHAR(255)';

        switch ($column->type) {
            case 'string':
            case 'char':
                $length = $column->parameters['length'] ?? 255;
                return "{$type}({$length})";

            case 'binary':
                $length = $column->parameters['length'] ?? 255;
                return "{$type}({$length})";

            case 'decimal':
            case 'float':
                $precision = $column->parameters['precision'] ?? 8;
                $scale = $column->parameters['scale'] ?? 2;
                return "{$type}({$precision},{$scale})";

            case 'double':
                $precision = $column->parameters['precision'] ?? null;
                $scale = $column->parameters['scale'] ?? null;
                if ($precision !== null && $scale !== null) {
                    return "{$type}({$precision},{$scale})";
                }
                return $type;

            case 'dateTime':
            case 'time':
            case 'timestamp':
                $precision = $column->parameters['precision'] ?? 0;
                return $precision > 0 ? "{$type}({$precision})" : $type;

            case 'enum':
            case 'set':
                $allowed = $column->parameters['allowed'] ?? [];
                $values = implode(', ', array_map(fn($v) => "'" . str_replace(["'", "\\"], ["''", "\\\\"], $v) . "'", $allowed));
                return "{$type}({$values})";

            default:
                return $type;
        }
    }

    /**
     * Check if a column type is numeric.
     */
    protected function isNumericType(string $type): bool
    {
        return in_array($type, [
            'bigInteger', 'integer', 'mediumInteger', 'smallInteger', 'tinyInteger',
            'decimal', 'float', 'double',
        ]);
    }

    /**
     * Check if a column type is string-based.
     */
    protected function isStringType(string $type): bool
    {
        return in_array($type, [
            'string', 'char', 'text', 'mediumText', 'longText', 'tinyText',
            'enum', 'set',
        ]);
    }

    // ─── Input Validation Helpers ─────────────────────────────

    /**
     * Validate SQL SECURITY option value.
     *
     * @throws \InvalidArgumentException
     */
    private function validateSqlSecurity(string $value): string
    {
        $allowed = ['DEFINER', 'INVOKER'];
        $value = strtoupper(trim($value));
        if (!in_array($value, $allowed, true)) {
            throw new \InvalidArgumentException(
                "Invalid SQL SECURITY value '{$value}'. Allowed: " . implode(', ', $allowed)
            );
        }
        return $value;
    }

    /**
     * Validate trigger timing value.
     *
     * @throws \InvalidArgumentException
     */
    private function validateTriggerTiming(string $timing): string
    {
        $allowed = ['BEFORE', 'AFTER'];
        $timing = strtoupper(trim($timing));
        if (!in_array($timing, $allowed, true)) {
            throw new \InvalidArgumentException(
                "Invalid trigger timing '{$timing}'. Allowed: " . implode(', ', $allowed)
            );
        }
        return $timing;
    }

    /**
     * Validate trigger event value.
     *
     * @throws \InvalidArgumentException
     */
    private function validateTriggerEvent(string $event): string
    {
        $allowed = ['INSERT', 'UPDATE', 'DELETE'];
        $event = strtoupper(trim($event));
        if (!in_array($event, $allowed, true)) {
            throw new \InvalidArgumentException(
                "Invalid trigger event '{$event}'. Allowed: " . implode(', ', $allowed)
            );
        }
        return $event;
    }

    /**
     * Validate foreign key action value.
     *
     * @throws \InvalidArgumentException
     */
    private function validateForeignKeyAction(string $action): string
    {
        $allowed = ['CASCADE', 'SET NULL', 'RESTRICT', 'NO ACTION', 'SET DEFAULT'];
        $action = strtoupper(trim($action));
        if (!in_array($action, $allowed, true)) {
            throw new \InvalidArgumentException(
                "Invalid foreign key action '{$action}'. Allowed: " . implode(', ', $allowed)
            );
        }
        return $action;
    }

    /**
     * Validate DEFINER format ('user'@'host' or `user`@`host`).
     *
     * @throws \InvalidArgumentException
     */
    private function validateDefiner(string $definer): string
    {
        $patterns = [
            '/^`[^`]+`@`[^`]+`$/',
            "/^'[^']+'@'[^']+'$/",
            '/^[a-zA-Z0-9_]+@[a-zA-Z0-9_.%]+$/',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $definer)) {
                return $definer;
            }
        }
        throw new \InvalidArgumentException(
            "Invalid DEFINER format '{$definer}'. Expected format: 'user'@'host'"
        );
    }

    /**
     * Validate a SQL identifier (parameter name, etc.).
     * Allows letters, numbers, underscores, and must start with a letter or underscore.
     *
     * @throws \InvalidArgumentException
     */
    private function validateIdentifier(string $name): string
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
            throw new \InvalidArgumentException(
                "Invalid SQL identifier '{$name}'. Only letters, numbers, and underscores are allowed (must start with letter or underscore)."
            );
        }
        return $name;
    }
}
