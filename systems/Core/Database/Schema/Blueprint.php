<?php

namespace Core\Database\Schema;

/**
 * Blueprint — Defines the structure of a database table for schema operations.
 *
 * Provides a fluent API for defining columns, indexes, foreign keys,
 * and other table-level constraints.
 *
 * Usage:
 *   Schema::create('users', function (Blueprint $table) {
 *       $table->id();
 *       $table->string('name');
 *       $table->string('email')->unique();
 *       $table->timestamp('created_at')->nullable()->useCurrent();
 *   });
 *
 * @category  Database
 * @package   Core\Database\Schema
 * @author    Mohd Fahmy Izwan Zulkhafri <faizzul14@gmail.com>
 * @license   http://opensource.org/licenses/gpl-3.0.html GNU Public License
 * @version   1.0.0
 */
class Blueprint
{
    /**
     * @var string The table name.
     */
    protected string $table;

    /**
     * @var ColumnDefinition[] Column definitions to add.
     */
    protected array $columns = [];

    /**
     * @var array Commands to execute (indexes, foreign keys, drops, renames, etc.)
     */
    protected array $commands = [];

    /**
     * @var string|null Storage engine (e.g., InnoDB, MyISAM).
     */
    protected ?string $engine = null;

    /**
     * @var string|null Default charset.
     */
    protected ?string $charset = null;

    /**
     * @var string|null Default collation.
     */
    protected ?string $collation = null;

    /**
     * @var string|null Table comment.
     */
    protected ?string $comment = null;

    /**
     * @var bool Whether this is a temporary table.
     */
    protected bool $temporary = false;

    /**
     * @var bool Whether to use IF NOT EXISTS.
     */
    protected bool $ifNotExists = false;

    /**
     * Create a new Blueprint instance.
     */
    public function __construct(string $table)
    {
        $this->table = $table;
    }

    // ─── Table Options ───────────────────────────────────────

    /**
     * Set the storage engine.
     */
    public function engine(string $engine): self
    {
        $this->engine = $engine;
        return $this;
    }

    /**
     * Set the default charset.
     */
    public function charset(string $charset): self
    {
        $this->charset = $charset;
        return $this;
    }

    /**
     * Set the collation.
     */
    public function collation(string $collation): self
    {
        $this->collation = $collation;
        return $this;
    }

    /**
     * Set a table comment.
     */
    public function comment(string $comment): self
    {
        $this->comment = $comment;
        return $this;
    }

    /**
     * Mark the table as temporary.
     */
    public function temporary(): self
    {
        $this->temporary = true;
        return $this;
    }

    /**
     * Use IF NOT EXISTS clause.
     */
    public function ifNotExists(): self
    {
        $this->ifNotExists = true;
        return $this;
    }

    // ─── Column Type Methods ─────────────────────────────────

    /**
     * Add an auto-incrementing big integer (BIGINT UNSIGNED) primary key column.
     */
    public function id(string $column = 'id'): ColumnDefinition
    {
        return $this->bigIncrements($column);
    }

    /**
     * Add an auto-incrementing BIGINT UNSIGNED column.
     */
    public function bigIncrements(string $column): ColumnDefinition
    {
        return $this->unsignedBigInteger($column)->autoIncrement()->primary();
    }

    /**
     * Add an auto-incrementing INT UNSIGNED column.
     */
    public function increments(string $column): ColumnDefinition
    {
        return $this->unsignedInteger($column)->autoIncrement()->primary();
    }

    /**
     * Add an auto-incrementing SMALLINT UNSIGNED column.
     */
    public function smallIncrements(string $column): ColumnDefinition
    {
        return $this->unsignedSmallInteger($column)->autoIncrement()->primary();
    }

    /**
     * Add an auto-incrementing TINYINT UNSIGNED column.
     */
    public function tinyIncrements(string $column): ColumnDefinition
    {
        return $this->unsignedTinyInteger($column)->autoIncrement()->primary();
    }

    /**
     * Add an auto-incrementing MEDIUMINT UNSIGNED column.
     */
    public function mediumIncrements(string $column): ColumnDefinition
    {
        return $this->unsignedMediumInteger($column)->autoIncrement()->primary();
    }

    /**
     * Add a BIGINT column.
     */
    public function bigInteger(string $column): ColumnDefinition
    {
        return $this->addColumn('bigInteger', $column);
    }

    /**
     * Add a BIGINT UNSIGNED column.
     */
    public function unsignedBigInteger(string $column): ColumnDefinition
    {
        return $this->bigInteger($column)->unsigned();
    }

    /**
     * Add an INT column.
     */
    public function integer(string $column): ColumnDefinition
    {
        return $this->addColumn('integer', $column);
    }

    /**
     * Add an INT UNSIGNED column.
     */
    public function unsignedInteger(string $column): ColumnDefinition
    {
        return $this->integer($column)->unsigned();
    }

    /**
     * Add a MEDIUMINT column.
     */
    public function mediumInteger(string $column): ColumnDefinition
    {
        return $this->addColumn('mediumInteger', $column);
    }

    /**
     * Add a MEDIUMINT UNSIGNED column.
     */
    public function unsignedMediumInteger(string $column): ColumnDefinition
    {
        return $this->mediumInteger($column)->unsigned();
    }

    /**
     * Add a SMALLINT column.
     */
    public function smallInteger(string $column): ColumnDefinition
    {
        return $this->addColumn('smallInteger', $column);
    }

    /**
     * Add a SMALLINT UNSIGNED column.
     */
    public function unsignedSmallInteger(string $column): ColumnDefinition
    {
        return $this->smallInteger($column)->unsigned();
    }

    /**
     * Add a TINYINT column.
     */
    public function tinyInteger(string $column): ColumnDefinition
    {
        return $this->addColumn('tinyInteger', $column);
    }

    /**
     * Add a TINYINT UNSIGNED column.
     */
    public function unsignedTinyInteger(string $column): ColumnDefinition
    {
        return $this->tinyInteger($column)->unsigned();
    }

    /**
     * Add a DECIMAL column.
     */
    public function decimal(string $column, int $precision = 8, int $scale = 2): ColumnDefinition
    {
        return $this->addColumn('decimal', $column, compact('precision', 'scale'));
    }

    /**
     * Add an UNSIGNED DECIMAL column.
     */
    public function unsignedDecimal(string $column, int $precision = 8, int $scale = 2): ColumnDefinition
    {
        return $this->decimal($column, $precision, $scale)->unsigned();
    }

    /**
     * Add a FLOAT column.
     */
    public function float(string $column, int $precision = 8, int $scale = 2): ColumnDefinition
    {
        return $this->addColumn('float', $column, compact('precision', 'scale'));
    }

    /**
     * Add a DOUBLE column.
     */
    public function double(string $column, ?int $precision = null, ?int $scale = null): ColumnDefinition
    {
        return $this->addColumn('double', $column, compact('precision', 'scale'));
    }

    /**
     * Add a VARCHAR column.
     */
    public function string(string $column, int $length = 255): ColumnDefinition
    {
        return $this->addColumn('string', $column, compact('length'));
    }

    /**
     * Add a CHAR column.
     */
    public function char(string $column, int $length = 255): ColumnDefinition
    {
        return $this->addColumn('char', $column, compact('length'));
    }

    /**
     * Add a TEXT column.
     */
    public function text(string $column): ColumnDefinition
    {
        return $this->addColumn('text', $column);
    }

    /**
     * Add a MEDIUMTEXT column.
     */
    public function mediumText(string $column): ColumnDefinition
    {
        return $this->addColumn('mediumText', $column);
    }

    /**
     * Add a LONGTEXT column.
     */
    public function longText(string $column): ColumnDefinition
    {
        return $this->addColumn('longText', $column);
    }

    /**
     * Add a TINYTEXT column.
     */
    public function tinyText(string $column): ColumnDefinition
    {
        return $this->addColumn('tinyText', $column);
    }

    /**
     * Add a BOOLEAN column (TINYINT(1)).
     */
    public function boolean(string $column): ColumnDefinition
    {
        return $this->addColumn('boolean', $column);
    }

    /**
     * Add an ENUM column.
     */
    public function enum(string $column, array $allowed): ColumnDefinition
    {
        return $this->addColumn('enum', $column, compact('allowed'));
    }

    /**
     * Add a SET column.
     */
    public function set(string $column, array $allowed): ColumnDefinition
    {
        return $this->addColumn('set', $column, compact('allowed'));
    }

    /**
     * Add a JSON column.
     */
    public function json(string $column): ColumnDefinition
    {
        return $this->addColumn('json', $column);
    }

    /**
     * Add a JSONB column (JSON for MySQL/MariaDB).
     */
    public function jsonb(string $column): ColumnDefinition
    {
        return $this->addColumn('jsonb', $column);
    }

    /**
     * Add a DATE column.
     */
    public function date(string $column): ColumnDefinition
    {
        return $this->addColumn('date', $column);
    }

    /**
     * Add a DATETIME column.
     */
    public function dateTime(string $column, int $precision = 0): ColumnDefinition
    {
        return $this->addColumn('dateTime', $column, compact('precision'));
    }

    /**
     * Add a TIME column.
     */
    public function time(string $column, int $precision = 0): ColumnDefinition
    {
        return $this->addColumn('time', $column, compact('precision'));
    }

    /**
     * Add a TIMESTAMP column.
     */
    public function timestamp(string $column, int $precision = 0): ColumnDefinition
    {
        return $this->addColumn('timestamp', $column, compact('precision'));
    }

    /**
     * Add a YEAR column.
     */
    public function year(string $column): ColumnDefinition
    {
        return $this->addColumn('year', $column);
    }

    /**
     * Add BINARY column.
     */
    public function binary(string $column, int $length = 255): ColumnDefinition
    {
        return $this->addColumn('binary', $column, compact('length'));
    }

    /**
     * Add BLOB column.
     */
    public function blob(string $column): ColumnDefinition
    {
        return $this->addColumn('blob', $column);
    }

    /**
     * Add a UUID column (CHAR(36)).
     */
    public function uuid(string $column = 'uuid'): ColumnDefinition
    {
        return $this->addColumn('uuid', $column);
    }

    /**
     * Add a UUID primary key.
     */
    public function uuidPrimary(string $column = 'id'): ColumnDefinition
    {
        return $this->uuid($column)->primary();
    }

    /**
     * Add an IP address column (VARCHAR(45) — supports both IPv4 and IPv6).
     */
    public function ipAddress(string $column = 'ip_address'): ColumnDefinition
    {
        return $this->string($column, 45);
    }

    /**
     * Add a MAC address column (VARCHAR(17)).
     */
    public function macAddress(string $column = 'mac_address'): ColumnDefinition
    {
        return $this->string($column, 17);
    }

    // ─── Shortcut / Convention Methods ───────────────────────

    /**
     * Add created_at and updated_at TIMESTAMP columns.
     */
    public function timestamps(int $precision = 0): void
    {
        $this->timestamp('created_at', $precision)->nullable()->useCurrent();
        $this->timestamp('updated_at', $precision)->nullable()->useCurrentOnUpdate();
    }

    /**
     * Add nullable created_at and updated_at DATETIME columns.
     */
    public function nullableTimestamps(int $precision = 0): void
    {
        $this->dateTime('created_at', $precision)->nullable();
        $this->dateTime('updated_at', $precision)->nullable();
    }

    /**
     * Add a deleted_at TIMESTAMP column for soft deletes.
     */
    public function softDeletes(string $column = 'deleted_at', int $precision = 0): ColumnDefinition
    {
        return $this->timestamp($column, $precision)->nullable();
    }

    /**
     * Add a polymorphic morphs helper (e.g., 'commentable_type' + 'commentable_id').
     */
    public function morphs(string $name, ?string $indexName = null): void
    {
        $this->string("{$name}_type");
        $this->unsignedBigInteger("{$name}_id");
        $this->index(["{$name}_type", "{$name}_id"], $indexName);
    }

    /**
     * Add nullable polymorphic morphs.
     */
    public function nullableMorphs(string $name, ?string $indexName = null): void
    {
        $this->string("{$name}_type")->nullable();
        $this->unsignedBigInteger("{$name}_id")->nullable();
        $this->index(["{$name}_type", "{$name}_id"], $indexName);
    }

    /**
     * Add a foreign ID column (unsignedBigInteger + foreign key).
     *
     * Usage:
     *   $table->foreignId('user_id')->constrained();            // references users(id)
     *   $table->foreignId('user_id')->constrained('accounts');  // references accounts(id)
     */
    public function foreignId(string $column): ColumnDefinition
    {
        return $this->unsignedBigInteger($column);
    }

    /**
     * Add a remember_token column.
     */
    public function rememberToken(string $column = 'remember_token'): ColumnDefinition
    {
        return $this->string($column, 100)->nullable();
    }

    // ─── Index Methods ───────────────────────────────────────

    /**
     * Add a primary key.
     *
     * @param string|array $columns
     */
    public function primary(string|array $columns, ?string $name = null): self
    {
        $this->addCommand('primary', [
            'columns' => (array) $columns,
            'name'    => $name,
        ]);

        return $this;
    }

    /**
     * Add a unique index.
     *
     * @param string|array $columns
     */
    public function unique(string|array $columns, ?string $name = null): self
    {
        $this->addCommand('unique', [
            'columns' => (array) $columns,
            'name'    => $name,
        ]);

        return $this;
    }

    /**
     * Add a regular index.
     *
     * @param string|array $columns
     */
    public function index(string|array $columns, ?string $name = null): self
    {
        $this->addCommand('index', [
            'columns' => (array) $columns,
            'name'    => $name,
        ]);

        return $this;
    }

    /**
     * Add a fulltext index.
     *
     * @param string|array $columns
     */
    public function fulltext(string|array $columns, ?string $name = null): self
    {
        $this->addCommand('fulltext', [
            'columns' => (array) $columns,
            'name'    => $name,
        ]);

        return $this;
    }

    /**
     * Add a spatial index.
     *
     * @param string|array $columns
     */
    public function spatialIndex(string|array $columns, ?string $name = null): self
    {
        $this->addCommand('spatialIndex', [
            'columns' => (array) $columns,
            'name'    => $name,
        ]);

        return $this;
    }

    /**
     * Drop an index by name.
     */
    public function dropIndex(string|array $indexOrColumns): self
    {
        $name = is_array($indexOrColumns)
            ? $this->generateIndexName('index', $indexOrColumns)
            : $indexOrColumns;

        $this->addCommand('dropIndex', ['name' => $name]);
        return $this;
    }

    /**
     * Drop a unique index.
     */
    public function dropUnique(string|array $indexOrColumns): self
    {
        $name = is_array($indexOrColumns)
            ? $this->generateIndexName('unique', $indexOrColumns)
            : $indexOrColumns;

        $this->addCommand('dropUnique', ['name' => $name]);
        return $this;
    }

    /**
     * Drop a primary key.
     */
    public function dropPrimary(?string $name = null): self
    {
        $this->addCommand('dropPrimary', ['name' => $name]);
        return $this;
    }

    /**
     * Drop a fulltext index.
     */
    public function dropFulltext(string|array $indexOrColumns): self
    {
        $name = is_array($indexOrColumns)
            ? $this->generateIndexName('fulltext', $indexOrColumns)
            : $indexOrColumns;

        $this->addCommand('dropFulltext', ['name' => $name]);
        return $this;
    }

    // ─── Foreign Key Methods ─────────────────────────────────

    /**
     * Add a foreign key constraint.
     *
     * Usage:
     *   $table->foreign('user_id')->references('id')->on('users')->onDelete('CASCADE');
     */
    public function foreign(string|array $columns, ?string $name = null): ForeignKeyDefinition
    {
        $fk = new ForeignKeyDefinition((array) $columns, $name ?? $this->generateIndexName('fk', (array) $columns));
        $this->addCommand('foreign', ['definition' => $fk]);
        return $fk;
    }

    /**
     * Drop a foreign key.
     */
    public function dropForeign(string|array $nameOrColumns): self
    {
        $name = is_array($nameOrColumns)
            ? $this->generateIndexName('fk', $nameOrColumns)
            : $nameOrColumns;

        $this->addCommand('dropForeign', ['name' => $name]);
        return $this;
    }

    // ─── Column Modification Methods ─────────────────────────

    /**
     * Drop one or more columns.
     *
     * @param string|array $columns
     */
    public function dropColumn(string|array $columns): self
    {
        $this->addCommand('dropColumn', ['columns' => (array) $columns]);
        return $this;
    }

    /**
     * Rename a column.
     */
    public function renameColumn(string $from, string $to): self
    {
        $this->addCommand('renameColumn', compact('from', 'to'));
        return $this;
    }

    /**
     * Rename the table.
     */
    public function rename(string $to): self
    {
        $this->addCommand('rename', ['to' => $to]);
        return $this;
    }

    // ─── Internal Accessors ──────────────────────────────────

    /**
     * Get the table name.
     */
    public function getTable(): string
    {
        return $this->table;
    }

    /**
     * Get all column definitions.
     *
     * @return ColumnDefinition[]
     */
    public function getColumns(): array
    {
        return $this->columns;
    }

    /**
     * Get all commands.
     */
    public function getCommands(): array
    {
        return $this->commands;
    }

    /**
     * Get the engine.
     */
    public function getEngine(): ?string
    {
        return $this->engine;
    }

    /**
     * Get the charset.
     */
    public function getCharset(): ?string
    {
        return $this->charset;
    }

    /**
     * Get the collation.
     */
    public function getCollation(): ?string
    {
        return $this->collation;
    }

    /**
     * Get the table comment.
     */
    public function getComment(): ?string
    {
        return $this->comment;
    }

    /**
     * Whether the table is temporary.
     */
    public function isTemporary(): bool
    {
        return $this->temporary;
    }

    /**
     * Whether to use IF NOT EXISTS.
     */
    public function isIfNotExists(): bool
    {
        return $this->ifNotExists;
    }

    // ─── Internal Helpers ────────────────────────────────────

    /**
     * Add a column definition.
     */
    protected function addColumn(string $type, string $name, array $parameters = []): ColumnDefinition
    {
        $column = new ColumnDefinition($name, $type, $parameters);
        $this->columns[] = $column;
        return $column;
    }

    /**
     * Add a command to the blueprint.
     */
    protected function addCommand(string $type, array $parameters = []): void
    {
        $this->commands[] = array_merge(['type' => $type], $parameters);
    }

    /**
     * Generate an index name based on convention: {table}_{columns}_{type}
     */
    protected function generateIndexName(string $type, array $columns): string
    {
        return strtolower($this->table . '_' . implode('_', $columns) . '_' . $type);
    }
}
