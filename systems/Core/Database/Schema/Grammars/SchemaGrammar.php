<?php

namespace Core\Database\Schema\Grammars;

use Core\Database\Schema\Blueprint;
use Core\Database\Schema\ColumnDefinition;
use Core\Database\Schema\ForeignKeyDefinition;

/**
 * SchemaGrammar — Abstract base class for compiling Blueprint definitions into SQL.
 *
 * Each database driver extends this class to generate driver-specific DDL statements.
 *
 * @category  Database
 * @package   Core\Database\Schema\Grammars
 * @author    Mohd Fahmy Izwan Zulkhafri <faizzul14@gmail.com>
 * @license   http://opensource.org/licenses/gpl-3.0.html GNU Public License
 * @version   1.0.0
 */
abstract class SchemaGrammar
{
    // ─── Table Operations ────────────────────────────────────

    /**
     * Compile a CREATE TABLE statement.
     *
     * @return string[]  One or more SQL statements
     */
    abstract public function compileCreate(Blueprint $blueprint): array;

    /**
     * Compile an ALTER TABLE statement (add/modify columns, add/drop indexes, etc.).
     *
     * @return string[]
     */
    abstract public function compileAlter(Blueprint $blueprint): array;

    /**
     * Compile a DROP TABLE statement.
     */
    abstract public function compileDrop(string $table): string;

    /**
     * Compile a DROP TABLE IF EXISTS statement.
     */
    abstract public function compileDropIfExists(string $table): string;

    /**
     * Compile a RENAME TABLE statement.
     */
    abstract public function compileRename(string $from, string $to): string;

    /**
     * Compile a TRUNCATE TABLE statement.
     */
    abstract public function compileTruncate(string $table): string;

    // ─── Introspection ───────────────────────────────────────

    /**
     * Compile a query to check if a table exists.
     */
    abstract public function compileTableExists(): string;

    /**
     * Compile a query to check if a column exists.
     */
    abstract public function compileColumnExists(string $table): string;

    /**
     * Compile a query to list all columns of a table.
     */
    abstract public function compileColumnListing(string $table): string;

    /**
     * Compile a query to list all indexes on a table.
     */
    abstract public function compileIndexListing(string $table): string;

    /**
     * Compile a query to list all foreign keys on a table.
     */
    abstract public function compileForeignKeyListing(string $table): string;

    // ─── Stored Procedures / Functions ───────────────────────

    /**
     * Compile a CREATE PROCEDURE statement.
     *
     * @param string $name       Procedure name
     * @param array  $parameters Array of ['name' => ..., 'direction' => IN|OUT|INOUT, 'type' => ...]
     * @param string $body       The procedure body (SQL statements)
     * @param array  $options    Optional: definer, comment, deterministic, etc.
     */
    abstract public function compileCreateProcedure(string $name, array $parameters, string $body, array $options = []): string;

    /**
     * Compile a DROP PROCEDURE statement.
     */
    abstract public function compileDropProcedure(string $name, bool $ifExists = true): string;

    /**
     * Compile a CREATE FUNCTION statement.
     *
     * @param string $name       Function name
     * @param array  $parameters Array of ['name' => ..., 'type' => ...]
     * @param string $returnType Return data type
     * @param string $body       The function body
     * @param array  $options    Optional: definer, comment, deterministic, etc.
     */
    abstract public function compileCreateFunction(string $name, array $parameters, string $returnType, string $body, array $options = []): string;

    /**
     * Compile a DROP FUNCTION statement.
     */
    abstract public function compileDropFunction(string $name, bool $ifExists = true): string;

    /**
     * Compile a query to list all stored procedures.
     */
    abstract public function compileProcedureListing(?string $database = null): string;

    /**
     * Compile a query to list all stored functions.
     */
    abstract public function compileFunctionListing(?string $database = null): string;

    // ─── Triggers ────────────────────────────────────────────

    /**
     * Compile a CREATE TRIGGER statement.
     *
     * @param string $name    Trigger name
     * @param string $table   Table the trigger acts on
     * @param string $timing  BEFORE | AFTER
     * @param string $event   INSERT | UPDATE | DELETE
     * @param string $body    Trigger body
     * @param array  $options Optional: definer, etc.
     */
    abstract public function compileCreateTrigger(string $name, string $table, string $timing, string $event, string $body, array $options = []): string;

    /**
     * Compile a DROP TRIGGER statement.
     */
    abstract public function compileDropTrigger(string $name, bool $ifExists = true): string;

    // ─── Views ───────────────────────────────────────────────

    /**
     * Compile a CREATE VIEW statement.
     */
    abstract public function compileCreateView(string $name, string $selectSql, bool $orReplace = false): string;

    /**
     * Compile a DROP VIEW statement.
     */
    abstract public function compileDropView(string $name, bool $ifExists = true): string;

    // ─── Column Helpers ──────────────────────────────────────

    /**
     * Compile a single column definition to SQL fragment.
     */
    abstract public function compileColumn(ColumnDefinition $column): string;

    /**
     * Compile a foreign key definition to SQL fragment.
     */
    abstract public function compileForeignKey(ForeignKeyDefinition $fk, string $table): string;

    // ─── Utility ─────────────────────────────────────────────

    /**
     * Wrap a table or column name with identifier quotes.
     */
    public function wrap(string $value): string
    {
        if ($value === '') {
            throw new \InvalidArgumentException('Cannot wrap an empty identifier.');
        }

        // Already wrapped
        if (str_starts_with($value, '`')) {
            return $value;
        }

        // Handle schema.table notation
        if (str_contains($value, '.')) {
            return implode('.', array_map([$this, 'wrap'], explode('.', $value)));
        }

        return '`' . str_replace('`', '``', $value) . '`';
    }

    /**
     * Wrap an array of column names.
     *
     * @return string Comma-separated wrapped names
     */
    public function columnize(array $columns): string
    {
        return implode(', ', array_map([$this, 'wrap'], $columns));
    }

    /**
     * Get the default value SQL representation.
     */
    protected function getDefaultValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if ($value === null) {
            return 'NULL';
        }

        return "'" . str_replace(["'", "\\"], ["''", "\\\\"], (string) $value) . "'";
    }
}
