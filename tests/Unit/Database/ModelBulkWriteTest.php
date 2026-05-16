<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use Core\Database\Model;
use PHPUnit\Framework\TestCase;

final class ModelBulkWriteBuilderStub
{
    public array $batchInsertCalls = [];
    public array $batchUpdateCalls = [];
    public array $upsertCalls = [];
    public array $chunkCalls = [];
    public array $chunkByIdCalls = [];
    public array $chunkRows = [];
    public array $chunkByIdRows = [];
    public array $whereInCalls = [];
    public array $whereCalls = [];
    public array $deleteCalls = [];
    public array $updateCalls = [];
    public array $getResultsQueue = [];

    public function table($table)
    {
        return $this;
    }

    public function setFillable(array $columns)
    {
        return $this;
    }

    public function setGuarded(array $columns)
    {
        return $this;
    }

    public function setSortableColumns(array $columns)
    {
        return $this;
    }

    public function setFilterableColumns(array $columns)
    {
        return $this;
    }

    public function whereNull($column)
    {
        return $this;
    }

    public function batchInsert(array $rows): array
    {
        $this->batchInsertCalls[] = $rows;

        return ['code' => 201, 'affected_rows' => count($rows)];
    }

    public function batchUpdate(array $rows): array
    {
        $this->batchUpdateCalls[] = $rows;

        return ['code' => 200, 'affected_rows' => count($rows)];
    }

    public function upsert(array $rows, string|array $uniqueBy = 'id', ?array $updateColumns = null): array
    {
        $this->upsertCalls[] = [$rows, $uniqueBy, $updateColumns];

        return ['code' => 200, 'affected_rows' => count($rows)];
    }

    public function chunk(int $size, callable $callback)
    {
        $this->chunkCalls[] = $size;

        foreach ($this->chunkRows as $rows) {
            if ($callback($rows) === false) {
                break;
            }
        }

        return $this;
    }

    public function chunkById(int $size, callable $callback, string $column = 'id', ?string $alias = null)
    {
        $this->chunkByIdCalls[] = [$size, $column, $alias];

        foreach ($this->chunkByIdRows as $rows) {
            if ($callback($rows) === false) {
                break;
            }
        }

        return $this;
    }

    public function whereIn($column, $values)
    {
        $this->whereInCalls[] = [$column, $values];

        return $this;
    }

    public function where($column, $operator = null, $value = null)
    {
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }

        $this->whereCalls[] = [$column, $operator, $value];

        return $this;
    }

    public function delete()
    {
        $this->deleteCalls[] = end($this->whereInCalls);

        return true;
    }

    public function update(array $attributes)
    {
        $where = end($this->whereCalls);
        $whereIn = end($this->whereInCalls);
        $this->updateCalls[] = ['where' => $where !== false ? $where : $whereIn, 'attributes' => $attributes];

        return true;
    }

    public function get($table = null)
    {
        return array_shift($this->getResultsQueue) ?? [];
    }
}

final class ModelBulkWriteRuntimeStub
{
    public function __construct(public ModelBulkWriteBuilderStub $builder)
    {
    }

    public function connection(string $connectionName = 'default')
    {
        return $this->builder;
    }
}

final class ModelBulkWriteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        reset_framework_service();
        require_once ROOT_DIR . 'systems/app.php';
    }

    public function testBulkInsertUsesGuardAwareBatchesWithTimestamps(): void
    {
        $builder = new ModelBulkWriteBuilderStub();
        register_framework_service('database.runtime', fn() => new ModelBulkWriteRuntimeStub($builder));

        $model = new class extends Model {
            protected string $table = 'users';
            protected array $fillable = ['name', 'email'];
            protected array $guarded = ['is_admin'];
            protected int $bulkWriteBatchSize = 2;

            protected function freshTimestamp(): string
            {
                return '2026-05-16 12:00:00';
            }
        };

        self::assertTrue($model::bulkInsert([
            ['name' => 'Alpha', 'email' => 'a@example.test', 'is_admin' => 1],
            ['name' => 'Beta', 'email' => 'b@example.test', 'is_admin' => 1],
            ['name' => 'Gamma', 'email' => 'c@example.test', 'ignored' => 'x'],
        ]));

        self::assertCount(2, $builder->batchInsertCalls);
        self::assertCount(2, $builder->batchInsertCalls[0]);
        self::assertSame('2026-05-16 12:00:00', $builder->batchInsertCalls[0][0]['created_at']);
        self::assertSame('2026-05-16 12:00:00', $builder->batchInsertCalls[0][0]['updated_at']);
        self::assertArrayNotHasKey('is_admin', $builder->batchInsertCalls[0][0]);
        self::assertArrayNotHasKey('ignored', $builder->batchInsertCalls[1][0]);
        self::assertSame('Gamma', $builder->batchInsertCalls[1][0]['name']);
    }

    public function testBulkUpsertUsesBatchesAndExtendsUpdateColumnsWithUpdatedAt(): void
    {
        $builder = new ModelBulkWriteBuilderStub();
        register_framework_service('database.runtime', fn() => new ModelBulkWriteRuntimeStub($builder));

        $model = new class extends Model {
            protected string $table = 'users';
            protected array $fillable = ['email', 'name', 'status'];
            protected int $bulkWriteBatchSize = 2;

            protected function freshTimestamp(): string
            {
                return '2026-05-16 12:30:00';
            }
        };

        self::assertTrue($model::bulkUpsert([
            ['email' => 'a@example.test', 'name' => 'Alpha', 'status' => 'active', 'role_id' => 5],
            ['email' => 'b@example.test', 'name' => 'Beta', 'status' => 'inactive'],
            ['email' => 'c@example.test', 'name' => 'Gamma', 'status' => 'active'],
        ], 'email', ['name', 'status']));

        self::assertCount(2, $builder->upsertCalls);
        self::assertSame('email', $builder->upsertCalls[0][1]);
        self::assertSame(['name', 'status', 'updated_at'], $builder->upsertCalls[0][2]);
        self::assertSame('2026-05-16 12:30:00', $builder->upsertCalls[0][0][0]['created_at']);
        self::assertSame('2026-05-16 12:30:00', $builder->upsertCalls[0][0][0]['updated_at']);
        self::assertArrayNotHasKey('role_id', $builder->upsertCalls[0][0][0]);
    }

    public function testEachUsesConfiguredChunkSizeAndStopsEarly(): void
    {
        $builder = new ModelBulkWriteBuilderStub();
        $builder->chunkRows = [
            [
                ['id' => 1, 'name' => 'Alpha'],
                ['id' => 2, 'name' => 'Beta'],
            ],
            [
                ['id' => 3, 'name' => 'Gamma'],
            ],
        ];
        register_framework_service('database.runtime', fn() => new ModelBulkWriteRuntimeStub($builder));

        $model = new class extends Model {
            protected string $table = 'users';
            protected int $chunkSize = 2500;
        };

        $seen = [];
        $result = $model::each(function (Model $row) use (&$seen): bool {
            $seen[] = (string) $row->getAttribute('name');
            return $row->getAttribute('name') !== 'Beta';
        });

        self::assertFalse($result);
        self::assertSame([2500], $builder->chunkCalls);
        self::assertSame(['Alpha', 'Beta'], $seen);
    }

    public function testEachByIdUsesConfiguredChunkSizeAndHydratedModels(): void
    {
        $builder = new ModelBulkWriteBuilderStub();
        $builder->chunkByIdRows = [
            [
                ['id' => 10, 'name' => 'Alpha'],
                ['id' => 11, 'name' => 'Beta'],
            ],
        ];
        register_framework_service('database.runtime', fn() => new ModelBulkWriteRuntimeStub($builder));

        $model = new class extends Model {
            protected string $table = 'users';
            protected int $chunkSize = 4000;
        };

        $ids = [];
        $result = $model::eachById(function (Model $row) use (&$ids): void {
            $ids[] = $row->getKey();
        });

        self::assertTrue($result);
        self::assertSame([[4000, 'id', null]], $builder->chunkByIdCalls);
        self::assertSame([10, 11], $ids);
    }

    public function testBulkUpdateUsesGuardAwareBatchesAndPreservesPrimaryKey(): void
    {
        $builder = new ModelBulkWriteBuilderStub();
        register_framework_service('database.runtime', fn() => new ModelBulkWriteRuntimeStub($builder));

        $model = new class extends Model {
            protected string $table = 'users';
            protected array $fillable = ['name', 'status'];
            protected array $guarded = ['is_admin', 'id'];
            protected int $bulkWriteBatchSize = 2;

            protected function freshTimestamp(): string
            {
                return '2026-05-16 13:00:00';
            }
        };

        self::assertTrue($model::bulkUpdate([
            ['id' => 10, 'name' => 'Alpha', 'status' => 'active', 'is_admin' => 1],
            ['id' => 11, 'name' => 'Beta', 'status' => 'inactive'],
            ['id' => 12, 'status' => 'pending'],
        ]));

        self::assertCount(2, $builder->batchUpdateCalls);
        self::assertSame(10, $builder->batchUpdateCalls[0][0]['id']);
        self::assertSame('2026-05-16 13:00:00', $builder->batchUpdateCalls[0][0]['updated_at']);
        self::assertArrayNotHasKey('is_admin', $builder->batchUpdateCalls[0][0]);
        self::assertSame(['id' => 12, 'status' => 'pending', 'updated_at' => '2026-05-16 13:00:00'], $builder->batchUpdateCalls[1][0]);
    }

    public function testDestroyChunksLargeIdListsForFastDeletes(): void
    {
        $builder = new ModelBulkWriteBuilderStub();
        register_framework_service('database.runtime', fn() => new ModelBulkWriteRuntimeStub($builder));

        $model = new class extends Model {
            protected string $table = 'users';
            protected int $bulkWriteBatchSize = 2;
        };

        $deleted = $model::destroy([10, 11, 12, 13, 14]);

        self::assertSame(5, $deleted);
        self::assertSame([
            ['id', [10, 11]],
            ['id', [12, 13]],
            ['id', [14]],
        ], $builder->whereInCalls);
        self::assertCount(3, $builder->deleteCalls);
    }

    public function testDestroyChunksLifecycleDeletesWithoutHydratingEverythingAtOnce(): void
    {
        $builder = new ModelBulkWriteBuilderStub();
        $builder->getResultsQueue = [
            [
                ['id' => 20, 'name' => 'Alpha'],
                ['id' => 21, 'name' => 'Beta'],
            ],
            [
                ['id' => 22, 'name' => 'Gamma'],
            ],
        ];
        register_framework_service('database.runtime', fn() => new ModelBulkWriteRuntimeStub($builder));

        $model = new class extends Model {
            protected string $table = 'users';
            protected bool $softDeletes = true;
            protected int $bulkWriteBatchSize = 2;
        };

        $deleted = $model::destroy([20, 21, 22]);

        self::assertSame(3, $deleted);
        self::assertSame([
            ['id', [20, 21]],
            ['id', [22]],
        ], $builder->whereInCalls);
        self::assertCount(3, $builder->updateCalls);
    }

    public function testImportInBatchesConsumesIterableAndReportsProgress(): void
    {
        $builder = new ModelBulkWriteBuilderStub();
        register_framework_service('database.runtime', fn() => new ModelBulkWriteRuntimeStub($builder));

        $model = new class extends Model {
            protected string $table = 'users';
            protected array $fillable = ['name', 'email'];

            protected function freshTimestamp(): string
            {
                return '2026-05-16 14:00:00';
            }
        };

        $progress = [];
        $processed = $model::importInBatches((function (): \Generator {
            yield ['name' => 'Alpha', 'email' => 'a@example.test'];
            yield ['name' => 'Beta', 'email' => 'b@example.test'];
            yield ['name' => 'Gamma', 'email' => 'c@example.test'];
        })(), function (array $state) use (&$progress): void {
            $progress[] = $state;
        }, 2);

        self::assertSame(3, $processed);
        self::assertCount(2, $builder->batchInsertCalls);
        self::assertSame([
            ['operation' => 'insert', 'processed_rows' => 2, 'batches_processed' => 1, 'last_batch_rows' => 2],
            ['operation' => 'insert', 'processed_rows' => 3, 'batches_processed' => 2, 'last_batch_rows' => 1],
        ], $progress);
    }

    public function testUpsertInBatchesUsesIterableAndPreparedUpdateColumns(): void
    {
        $builder = new ModelBulkWriteBuilderStub();
        register_framework_service('database.runtime', fn() => new ModelBulkWriteRuntimeStub($builder));

        $model = new class extends Model {
            protected string $table = 'users';
            protected array $fillable = ['email', 'name', 'status'];

            protected function freshTimestamp(): string
            {
                return '2026-05-16 14:30:00';
            }
        };

        $processed = $model::upsertInBatches((function (): \Generator {
            yield ['email' => 'a@example.test', 'name' => 'Alpha', 'status' => 'active'];
            yield ['email' => 'b@example.test', 'name' => 'Beta', 'status' => 'inactive'];
        })(), 'email', ['name', 'status'], null, 1);

        self::assertSame(2, $processed);
        self::assertCount(2, $builder->upsertCalls);
        self::assertSame(['name', 'status', 'updated_at'], $builder->upsertCalls[0][2]);
    }

    public function testUpdateInBatchesCanStopAfterCommittedBatchViaProgressCallback(): void
    {
        $builder = new ModelBulkWriteBuilderStub();
        register_framework_service('database.runtime', fn() => new ModelBulkWriteRuntimeStub($builder));

        $model = new class extends Model {
            protected string $table = 'users';
            protected array $fillable = ['status'];

            protected function freshTimestamp(): string
            {
                return '2026-05-16 15:00:00';
            }
        };

        $processed = $model::updateInBatches((function (): \Generator {
            yield ['id' => 100, 'status' => 'active'];
            yield ['id' => 101, 'status' => 'inactive'];
            yield ['id' => 102, 'status' => 'pending'];
        })(), static fn(array $state): bool => $state['batches_processed'] < 1, 2);

        self::assertSame(2, $processed);
        self::assertCount(1, $builder->batchUpdateCalls);
        self::assertSame(100, $builder->batchUpdateCalls[0][0]['id']);
        self::assertSame(101, $builder->batchUpdateCalls[0][1]['id']);
    }

    public function testRestoreManyUsesChunkedFastPathWhenNoObserversAreRegistered(): void
    {
        $builder = new ModelBulkWriteBuilderStub();
        register_framework_service('database.runtime', fn() => new ModelBulkWriteRuntimeStub($builder));

        $model = new class extends Model {
            protected string $table = 'users';
            protected bool $softDeletes = true;
            protected int $bulkWriteBatchSize = 2;

            protected function freshTimestamp(): string
            {
                return '2026-05-16 15:30:00';
            }
        };

        $restored = $model::restoreMany([30, 31, 32]);

        self::assertSame(3, $restored);
        self::assertSame([
            ['id', [30, 31]],
            ['id', [32]],
        ], $builder->whereInCalls);
        self::assertCount(2, $builder->updateCalls);
        self::assertSame(['deleted_at' => null, 'updated_at' => '2026-05-16 15:30:00'], $builder->updateCalls[0]['attributes']);
    }

    public function testForceDestroyChunksLifecycleDeletesForSoftDeletedModels(): void
    {
        $builder = new ModelBulkWriteBuilderStub();
        $builder->getResultsQueue = [
            [
                ['id' => 40, 'name' => 'Alpha'],
                ['id' => 41, 'name' => 'Beta'],
            ],
            [
                ['id' => 42, 'name' => 'Gamma'],
            ],
        ];
        register_framework_service('database.runtime', fn() => new ModelBulkWriteRuntimeStub($builder));

        $model = new class extends Model {
            protected string $table = 'users';
            protected bool $softDeletes = true;
            protected int $bulkWriteBatchSize = 2;

            protected static function boot(): void
            {
                static::__callStatic('forceDeleted', [static function (): void {
                }]);
            }
        };
        $class = $model::class;
        $class::clearBootedModelState();

        $deleted = $class::forceDestroy([40, 41, 42]);

        self::assertSame(3, $deleted);
        self::assertSame([
            ['id', [40, 41]],
            ['id', [42]],
        ], $builder->whereInCalls);
        self::assertCount(3, $builder->deleteCalls);
    }

    public function testImportInBatchesUsesAdaptiveBatchSizeWhenNoOverrideIsProvided(): void
    {
        $builder = new ModelBulkWriteBuilderStub();
        register_framework_service('database.runtime', fn() => new ModelBulkWriteRuntimeStub($builder));

        $model = new class extends Model {
            protected string $table = 'users';
            protected array $fillable = ['name', 'email', 'status'];

            protected function recommendedBulkWriteBatchSize(?array $sampleRow = null): int
            {
                return 2;
            }
        };

        $processed = $model::importInBatches([
            ['name' => 'Alpha', 'email' => 'a@example.test', 'status' => 'active'],
            ['name' => 'Beta', 'email' => 'b@example.test', 'status' => 'inactive'],
            ['name' => 'Gamma', 'email' => 'c@example.test', 'status' => 'pending'],
        ]);

        self::assertSame(3, $processed);
        self::assertCount(2, $builder->batchInsertCalls);
        self::assertCount(2, $builder->batchInsertCalls[0]);
        self::assertCount(1, $builder->batchInsertCalls[1]);
    }

    public function testImportInBatchesMillionRowHarnessUsesAdaptiveBatchingWithoutMaterializingDataset(): void
    {
        $builder = new ModelBulkWriteBuilderStub();
        register_framework_service('database.runtime', fn() => new ModelBulkWriteRuntimeStub($builder));

        $model = new class extends Model {
            protected string $table = 'users';
            protected array $guarded = [];

            protected function freshTimestamp(): string
            {
                return '2026-05-16 16:00:00';
            }
        };

        $processed = $model::importInBatches((function (): \Generator {
            for ($id = 1; $id <= 1000000; $id++) {
                $row = ['id' => $id, 'name' => 'User ' . $id, 'email' => 'user' . $id . '@example.test'];
                for ($column = 1; $column <= 26; $column++) {
                    $row['col_' . $column] = 'value-' . $column;
                }

                yield $row;
            }
        })(), static fn(array $state): bool => $state['batches_processed'] < 2);

        self::assertSame(500, $processed);
        self::assertCount(2, $builder->batchInsertCalls);
        self::assertCount(250, $builder->batchInsertCalls[0]);
        self::assertCount(250, $builder->batchInsertCalls[1]);
    }
}