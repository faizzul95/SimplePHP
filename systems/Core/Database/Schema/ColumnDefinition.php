<?php

namespace Core\Database\Schema;

/**
 * ColumnDefinition — Represents a single column in a Blueprint.
 *
 * Provides a fluent API for setting column modifiers like nullable, default, etc.
 *
 * @category  Database
 * @package   Core\Database\Schema
 * @author    Mohd Fahmy Izwan Zulkhafri <faizzul14@gmail.com>
 * @license   http://opensource.org/licenses/gpl-3.0.html GNU Public License
 * @version   1.0.0
 */
class ColumnDefinition
{
    public string $name;
    public string $type;
    public array $parameters;

    public bool $isNullable = false;
    public bool $isUnsigned = false;
    public bool $isAutoIncrement = false;
    public bool $isPrimary = false;
    public bool $isUnique = false;
    public bool $isIndex = false;

    public mixed $default = null;
    public bool $hasDefault = false;

    public bool $useCurrent = false;
    public bool $useCurrentOnUpdate = false;

    public ?string $after = null;
    public bool $first = false;
    public ?string $comment = null;
    public ?string $collation = null;
    public ?string $charset = null;
    public ?string $virtualAs = null;
    public ?string $storedAs = null;

    /** @var bool Whether this is a column modification (ALTER MODIFY). */
    public bool $change = false;

    /** @var string|null Constrained table for foreignId() -> constrained() shortcut. */
    public ?string $constrainedTable = null;
    public ?string $constrainedColumn = null;
    public ?string $onDelete = null;
    public ?string $onUpdate = null;

    public function __construct(string $name, string $type, array $parameters = [])
    {
        $this->name = $name;
        $this->type = $type;
        $this->parameters = $parameters;
    }

    // ─── Modifiers ───────────────────────────────────────────

    /**
     * Allow NULL values.
     */
    public function nullable(bool $value = true): self
    {
        $this->isNullable = $value;
        return $this;
    }

    /**
     * Make column UNSIGNED.
     */
    public function unsigned(): self
    {
        $this->isUnsigned = true;
        return $this;
    }

    /**
     * Enable AUTO_INCREMENT.
     */
    public function autoIncrement(): self
    {
        $this->isAutoIncrement = true;
        return $this;
    }

    /**
     * Set this column as PRIMARY KEY.
     */
    public function primary(): self
    {
        $this->isPrimary = true;
        return $this;
    }

    /**
     * Add a UNIQUE constraint.
     */
    public function unique(): self
    {
        $this->isUnique = true;
        return $this;
    }

    /**
     * Add an INDEX.
     */
    public function index(): self
    {
        $this->isIndex = true;
        return $this;
    }

    /**
     * Set the default value.
     */
    public function default(mixed $value): self
    {
        $this->default = $value;
        $this->hasDefault = true;
        return $this;
    }

    /**
     * Use CURRENT_TIMESTAMP as default.
     */
    public function useCurrent(): self
    {
        $this->useCurrent = true;
        return $this;
    }

    /**
     * Use CURRENT_TIMESTAMP ON UPDATE for the column.
     */
    public function useCurrentOnUpdate(): self
    {
        $this->useCurrentOnUpdate = true;
        return $this;
    }

    /**
     * Place this column after another column (ALTER TABLE).
     */
    public function after(string $column): self
    {
        $this->after = $column;
        return $this;
    }

    /**
     * Place this column first (ALTER TABLE).
     */
    public function first(): self
    {
        $this->first = true;
        return $this;
    }

    /**
     * Add a column comment.
     */
    public function comment(string $comment): self
    {
        $this->comment = $comment;
        return $this;
    }

    /**
     * Set column collation.
     */
    public function collation(string $collation): self
    {
        $this->collation = $collation;
        return $this;
    }

    /**
     * Set column charset.
     */
    public function charset(string $charset): self
    {
        $this->charset = $charset;
        return $this;
    }

    /**
     * Set column as a virtual generated column.
     */
    public function virtualAs(string $expression): self
    {
        $this->virtualAs = $expression;
        return $this;
    }

    /**
     * Set column as a stored generated column.
     */
    public function storedAs(string $expression): self
    {
        $this->storedAs = $expression;
        return $this;
    }

    /**
     * Mark this column for modification (used in ALTER TABLE).
     */
    public function change(): self
    {
        $this->change = true;
        return $this;
    }

    /**
     * Add a foreign key constraint via constrained() shortcut.
     *
     * Usage:
     *   $table->foreignId('user_id')->constrained();           // FK to users(id)
     *   $table->foreignId('user_id')->constrained('accounts'); // FK to accounts(id)
     */
    public function constrained(?string $table = null, string $column = 'id'): self
    {
        if ($table === null) {
            // Derive table name from column: user_id -> users
            $table = str_replace('_id', '', $this->name) . 's';
        }

        $this->constrainedTable = $table;
        $this->constrainedColumn = $column;
        return $this;
    }

    /**
     * Set ON DELETE action for constrained column.
     */
    public function onDelete(string $action): self
    {
        $this->onDelete = strtoupper($action);
        return $this;
    }

    /**
     * Shortcut for ON DELETE CASCADE.
     */
    public function cascadeOnDelete(): self
    {
        return $this->onDelete('CASCADE');
    }

    /**
     * Shortcut for ON DELETE SET NULL.
     */
    public function nullOnDelete(): self
    {
        $this->isNullable = true;
        return $this->onDelete('SET NULL');
    }

    /**
     * Shortcut for ON DELETE RESTRICT.
     */
    public function restrictOnDelete(): self
    {
        return $this->onDelete('RESTRICT');
    }

    /**
     * Set ON UPDATE action for constrained column.
     */
    public function onUpdate(string $action): self
    {
        $this->onUpdate = strtoupper($action);
        return $this;
    }

    /**
     * Shortcut for ON UPDATE CASCADE.
     */
    public function cascadeOnUpdate(): self
    {
        return $this->onUpdate('CASCADE');
    }

    /**
     * Shortcut for ON UPDATE RESTRICT.
     */
    public function restrictOnUpdate(): self
    {
        return $this->onUpdate('RESTRICT');
    }
}
