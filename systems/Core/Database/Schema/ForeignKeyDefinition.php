<?php

namespace Core\Database\Schema;

/**
 * ForeignKeyDefinition — Represents a foreign key constraint.
 *
 * Usage:
 *   $table->foreign('user_id')->references('id')->on('users')->onDelete('CASCADE');
 *
 * @category  Database
 * @package   Core\Database\Schema
 * @author    Mohd Fahmy Izwan Zulkhafri <faizzul14@gmail.com>
 * @license   http://opensource.org/licenses/gpl-3.0.html GNU Public License
 * @version   1.0.0
 */
class ForeignKeyDefinition
{
    public array $columns;
    public string $name;
    public ?string $referencedTable = null;
    public array $referencedColumns = [];
    public string $onDelete = 'RESTRICT';
    public string $onUpdate = 'RESTRICT';

    public function __construct(array $columns, string $name)
    {
        $this->columns = $columns;
        $this->name = $name;
    }

    /**
     * Set the referenced column(s).
     *
     * @param string|array $columns
     */
    public function references(string|array $columns): self
    {
        $this->referencedColumns = (array) $columns;
        return $this;
    }

    /**
     * Set the referenced table.
     */
    public function on(string $table): self
    {
        $this->referencedTable = $table;
        return $this;
    }

    /**
     * Set ON DELETE action.
     */
    public function onDelete(string $action): self
    {
        $this->onDelete = strtoupper($action);
        return $this;
    }

    /**
     * Set ON UPDATE action.
     */
    public function onUpdate(string $action): self
    {
        $this->onUpdate = strtoupper($action);
        return $this;
    }

    /**
     * Shortcut: ON DELETE CASCADE.
     */
    public function cascadeOnDelete(): self
    {
        return $this->onDelete('CASCADE');
    }

    /**
     * Shortcut: ON DELETE SET NULL.
     */
    public function nullOnDelete(): self
    {
        return $this->onDelete('SET NULL');
    }

    /**
     * Shortcut: ON DELETE RESTRICT.
     */
    public function restrictOnDelete(): self
    {
        return $this->onDelete('RESTRICT');
    }

    /**
     * Shortcut: ON UPDATE CASCADE.
     */
    public function cascadeOnUpdate(): self
    {
        return $this->onUpdate('CASCADE');
    }

    /**
     * Shortcut: ON UPDATE RESTRICT.
     */
    public function restrictOnUpdate(): self
    {
        return $this->onUpdate('RESTRICT');
    }
}
