<?php

declare(strict_types=1);

use Core\Database\BaseDatabase;
use Core\Database\DatabaseHelper;
use PHPUnit\Framework\TestCase;

final class DatabaseHelperSanitizationProbe extends DatabaseHelper
{
    public function sanitizeValue(mixed $value): mixed
    {
        return $this->sanitize($value);
    }

    public function normalizeValue(mixed $value): mixed
    {
        return $this->normalizeDatabaseValue($value);
    }
}

final class DatabaseSanitizationTest extends TestCase
{
    public function testDatabaseHelperEscapesHtmlStringsForSafeOutputBoundary(): void
    {
        $helper = new DatabaseHelperSanitizationProbe();

        $result = $helper->sanitizeValue('  <b>admin</b>  ');

        self::assertSame('&lt;b&gt;admin&lt;/b&gt;', $result);
    }

    public function testDatabaseHelperNormalizesRawArrayKeysAndValuesForPersistenceBoundary(): void
    {
        $helper = new DatabaseHelperSanitizationProbe();

        $result = $helper->normalizeValue([' profile ' => ' <i>active</i> ']);

        self::assertSame(['profile' => '<i>active</i>'], $result);
    }

    public function testSafeOutputEscapesDatabaseResults(): void
    {
        $database = new class extends BaseDatabase {
            public function __construct()
            {
            }

            public function inspectOutput(mixed $value): mixed
            {
                $this->safeOutput(true);
                return $this->_safeOutputSanitize($value);
            }

            public function connect($connectionID = null) { return $this; }
            public function whereDate($column, $operator = null, $value = null) { return $this; }
            public function orWhereDate($column, $operator = null, $value = null) { return $this; }
            public function whereDay($column, $operator = null, $value = null) { return $this; }
            public function orWhereDay($column, $operator = null, $value = null) { return $this; }
            public function whereMonth($column, $operator = null, $value = null) { return $this; }
            public function orWhereMonth($column, $operator = null, $value = null) { return $this; }
            public function whereYear($column, $operator = null, $value = null) { return $this; }
            public function orWhereYear($column, $operator = null, $value = null) { return $this; }
            public function whereTime($column, $operator = null, $value = null) { return $this; }
            public function orWhereTime($column, $operator = null, $value = null) { return $this; }
            public function whereJsonContains($columnName, $jsonPath, $value) { return $this; }
            public function limit($limit) { return $this; }
            public function offset($offset) { return $this; }
            public function count($table = null) { return 0; }
            public function exists($table = null) { return false; }
            public function _getLimitOffsetPaginate($query, $limit, $offset) { return $query; }
            public function batchInsert($data) { return []; }
            public function batchUpdate($data) { return []; }
            public function upsert($values, $uniqueBy = 'id', $updateColumns = null) { return []; }
            protected function sanitizeColumn($data) { return $data; }
        };

        $result = $database->inspectOutput(['bio' => '<script>alert(1)</script>']);

        self::assertSame(['bio' => '&lt;script&gt;alert(1)&lt;/script&gt;'], $result);
    }
}