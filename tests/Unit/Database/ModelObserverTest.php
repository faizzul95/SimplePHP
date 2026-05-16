<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use Core\Database\Model;
use PHPUnit\Framework\TestCase;

final class ModelObserverBuilderStub
{
    public ?string $table = null;
    public array $sortable = [];
    public array $filterable = [];
    public array $whereCalls = [];
    public array $whereInCalls = [];
    public array $updateCalls = [];
    public int|string|null $insertId = 1;
    public bool $deleteResult = true;
    public array $deleteCalls = [];
    public mixed $fetchResult = null;
    public array $getResult = [];

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

    public function setFillable(array $columns)
    {
        return $this;
    }

    public function setGuarded(array $columns)
    {
        return $this;
    }

    public function whereNotNull($column)
    {
        return $this;
    }

    public function where($column, $operator = null, $value = null)
    {
        if ($value === null && $operator !== 'IS NULL' && $operator !== 'IS NOT NULL') {
            $value = $operator;
            $operator = '=';
        }

        $this->whereCalls[] = [$column, $operator, $value];
        return $this;
    }

    public function whereIn($column, $values)
    {
        $this->whereInCalls[] = [$column, $values];
        return $this;
    }

    public function insertGetId(array $attributes)
    {
        $this->updateCalls[] = ['insert', $attributes];
        return $this->insertId;
    }

    public function update(array $attributes)
    {
        $this->updateCalls[] = ['update', $attributes];
        return true;
    }

    public function delete()
    {
        $this->deleteCalls[] = $this->whereCalls;
        return $this->deleteResult;
    }

    public function fetch($table = null)
    {
        return $this->fetchResult;
    }

    public function get($table = null)
    {
        return $this->getResult;
    }
}

final class ModelObserverRuntimeStub
{
    public ModelObserverBuilderStub $builder;

    public function __construct(?ModelObserverBuilderStub $builder = null)
    {
        $this->builder = $builder ?? new ModelObserverBuilderStub();
    }

    public function connection(string $connectionName = 'default')
    {
        return $this->builder;
    }
}

final class ModelObserverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        reset_framework_service();
        require_once ROOT_DIR . 'systems/app.php';
    }

    public function testRetrievedObserverRunsDuringHydration(): void
    {
        $runtime = new ModelObserverRuntimeStub();
        $runtime->builder->fetchResult = ['id' => 7, 'name' => 'alice'];
        register_framework_service('database.runtime', fn() => $runtime);

        $observer = new class {
            public function retrieved(Model $model): void
            {
                $model->setAttribute('hydrated_flag', true);
            }
        };

        $model = new class extends Model {
            protected string $table = 'users';
        };
        $class = $model::class;
        $class::clearBootedModelState();
        $class::observe($observer);

        $result = $class::findById(7);

        self::assertInstanceOf($class, $result);
        self::assertTrue((bool) $result->hydrated_flag);
    }

    public function testSavingAndCreatedObserversWrapInsertLifecycle(): void
    {
        $runtime = new ModelObserverRuntimeStub();
        $runtime->builder->insertId = 12;
        register_framework_service('database.runtime', fn() => $runtime);

        $events = [];
        $model = new class extends Model {
            protected string $table = 'users';
            protected array $fillable = ['name'];
        };
        $class = $model::class;
        $class::clearBootedModelState();
        $class::saving(function (Model $model) use (&$events): void {
            $events[] = 'saving';
            $model->name = strtoupper((string) $model->name);
        });
        $class::created(function (Model $model) use (&$events): void {
            $events[] = 'created';
            $model->setAttribute('created_flag', true);
        });
        $class::saved(function () use (&$events): void {
            $events[] = 'saved';
        });

        $instance = new $class(['name' => 'alice']);

        self::assertTrue($instance->save());
        self::assertSame(['saving', 'created', 'saved'], $events);
        self::assertSame('ALICE', $runtime->builder->updateCalls[0][1]['name']);
        self::assertTrue((bool) $instance->created_flag);
        self::assertSame(12, $instance->getKey());
    }

    public function testSavingObserverCanCancelInsert(): void
    {
        $runtime = new ModelObserverRuntimeStub();
        register_framework_service('database.runtime', fn() => $runtime);

        $model = new class extends Model {
            protected string $table = 'users';
            protected array $fillable = ['name'];
        };
        $class = $model::class;
        $class::clearBootedModelState();
        $class::saving(static fn(Model $model): bool => false);

        $instance = new $class(['name' => 'alice']);

        self::assertFalse($instance->save());
        self::assertSame([], $runtime->builder->updateCalls);
    }

    public function testInstanceMagicCallForwardsToQueryBuilder(): void
    {
        $runtime = new ModelObserverRuntimeStub();
        $runtime->builder->fetchResult = ['id' => 5, 'name' => 'Alice'];
        register_framework_service('database.runtime', fn() => $runtime);

        $model = new class extends Model {
            protected string $table = 'users';
        };

        $result = $model->where('email', 'alice@example.test')->fetch();

        self::assertSame([['email', '=', 'alice@example.test']], $runtime->builder->whereCalls);
        self::assertInstanceOf($model::class, $result);
    }

    public function testDestroyUsesSoftDeleteLifecycleWhenEnabled(): void
    {
        $runtime = new ModelObserverRuntimeStub();
        $runtime->builder->getResult = [
            ['id' => 10, 'name' => 'Alice'],
        ];
        register_framework_service('database.runtime', fn() => $runtime);

        $events = [];
        $model = new class extends Model {
            protected string $table = 'users';
            protected bool $softDeletes = true;
        };
        $class = $model::class;
        $class::clearBootedModelState();
        $class::deleted(function () use (&$events): void {
            $events[] = 'deleted';
        });

        $deletedCount = $class::destroy([10]);

        self::assertSame(1, $deletedCount);
        self::assertSame([['id', [10]]], $runtime->builder->whereInCalls);
        self::assertNotEmpty($runtime->builder->updateCalls);
        self::assertSame([], $runtime->builder->deleteCalls);
        self::assertSame(['deleted'], $events);
    }

    public function testSaveFiltersGuardedColumnsOnUpdate(): void
    {
        $runtime = new ModelObserverRuntimeStub();
        register_framework_service('database.runtime', fn() => $runtime);

        $model = new class extends Model {
            protected string $table = 'users';
            protected array $fillable = ['name'];
            protected array $guarded = ['is_admin'];
        };

        $instance = $model::hydrateRecord(['id' => 5, 'name' => 'Alice', 'is_admin' => 0]);
        $instance->name = 'Bob';
        $instance->is_admin = 1;

        self::assertTrue($instance->save());
        self::assertSame('update', $runtime->builder->updateCalls[0][0]);
        self::assertSame('Bob', $runtime->builder->updateCalls[0][1]['name']);
        self::assertArrayHasKey('updated_at', $runtime->builder->updateCalls[0][1]);
        self::assertArrayNotHasKey('is_admin', $runtime->builder->updateCalls[0][1]);
    }

    public function testForceCreateBypassesGuardFilteringOnInsert(): void
    {
        $runtime = new ModelObserverRuntimeStub();
        $runtime->builder->insertId = 21;
        register_framework_service('database.runtime', fn() => $runtime);

        $model = new class extends Model {
            protected string $table = 'users';
            protected array $guarded = ['is_admin'];
        };
        $class = $model::class;
        $class::clearBootedModelState();

        $created = $class::forceCreate(['name' => 'Root', 'is_admin' => 1]);

        self::assertSame(21, $created->getKey());
        self::assertSame('insert', $runtime->builder->updateCalls[0][0]);
        self::assertSame(1, $runtime->builder->updateCalls[0][1]['is_admin']);
    }

    public function testSoftDeleteDoesNotFireSavingOrSavedEvents(): void
    {
        $runtime = new ModelObserverRuntimeStub();
        $runtime->builder->getResult = [
            ['id' => 10, 'name' => 'Alice'],
        ];
        register_framework_service('database.runtime', fn() => $runtime);

        $events = [];
        $model = new class extends Model {
            protected string $table = 'users';
            protected bool $softDeletes = true;
        };
        $class = $model::class;
        $class::clearBootedModelState();
        $class::saving(function () use (&$events): void {
            $events[] = 'saving';
        });
        $class::saved(function () use (&$events): void {
            $events[] = 'saved';
        });
        $class::deleted(function () use (&$events): void {
            $events[] = 'deleted';
        });

        self::assertSame(1, $class::destroy([10]));
        self::assertSame(['deleted'], $events);
    }

    public function testBootTraitAndInitializeTraitHooksRun(): void
    {
        $model = new class extends Model {
            use ModelObserverBootableTrait;

            protected string $table = 'users';
        };

        $class = $model::class;
        $class::clearBootedModelState();
        $class::$bootTraitCalls = 0;

        $instance = new $class();

        self::assertSame(1, $class::$bootTraitCalls);
        self::assertSame(1, $instance->traitInitializedCount);
    }

    public function testSaveQuietlySuppressesObserverCallbacks(): void
    {
        $runtime = new ModelObserverRuntimeStub();
        $runtime->builder->insertId = 9;
        register_framework_service('database.runtime', fn() => $runtime);

        $events = [];
        $model = new class extends Model {
            protected string $table = 'users';
            protected array $fillable = ['name'];
        };
        $class = $model::class;
        $class::clearBootedModelState();
        $class::saving(function () use (&$events): void {
            $events[] = 'saving';
        });
        $class::created(function () use (&$events): void {
            $events[] = 'created';
        });

        $instance = new $class(['name' => 'quiet']);

        self::assertTrue($instance->saveQuietly());
        self::assertSame([], $events);
        self::assertSame(9, $instance->getKey());
    }

    public function testWithoutEventsMutesNestedLifecycleCallbacks(): void
    {
        $runtime = new ModelObserverRuntimeStub();
        $runtime->builder->insertId = 11;
        register_framework_service('database.runtime', fn() => $runtime);

        $events = [];
        $model = new class extends Model {
            protected string $table = 'users';
            protected array $fillable = ['name'];
        };
        $class = $model::class;
        $class::clearBootedModelState();
        $class::saved(function () use (&$events): void {
            $events[] = 'saved';
        });

        $instance = new $class(['name' => 'nested']);

        $result = $class::withoutEvents(function () use ($instance, $class) {
            return $class::withoutEvents(fn() => $instance->save());
        });

        self::assertTrue($result);
        self::assertSame([], $events);
    }
}

trait ModelObserverBootableTrait
{
    public static int $bootTraitCalls = 0;
    public int $traitInitializedCount = 0;

    protected static function bootModelObserverBootableTrait(): void
    {
        static::$bootTraitCalls++;
    }

    protected function initializeModelObserverBootableTrait(): void
    {
        $this->traitInitializedCount++;
    }
}