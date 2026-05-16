<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use Core\Database\Model;
use Core\Database\ModelQuery;
use PHPUnit\Framework\TestCase;

final class ModelQueryAllowlistTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        reset_framework_service();
        require_once ROOT_DIR . 'systems/app.php';
    }

    public function testNewQuerySeedsBuilderWithModelSortableAndFilterableColumns(): void
    {
        $runtime = new class {
            public object $builder;

            public function __construct()
            {
                $this->builder = new class {
                    public ?string $table = null;
                    public array $sortable = [];
                    public array $filterable = [];

                    public function table($table)
                    {
                        $this->table = $table;
                        return $this;
                    }

                    public function setSortableColumns(array $columns)
                    {
                        $this->sortable = $columns;
                        return $this;
                    }

                    public function setFilterableColumns(array $columns)
                    {
                        $this->filterable = $columns;
                        return $this;
                    }

                    public function whereNull($column)
                    {
                        return $this;
                    }
                };
            }

            public function connection(string $connectionName = 'default')
            {
                return $this->builder;
            }
        };

        register_framework_service('database.runtime', fn() => $runtime);

        $model = new class extends Model {
            protected string $table = 'users';
            protected array $sortable = ['users.name'];
            protected array $filterable = ['users.email'];

            public function exposeNewQuery(): ModelQuery
            {
                return $this->newQuery();
            }
        };

        $query = $model->exposeNewQuery();

        self::assertInstanceOf(ModelQuery::class, $query);
        self::assertSame('users', $runtime->builder->table);
        self::assertSame(['users.name'], $runtime->builder->sortable);
        self::assertSame(['users.email'], $runtime->builder->filterable);
    }
}