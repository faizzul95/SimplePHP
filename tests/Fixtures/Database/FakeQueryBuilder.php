<?php

declare(strict_types=1);

namespace Tests\Fixtures\Database;

use Core\Database\BaseDatabase;

/**
 * Satisfies BaseDatabase's 19 abstract driver methods so tests can exercise the
 * builder without a database connection. Override only what a test cares about.
 */
abstract class FakeQueryBuilder extends BaseDatabase
{
    public function __construct(string $table = 'users')
    {
        $this->table = $table;
        $this->column = '*';
        $this->driver = 'mysql';
    }

    public function connect($connectionID = null)
    {
        return $this;
    }

    public function whereDate($column, $operator = null, $value = null)
    {
        return $this;
    }

    public function orWhereDate($column, $operator = null, $value = null)
    {
        return $this;
    }

    public function whereDay($column, $operator = null, $value = null)
    {
        return $this;
    }

    public function orWhereDay($column, $operator = null, $value = null)
    {
        return $this;
    }

    public function whereMonth($column, $operator = null, $value = null)
    {
        return $this;
    }

    public function orWhereMonth($column, $operator = null, $value = null)
    {
        return $this;
    }

    public function whereYear($column, $operator = null, $value = null)
    {
        return $this;
    }

    public function orWhereYear($column, $operator = null, $value = null)
    {
        return $this;
    }

    public function whereTime($column, $operator = null, $value = null)
    {
        return $this;
    }

    public function orWhereTime($column, $operator = null, $value = null)
    {
        return $this;
    }

    public function whereJsonContains($columnName, $jsonPath, $value)
    {
        return $this;
    }

    public function limit($limit)
    {
        return $this;
    }

    public function offset($offset)
    {
        return $this;
    }

    public function count($table = null)
    {
        return 0;
    }

    public function exists($table = null)
    {
        return false;
    }

    public function _getLimitOffsetPaginate($query, $limit, $offset)
    {
        return $query;
    }

    public function batchInsert($data)
    {
        return [];
    }

    public function batchUpdate($data)
    {
        return [];
    }

    public function upsert($values, $uniqueBy = 'id', $updateColumns = null)
    {
        return [];
    }

    protected function sanitizeColumn($data): array
    {
        return is_array($data) ? $data : [];
    }
}
