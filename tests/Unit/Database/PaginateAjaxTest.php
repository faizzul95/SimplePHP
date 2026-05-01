<?php

declare(strict_types=1);

use Core\Database\BaseDatabase;
use PHPUnit\Framework\TestCase;

final class PaginateAjaxTest extends TestCase
{
    public function testPaginateAjaxClampsUnsafeInputsAndNormalizesOrdering(): void
    {
        $database = new class extends BaseDatabase {
            public array $capturedPaginate = [];
            public array $capturedOrderBy = [];

            public function __construct()
            {
            }

            public function connect($connectionID = null)
            {
                return $this;
            }

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

            public function getTableColumns($table = null)
            {
                return ['id', 'name', 'email'];
            }

            public function orderBy($column, $direction = 'ASC')
            {
                $this->capturedOrderBy = [$column, $direction];
                return $this;
            }

            public function paginate($start = 0, $limit = 10, $draw = 1)
            {
                $this->capturedPaginate = [$start, $limit, $draw, $this->_paginateFilterValue];
                return $this->capturedPaginate;
            }
        };

        $result = $database->paginate_ajax([
            'draw' => '0',
            'start' => '-40',
            'length' => '-1',
            'search' => ['value' => str_repeat('x', 400)],
            'order' => [[
                'column' => '1',
                'dir' => 'drop table',
            ]],
        ]);

        self::assertSame(['name', 'ASC'], $database->capturedOrderBy);
        self::assertSame([0, 500, 1, str_repeat('x', 255)], $result);
    }

    public function testPaginateAjaxUsesApproximateCountWhenEnabledWithoutSearchFilter(): void
    {
        $database = new class extends BaseDatabase {
            public int $countCalls = 0;
            public int $approximateCountCalls = 0;

            public function __construct()
            {
                $this->table = 'users';
                $this->column = 'id';
            }

            public function connect($connectionID = null)
            {
                return $this;
            }

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
            public function count($table = null) { $this->countCalls++; return 0; }
            public function exists($table = null) { return false; }
            public function _getLimitOffsetPaginate($query, $limit, $offset) { return $query; }
            public function batchInsert($data) { return []; }
            public function batchUpdate($data) { return []; }
            public function upsert($values, $uniqueBy = 'id', $updateColumns = null) { return []; }
            protected function sanitizeColumn($data) { return $data; }

            public function approximateCount(): ?int
            {
                $this->approximateCountCalls++;
                return 321;
            }

            public function getTableColumns($table = null)
            {
                return ['id', 'name'];
            }

            protected function _buildSelectQuery()
            {
                $this->_query = 'SELECT id FROM users';
            }

            protected function _prepareStatement($query)
            {
                return new class {
                    public function execute(): void
                    {
                    }

                    public function fetchAll($mode): array
                    {
                        return [];
                    }
                };
            }
        };

        $result = $database
            ->useApproximateCount(true)
            ->paginate_ajax([
                'draw' => 2,
                'start' => 0,
                'length' => 25,
                'search' => ['value' => ''],
            ]);

        self::assertSame(321, $result['recordsTotal']);
        self::assertSame(321, $result['recordsFiltered']);
        self::assertSame(0, $database->countCalls);
        self::assertSame(1, $database->approximateCountCalls);
    }

    public function testPaginateAjaxUsesExplicitAllowedSortColumnsWhenConfigured(): void
    {
        $database = new class extends BaseDatabase {
            public array $capturedOrderBy = [];

            public function __construct()
            {
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

            public function getTableColumns($table = null)
            {
                return ['unsafe_id', 'unsafe_name'];
            }

            public function orderBy($column, $direction = 'ASC')
            {
                $this->capturedOrderBy = [$column, $direction];
                return $this;
            }

            public function paginate($start = 0, $limit = 10, $draw = 1)
            {
                return ['draw' => $draw, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []];
            }
        };

        $database
            ->setPaginateFilterColumn(['name', 'email'])
            ->setAllowedSortColumns(['users.name', 'users.email'])
            ->paginate_ajax([
                'order' => [[
                    'column' => 1,
                    'dir' => 'desc',
                ]],
            ]);

        self::assertSame(['users.email', 'DESC'], $database->capturedOrderBy);
    }

    public function testResetClearsPaginationColumnsSortColumnsAndApproximateCountFlag(): void
    {
        $database = new class extends BaseDatabase {
            public function __construct()
            {
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

            public function exposePaginationState(): array
            {
                return [
                    'columns' => $this->_paginateColumn,
                    'sort_columns' => $this->_paginateAllowedSortColumns,
                    'filter' => $this->_paginateFilterValue,
                    'approximate' => $this->_useApproximateCount,
                ];
            }
        };

        $database
            ->setPaginateFilterColumn(['name'])
            ->setAllowedSortColumns(['users.name'])
            ->useApproximateCount(true)
            ->reset();

        self::assertSame(
            [
                'columns' => [],
                'sort_columns' => [],
                'filter' => null,
                'approximate' => false,
            ],
            $database->exposePaginationState()
        );
    }
}