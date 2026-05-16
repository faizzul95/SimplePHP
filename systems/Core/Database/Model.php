<?php

declare(strict_types=1);

namespace Core\Database;

use JsonSerializable;
use RuntimeException;

/**
 * Driver-agnostic Active-Record-style base model.
 *
 * Works with MySQL, MariaDB, or any future driver registered in DriverRegistry.
 * All query operations delegate to db($connection)->table(…) at runtime — the
 * concrete driver is resolved automatically by the framework's DatabaseRuntime.
 *
 * Usage:
 *   namespace App\Models;
 *
 *   class User extends \Core\Database\Model
 *   {
 *       protected array  $fillable    = ['name', 'email', 'bio'];
 *       protected array  $guarded     = ['role_id', 'is_admin'];
 *       protected array  $hidden      = ['password', 'remember_token'];
 *       protected array  $casts       = ['is_active' => 'bool', 'meta' => 'json'];
 *       protected bool   $timestamps  = true;
 *       protected bool   $softDeletes = true;
 *   }
 *
 * Common patterns:
 *   User::all()
 *   User::findById(1)
 *   User::where('active', 1)->orderBy('name')->get()
 *   User::create(['name' => 'Alice', 'email' => 'a@example.com'])
 *   $u = User::findById(1);  $u->name = 'Bob';  $u->save();
 *   User::destroy([1, 2, 3])
 *   User::firstOrCreate(['email' => 'a@example.com'], ['name' => 'Alice'])
 *   User::withTrashed()->where('email', 'x')->first()
 *
 * @phpstan-consistent-constructor
 */
abstract class Model implements JsonSerializable
{
    // ── Timestamp / soft-delete column names ────────────────────────────
    public const CREATED_AT = 'created_at';
    public const UPDATED_AT = 'updated_at';
    public const DELETED_AT = 'deleted_at';

    /** @var string[] */
    private const OBSERVABLE_EVENTS = [
        'retrieved',
        'saving',
        'saved',
        'creating',
        'created',
        'updating',
        'updated',
        'deleting',
        'deleted',
        'restoring',
        'restored',
        'forceDeleting',
        'forceDeleted',
    ];

    // ── Model configuration (override in subclasses) ──────────────────────

    /**
     * Optional explicit table name.
     * Leave empty to auto-derive: "App\Models\UserProfile" → "user_profiles".
     */
    protected string $table = '';

    /** Database connection name — empty string = 'default'. */
    protected string $connection = '';

    /** Primary key column name. */
    protected string $primaryKey = 'id';

    /** PHP type of the primary key ('int' or 'string'). */
    protected string $keyType = 'int';

    /** Whether the primary key auto-increments. */
    protected bool $incrementing = true;

    /**
     * Columns stripped from toArray() and toJson().
     * @var string[]
     */
    protected array $hidden = [];

    /**
     * Columns that may be mass-assigned via fill(). Empty = allow all non-guarded.
     * @var string[]
     */
    protected array $fillable = [];

    /**
     * Columns that can never be mass-assigned.
     * @var string[]
     */
    protected array $guarded = ['id'];

    /**
     * Column type-casts applied when reading attributes.
     * Supported types: 'int'|'integer', 'float'|'double', 'bool'|'boolean',
     *                   'string', 'array', 'json', 'datetime'
     * @var array<string, string>
     */
    protected array $casts = [];

    /** Automatically manage created_at / updated_at timestamps. */
    protected bool $timestamps = true;

    /** Soft-delete support (sets deleted_at instead of removing the row). */
    protected bool $softDeletes = false;

    /** Default records-per-page for paginate(). */
    protected int $perPage = 15;

    /** Default chunk size for model iteration over large result sets. */
    protected int $chunkSize = 1000;

    /** Default batch size for bulk writes. */
    protected int $bulkWriteBatchSize = 2000;

    /** @var array<string, true> Attributes explicitly marked to bypass write guards for the next save cycle. */
    protected array $forceFilled = [];

    /**
     * Columns explicitly permitted for dynamic ORDER BY clauses.
     * @var string[]
     */
    protected array $sortable = [];

    /**
     * Columns explicitly permitted for dynamic user-driven WHERE clauses.
     * @var string[]
     */
    protected array $filterable = [];

    // ── Row-level state ──────────────────────────────────────────────────

    /**
     * Column values for this row.
     * @var array<string, mixed>
     */
    public array $attributes = [];

    /**
     * Snapshot of attributes at last load / save (dirty-tracking baseline).
     * @var array<string, mixed>
     */
    protected array $original = [];

    /** True when this instance represents a persisted DB row. */
    public bool $exists = false;

    /** True immediately after a successful insert via save(). */
    public bool $wasRecentlyCreated = false;

    /** @var array<class-string, bool> */
    protected static array $bootedModels = [];

    /** @var array<class-string, array<string, array<int, callable>>> */
    protected static array $modelEventListeners = [];

    /** @var array<class-string, array<int, string>> */
    protected static array $traitInitializers = [];

    /** @var array<class-string, int> */
    protected static array $mutedEventDepth = [];

    // ── Constructor ───────────────────────────────────────────────────

    public function __construct(array $attributes = [])
    {
        static::bootIfNotBooted();
        $this->initializeTraits();
        $this->fill($attributes);
    }

    protected static function boot(): void
    {
    }

    protected static function booted(): void
    {
    }

    protected static function bootIfNotBooted(): void
    {
        $class = static::class;
        if (isset(static::$bootedModels[$class])) {
            return;
        }

        static::$bootedModels[$class] = true;
        static::bootTraits();
        static::boot();
        static::booted();
    }

    protected static function bootTraits(): void
    {
        $class = static::class;
        static::$traitInitializers[$class] = [];

        foreach (static::classUsesRecursive($class) as $traitName) {
            $baseName = static::classBasename($traitName);
            $bootMethod = 'boot' . $baseName;
            $initializeMethod = 'initialize' . $baseName;

            if (method_exists($class, $bootMethod)) {
                forward_static_call([$class, $bootMethod]);
            }

            if (method_exists($class, $initializeMethod)) {
                static::$traitInitializers[$class][] = $initializeMethod;
            }
        }
    }

    protected function initializeTraits(): void
    {
        foreach (static::$traitInitializers[static::class] ?? [] as $method) {
            $this->{$method}();
        }
    }

    // ── Table resolution ──────────────────────────────────────────────

    /**
     * Return the table name for this model.
     *
     * Priority: $table → camelCase-to-snake_case plural of the class basename.
     * Examples:
     *   User            → users
     *   UserProfile     → user_profiles
     *   OrderItem       → order_items
     */
    public function getTable(): string
    {
        if (!empty($this->table)) {
            return $this->table;
        }

        $class    = static::class;
        $basename = substr($class, (int) strrpos($class, '\\') + 1);

        return static::pluralize(static::camelToSnake($basename));
    }

    /** CamelCase → snake_case (e.g. "UserProfile" → "user_profile"). */
    protected static function camelToSnake(string $name): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
    }

    /**
     * Simple English plural for common cases.
     * Not exhaustive — override getTable() for irregular table names.
     */
    protected static function pluralize(string $word): string
    {
        static $irregular = [
            'person' => 'people', 'child'  => 'children', 'man'   => 'men',
            'woman'  => 'women',  'tooth'  => 'teeth',    'foot'  => 'feet',
            'mouse'  => 'mice',   'goose'  => 'geese',    'ox'    => 'oxen',
            'datum'  => 'data',   'medium' => 'media',    'genus' => 'genera',
            'status' => 'statuses',
        ];

        $lower = strtolower($word);

        if (isset($irregular[$lower])) {
            return $irregular[$lower];
        }
        // Already plural-looking (ends in s|x|z|ch|sh)
        if (preg_match('/(?:s|x|z|ch|sh)$/i', $word)) {
            return $word . 'es';
        }
        // Radius → radii, cactus → cacti
        if (preg_match('/(?<![aeiou])us$/i', $word)) {
            return substr($word, 0, -2) . 'i';
        }
        // Analysis → analyses
        if (preg_match('/sis$/i', $word)) {
            return substr($word, 0, -3) . 'ses';
        }
        // City → cities (consonant + y)
        if (preg_match('/[^aeiou]y$/i', $word)) {
            return substr($word, 0, -1) . 'ies';
        }
        // Leaf → leaves, knife → knives
        if (preg_match('/(?<![aeiou])(?:f|fe)$/i', $word)) {
            return (string) preg_replace('/(?:f|fe)$/i', 'ves', $word);
        }

        return $word . 's';
    }

    // ── Connection / query ───────────────────────────────────────────────

    /** Return the resolved connection name (falls back to 'default'). */
    protected function getConnectionName(): string
    {
        return $this->connection !== '' ? $this->connection : 'default';
    }

    /**
     * Return a fresh ModelQuery scoped to this model's table.
     *
     * The underlying driver is resolved at runtime via db() — MySQL, MariaDB,
     * or any future driver registered in DriverRegistry.  No driver is
     * hardcoded here.
     */
    protected function newQuery(bool $withTrashed = false, bool $onlyTrashed = false): ModelQuery
    {
        static::bootIfNotBooted();

        $builder = db($this->getConnectionName())->table($this->getTable());
        $builder->setSortableColumns($this->getSortableColumns());
        $builder->setFilterableColumns($this->getFilterableColumns());

        if ($this->softDeletes) {
            if (!$withTrashed && !$onlyTrashed) {
                $builder->whereNull(static::DELETED_AT);
            } elseif ($onlyTrashed) {
                $builder->whereNotNull(static::DELETED_AT);
            }
            // $withTrashed && !$onlyTrashed → no scope → return everything
        }

        return new ModelQuery($builder, static::class);
    }

    /**
     * @return string[]
     */
    public function getSortableColumns(): array
    {
        return array_values($this->sortable);
    }

    /**
     * @return string[]
     */
    public function getFilterableColumns(): array
    {
        return array_values($this->filterable);
    }

    public function getChunkSize(): int
    {
        return $this->chunkSize;
    }

    public function getBulkWriteBatchSize(): int
    {
        return $this->bulkWriteBatchSize;
    }

    // ── Static query API ─────────────────────────────────────────────────

    /**
     * Retrieve all rows (use sparingly on large tables — prefer paginate()).
     *
     * @param string[]|string $columns
     * @return static[]
     */
    public static function all(array|string $columns = ['*']): array
    {
        $q = (new static())->newQuery();
        if ($columns !== ['*'] && $columns !== '*') {
            $q->select($columns);
        }
        return $q->get() ?: [];
    }

    /**
     * Find row(s) by primary key.
     *
     *   User::findById(1)           → User instance or null
     *   User::findById([1, 2, 3])   → User[]
     *
     * @param int|string|array<int|string> $id
     * @return static|static[]|null
     */
    public static function findById(mixed $id, array|string $columns = ['*']): mixed
    {
        return (new static())->newQuery()->find($id, $columns);
    }

    /**
     * Find a row by primary key or throw RuntimeException.
     *
     * @return static
     * @throws RuntimeException
     */
    public static function findOrFail(mixed $id, array|string $columns = ['*']): static
    {
        $result = static::findById($id, $columns);
        if ($result === null || (is_array($result) && empty($result))) {
            $key = is_array($id) ? implode(',', $id) : $id;
            throw new RuntimeException(static::class . " with key [{$key}] not found.");
        }
        /** @var static $result */
        return $result;
    }

    /**
     * Create and persist a new model instance (respects $fillable/$guarded).
     *
     * @return static
     */
    public static function create(array $attributes): static
    {
        $model = new static($attributes);
        $model->save();
        return $model;
    }

    /**
     * Create and persist a new model, bypassing $fillable/$guarded.
     *
     * @return static
     */
    public static function forceCreate(array $attributes): static
    {
        $model = new static();
        $model->forceFill($attributes);
        $model->save();
        return $model;
    }

    /**
     * Mass-insert rows (no timestamps, no model events).
     *
     * @param array<int, array<string, mixed>> $rows
     */
    public static function bulkInsert(array $rows): bool
    {
        if (empty($rows)) {
            return true;
        }

        $instance = new static();
        $preparedRows = $instance->prepareBulkWriteRows($rows, false);
        if ($preparedRows === []) {
            return true;
        }

        foreach (array_chunk($preparedRows, $instance->normalizeBulkBatchSize(null, $preparedRows[0] ?? null)) as $chunk) {
            $result = $instance->newBulkWriteBuilder()->batchInsert($chunk);
            if (!$instance->bulkWriteSucceeded($result)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Bulk upsert rows in batches using the driver's write-optimized path.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param string|string[] $uniqueBy
     * @param string[]|null $updateColumns
     */
    public static function bulkUpsert(array $rows, string|array $uniqueBy = 'id', ?array $updateColumns = null): bool
    {
        if (empty($rows)) {
            return true;
        }

        $instance = new static();
        $preparedRows = $instance->prepareBulkWriteRows($rows, true);
        if ($preparedRows === []) {
            return true;
        }

        $preparedUpdateColumns = $instance->prepareBulkUpsertUpdateColumns($updateColumns);

        foreach (array_chunk($preparedRows, $instance->normalizeBulkBatchSize(null, $preparedRows[0] ?? null)) as $chunk) {
            $result = $instance->newBulkWriteBuilder()->upsert($chunk, $uniqueBy, $preparedUpdateColumns);
            if (!$instance->bulkWriteSucceeded($result)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Bulk update rows in batches using the model primary key as the row identifier.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    public static function bulkUpdate(array $rows): bool
    {
        if (empty($rows)) {
            return true;
        }

        $instance = new static();
        $preparedRows = $instance->prepareBulkUpdateRows($rows);
        if ($preparedRows === []) {
            return true;
        }

        foreach (array_chunk($preparedRows, $instance->normalizeBulkBatchSize(null, $preparedRows[0] ?? null)) as $chunk) {
            $result = $instance->newBulkWriteBuilder()->batchUpdate($chunk);
            if (!$instance->bulkWriteSucceeded($result)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Insert rows from any iterable source in bounded batches.
     */
    public static function importInBatches(iterable $rows, ?callable $progress = null, ?int $batchSize = null): int
    {
        $instance = new static();

        return $instance->processBulkStream(
            $rows,
            $batchSize,
            fn(array $batch): array => $instance->prepareBulkWriteRows($batch, false),
            fn(array $batch): mixed => $instance->newBulkWriteBuilder()->batchInsert($batch),
            'insert',
            $progress
        );
    }

    /**
     * Upsert rows from any iterable source in bounded batches.
     *
     * @param string|string[] $uniqueBy
     * @param string[]|null $updateColumns
     */
    public static function upsertInBatches(iterable $rows, string|array $uniqueBy = 'id', ?array $updateColumns = null, ?callable $progress = null, ?int $batchSize = null): int
    {
        $instance = new static();
        $preparedUpdateColumns = $instance->prepareBulkUpsertUpdateColumns($updateColumns);

        return $instance->processBulkStream(
            $rows,
            $batchSize,
            fn(array $batch): array => $instance->prepareBulkWriteRows($batch, true),
            fn(array $batch): mixed => $instance->newBulkWriteBuilder()->upsert($batch, $uniqueBy, $preparedUpdateColumns),
            'upsert',
            $progress
        );
    }

    /**
     * Update rows from any iterable source in bounded batches.
     */
    public static function updateInBatches(iterable $rows, ?callable $progress = null, ?int $batchSize = null): int
    {
        $instance = new static();

        return $instance->processBulkStream(
            $rows,
            $batchSize,
            fn(array $batch): array => $instance->prepareBulkUpdateRows($batch),
            fn(array $batch): mixed => $instance->newBulkWriteBuilder()->batchUpdate($batch),
            'update',
            $progress
        );
    }

    public static function each(callable $callback, ?int $chunkSize = null): bool
    {
        $instance = new static();
        $chunkSize ??= $instance->getChunkSize();
        $completed = true;

        $instance->newQuery()->chunk($chunkSize, static function (array $models) use ($callback, &$completed) {
            foreach ($models as $model) {
                if ($callback($model) === false) {
                    $completed = false;
                    return false;
                }
            }

            return true;
        });

        return $completed;
    }

    public static function eachById(callable $callback, ?int $chunkSize = null, string $column = 'id', ?string $alias = null): bool
    {
        $instance = new static();
        $chunkSize ??= $instance->getChunkSize();
        $completed = true;

        $instance->newQuery()->chunkById($chunkSize, static function (array $models) use ($callback, &$completed) {
            foreach ($models as $model) {
                if ($callback($model) === false) {
                    $completed = false;
                    return false;
                }
            }

            return true;
        }, $column, $alias);

        return $completed;
    }

    /**
     * Find the first row matching $conditions, or create it.
     *
     * @return static
     */
    public static function firstOrCreate(array $conditions, array $extra = []): static
    {
        $q = (new static())->newQuery();
        foreach ($conditions as $col => $val) {
            $q->where((string) $col, $val);
        }
        $found = $q->fetch();
        if ($found instanceof static) {
            return $found;
        }
        return static::create(array_merge($conditions, $extra));
    }

    /**
     * Find the first row matching $conditions, or return a new (unsaved) instance.
     *
     * @return static
     */
    public static function firstOrNew(array $conditions, array $extra = []): static
    {
        $q = (new static())->newQuery();
        foreach ($conditions as $col => $val) {
            $q->where((string) $col, $val);
        }
        $found = $q->fetch();
        if ($found instanceof static) {
            return $found;
        }
        return new static(array_merge($conditions, $extra));
    }

    /**
     * Update an existing row matched by $conditions, or create it.
     *
     * @return static
     */
    public static function updateOrCreateRecord(array $conditions, array $data = []): static
    {
        $q = (new static())->newQuery();
        foreach ($conditions as $col => $val) {
            $q->where((string) $col, $val);
        }
        $found = $q->fetch();
        if ($found instanceof static) {
            $found->fill($data);
            $found->save();
            return $found;
        }
        return static::create(array_merge($conditions, $data));
    }

    /**
     * Delete rows by primary key(s). Returns the number of rows deleted.
     *
     * @param int|string|array<int|string> $ids
     */
    public static function destroy(int|string|array $ids): int
    {
        $ids = array_values(array_unique((array) $ids));
        if (empty($ids)) {
            return 0;
        }

        static::bootIfNotBooted();

        $instance = new static();

        if (!$instance->requiresPerModelDeleteLifecycle()) {
            $deleted = 0;

            foreach (array_chunk($ids, $instance->getBulkWriteBatchSize()) as $chunk) {
                $result = db($instance->getConnectionName())
                    ->table($instance->getTable())
                    ->whereIn($instance->primaryKey, $chunk)
                    ->delete();

                if ($result === false) {
                    break;
                }

                $deleted += count($chunk);
            }

            return $deleted;
        }

        $deleted = 0;

        foreach (array_chunk($ids, $instance->getBulkWriteBatchSize()) as $chunk) {
            $models = $instance->softDeletes
                ? static::withTrashed()->find($chunk)
                : static::findById($chunk);

            if (!is_array($models) || $models === []) {
                continue;
            }

            foreach ($models as $model) {
                if ($model instanceof static && $model->delete()) {
                    $deleted++;
                }
            }
        }

        return $deleted;
    }

    /**
     * Restore soft-deleted rows by primary key(s). Returns the number of rows restored.
     *
     * @param int|string|array<int|string> $ids
     */
    public static function restoreMany(int|string|array $ids): int
    {
        $ids = array_values(array_unique((array) $ids));
        if ($ids === []) {
            return 0;
        }

        static::bootIfNotBooted();

        $instance = new static();
        if (!$instance->softDeletes) {
            return 0;
        }

        if (!$instance->requiresPerModelRestoreLifecycle()) {
            $restored = 0;
            $now = $instance->timestamps ? $instance->freshTimestamp() : null;

            foreach (array_chunk($ids, $instance->getBulkWriteBatchSize()) as $chunk) {
                $updateData = [static::DELETED_AT => null];
                if ($now !== null) {
                    $updateData[static::UPDATED_AT] = $now;
                }

                $result = db($instance->getConnectionName())
                    ->table($instance->getTable())
                    ->whereIn($instance->primaryKey, $chunk)
                    ->update($updateData);

                if ($result === false) {
                    break;
                }

                $restored += count($chunk);
            }

            return $restored;
        }

        $restored = 0;

        foreach (array_chunk($ids, $instance->getBulkWriteBatchSize()) as $chunk) {
            $models = static::withTrashed()->find($chunk);
            if (!is_array($models) || $models === []) {
                continue;
            }

            foreach ($models as $model) {
                if ($model instanceof static && $model->restore()) {
                    $restored++;
                }
            }
        }

        return $restored;
    }

    /**
     * Force-delete rows by primary key(s). Returns the number of rows deleted.
     *
     * @param int|string|array<int|string> $ids
     */
    public static function forceDestroy(int|string|array $ids): int
    {
        $ids = array_values(array_unique((array) $ids));
        if ($ids === []) {
            return 0;
        }

        static::bootIfNotBooted();

        $instance = new static();

        if (!$instance->requiresPerModelForceDeleteLifecycle()) {
            $deleted = 0;

            foreach (array_chunk($ids, $instance->getBulkWriteBatchSize()) as $chunk) {
                $result = db($instance->getConnectionName())
                    ->table($instance->getTable())
                    ->whereIn($instance->primaryKey, $chunk)
                    ->delete();

                if ($result === false) {
                    break;
                }

                $deleted += count($chunk);
            }

            return $deleted;
        }

        $deleted = 0;

        foreach (array_chunk($ids, $instance->getBulkWriteBatchSize()) as $chunk) {
            $models = $instance->softDeletes
                ? static::withTrashed()->find($chunk)
                : static::findById($chunk);

            if (!is_array($models) || $models === []) {
                continue;
            }

            foreach ($models as $model) {
                if ($model instanceof static && $model->forceDelete()) {
                    $deleted++;
                }
            }
        }

        return $deleted;
    }

    /**
     * Start a query that includes soft-deleted rows.
     */
    public static function withTrashed(): ModelQuery
    {
        return (new static())->newQuery(withTrashed: true);
    }

    /**
     * Start a query that returns ONLY soft-deleted rows.
     */
    public static function onlyTrashed(): ModelQuery
    {
        return (new static())->newQuery(onlyTrashed: true);
    }

    /**
     * Forward any undefined static method call to a fresh ModelQuery.
     *
     *   User::where('active', 1)->get()
     *   User::orderBy('name')->paginate(20)
     *   User::count()
     */
    public static function __callStatic(string $method, array $args): mixed
    {
        if (in_array($method, static::observableEvents(), true) && isset($args[0]) && is_callable($args[0])) {
            static::registerModelEvent($method, $args[0]);
            return null;
        }

        return (new static())->newQuery()->{$method}(...$args);
    }

    public function __call(string $method, array $args): mixed
    {
        return $this->newQuery()->{$method}(...$args);
    }

    // ── Attribute access ─────────────────────────────────────────────

    public function __get(string $key): mixed
    {
        return $this->getAttribute($key);
    }

    public function __set(string $key, mixed $value): void
    {
        $this->setAttribute($key, $value);
    }

    public function __isset(string $key): bool
    {
        return isset($this->attributes[$key]);
    }

    public function __unset(string $key): void
    {
        unset($this->attributes[$key]);
    }

    /** Read an attribute, applying any declared cast. */
    public function getAttribute(string $key): mixed
    {
        if (!array_key_exists($key, $this->attributes)) {
            return null;
        }
        return $this->castAttribute($key, $this->attributes[$key]);
    }

    /** Write an attribute without any guard check (use fill() for guarded writes). */
    public function setAttribute(string $key, mixed $value): static
    {
        $this->attributes[$key] = $value;
        return $this;
    }

    /**
     * Apply the declared cast to a raw attribute value.
     *
     * Supported: int|integer, float|double, bool|boolean, string,
     *            array, json, datetime
     */
    protected function castAttribute(string $key, mixed $value): mixed
    {
        if (!isset($this->casts[$key]) || $value === null) {
            return $value;
        }

        return match ($this->casts[$key]) {
            'int', 'integer'  => (int) $value,
            'float', 'double' => (float) $value,
            'bool', 'boolean' => (bool) $value,
            'string'          => (string) $value,
            'array'           => is_string($value)
                                    ? (array) json_decode($value, true)
                                    : (array) $value,
            'json'            => is_string($value)
                                    ? json_decode($value, true)
                                    : $value,
            'datetime'        => $value instanceof \DateTimeImmutable
                                    ? $value
                                    : new \DateTimeImmutable((string) $value),
            default           => $value,
        };
    }

    // ── fill / forceFill ─────────────────────────────────────────────

    /**
     * Mass-assign attributes, respecting $fillable and $guarded.
     * Silently ignores keys that are not fillable.
     */
    public function fill(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            if ($this->isFillable((string) $key)) {
                $this->setAttribute((string) $key, $value);
            }
        }
        return $this;
    }

    /** Mass-assign attributes, bypassing all fill guards. */
    public function forceFill(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            $key = (string) $key;
            $this->setAttribute($key, $value);
            $this->forceFilled[$key] = true;
        }
        return $this;
    }

    /**
     * Determine if a column name may be mass-assigned via fill().
     *
     * Rules (applied in order):
     *  1. Always blocked if in $guarded.
     *  2. Only allowed if in $fillable (when $fillable is non-empty).
     *  3. Open model (no $fillable): allow all except $primaryKey.
     */
    protected function isFillable(string $key): bool
    {
        if (!empty($this->guarded) && in_array($key, $this->guarded, true)) {
            return false;
        }
        if (!empty($this->fillable)) {
            return in_array($key, $this->fillable, true);
        }
        return $key !== $this->primaryKey;
    }

    // ── Dirty / change tracking ───────────────────────────────────────

    /**
     * Determine if any (or specific) attributes changed since last sync.
     *
     * @param string|string[]|null $keys  null = check all attributes
     */
    public function isDirty(string|array|null $keys = null): bool
    {
        $dirty = $this->getDirty();
        if ($keys === null) {
            return !empty($dirty);
        }
        foreach ((array) $keys as $key) {
            if (array_key_exists($key, $dirty)) {
                return true;
            }
        }
        return false;
    }

    public function isClean(string|array|null $keys = null): bool
    {
        return !$this->isDirty($keys);
    }

    /** Return all attributes that differ from $original. */
    public function getDirty(): array
    {
        $dirty = [];
        foreach ($this->attributes as $key => $value) {
            if (!array_key_exists($key, $this->original) || $this->original[$key] !== $value) {
                $dirty[$key] = $value;
            }
        }
        return $dirty;
    }

    /**
     * Return the original attribute value(s) as loaded from the DB.
     *
     * @return mixed  Full array when $key is null, scalar value otherwise.
     */
    public function getOriginal(?string $key = null, mixed $default = null): mixed
    {
        return $key === null
            ? $this->original
            : ($this->original[$key] ?? $default);
    }

    /** Sync the original snapshot to the current attributes (called after save). */
    public function syncOriginal(): static
    {
        $this->original = $this->attributes;
        $this->forceFilled = [];
        return $this;
    }

    protected function freshTimestamp(): string
    {
        return date('Y-m-d H:i:s');
    }

    protected function touchTimestamps(bool $isCreate = false): void
    {
        if (!$this->timestamps) {
            return;
        }

        $now = $this->freshTimestamp();

        if ($isCreate && !array_key_exists(static::CREATED_AT, $this->attributes)) {
            $this->attributes[static::CREATED_AT] = $now;
        }

        $this->attributes[static::UPDATED_AT] = $now;
    }

    // ── Row-instance persistence ──────────────────────────────────────────

    /**
     * Persist the model: INSERT if new, UPDATE if it already exists.
     */
    public function save(): bool
    {
        return $this->exists ? $this->performUpdate() : $this->performInsert();
    }

    /**
     * Soft-delete if enabled; hard-delete otherwise.
     */
    public function delete(): bool
    {
        if (!$this->exists) {
            return false;
        }
        return $this->softDeletes ? $this->performSoftDelete() : $this->performHardDelete();
    }

    /**
     * Hard-delete regardless of soft-delete configuration.
     */
    public function forceDelete(): bool
    {
        if (!$this->exists) {
            return false;
        }
        return $this->performHardDelete(true);
    }

    /**
     * Restore a soft-deleted row (sets deleted_at = null).
     */
    public function restore(): bool
    {
        if (!$this->softDeletes || !$this->exists) {
            return false;
        }

        if (!$this->fireModelEvent('restoring', true)) {
            return false;
        }

        $now = $this->freshTimestamp();
        $this->attributes[static::DELETED_AT] = null;
        if ($this->timestamps) {
            $this->attributes[static::UPDATED_AT] = $now;
        }
        $updateData = [static::DELETED_AT => null];
        if ($this->timestamps) {
            $updateData[static::UPDATED_AT] = $now;
        }
        $result = db($this->getConnectionName())
            ->table($this->getTable())
            ->where($this->primaryKey, $this->attributes[$this->primaryKey])
            ->update($updateData);
        if ($result !== false) {
            $this->syncOriginal();
            $this->fireModelEvent('restored');
        }
        return $result !== false;
    }

    /** Re-load this model's attributes from the database. */
    public function refresh(): static
    {
        if (!$this->exists) {
            return $this;
        }
        $pkValue = $this->attributes[$this->primaryKey] ?? null;
        if ($pkValue === null) {
            return $this;
        }
        $fresh = static::findById($pkValue);
        if ($fresh instanceof static) {
            $this->attributes = $fresh->attributes;
            $this->original   = $fresh->original;
            $this->exists = $fresh->exists;
            $this->wasRecentlyCreated = false;
        }
        return $this;
    }

    public static function hydrateRecord(array $attributes): static
    {
        $model = new static();
        $model->forceFill($attributes);
        $model->syncOriginal();
        $model->exists = true;
        $model->wasRecentlyCreated = false;
        $model->fireModelEvent('retrieved');

        return $model;
    }

    protected function performInsert(): bool
    {
        if (!$this->fireModelEvent('saving', true) || !$this->fireModelEvent('creating', true)) {
            return false;
        }

        $this->touchTimestamps(isCreate: true);

        $builder = db($this->getConnectionName())->table($this->getTable());
        if (!empty($this->fillable) && !$this->hasForceFilledAttributes()) {
            $builder->setFillable($this->fillable);
        }
        if (!empty($this->guarded) && !$this->hasForceFilledAttributes()) {
            $builder->setGuarded($this->guarded);
        }

        $id = $builder->insertGetId($this->filterPersistableAttributes($this->attributes, false));

        if ($id === null || $id === false) {
            return false;
        }

        if ($this->incrementing) {
            $this->attributes[$this->primaryKey] =
                $this->keyType === 'int' ? (int) $id : (string) $id;
        }
        $this->exists             = true;
        $this->wasRecentlyCreated = true;
        $this->syncOriginal();
        $this->fireModelEvent('created');
        $this->fireModelEvent('saved');
        return true;
    }

    protected function performUpdate(): bool
    {
        $dirty = $this->getDirty();
        if (empty($dirty)) {
            return true;
        }

        if (!$this->fireModelEvent('saving', true) || !$this->fireModelEvent('updating', true)) {
            return false;
        }

        $this->touchTimestamps();
        $dirty = $this->filterPersistableAttributes($this->getDirty(), true); // re-read after touching timestamps

        if (empty($dirty)) {
            $this->syncOriginal();
            return true;
        }

        $pkValue = $this->attributes[$this->primaryKey] ?? null;
        if ($pkValue === null) {
            throw new RuntimeException(
                static::class . '::performUpdate() — primary key attribute is null.'
            );
        }

        $builder = db($this->getConnectionName())->table($this->getTable());
        if (!empty($this->fillable) && !$this->hasForceFilledAttributes()) {
            $builder->setFillable($this->fillable);
        }
        if (!empty($this->guarded) && !$this->hasForceFilledAttributes()) {
            $builder->setGuarded($this->guarded);
        }

        $result = $builder->where($this->primaryKey, $pkValue)->update($dirty);

        if ($result !== false) {
            $this->syncOriginal();
            $this->fireModelEvent('updated');
            $this->fireModelEvent('saved');
        }
        return $result !== false;
    }

    protected function performSoftDelete(): bool
    {
        if (!$this->fireModelEvent('deleting', true)) {
            return false;
        }

        $now = $this->freshTimestamp();
        $this->attributes[static::DELETED_AT] = $now;
        if ($this->timestamps) {
            $this->attributes[static::UPDATED_AT] = $now;
        }

        $updateData = [static::DELETED_AT => $now];
        if ($this->timestamps) {
            $updateData[static::UPDATED_AT] = $now;
        }

        $result = db($this->getConnectionName())
            ->table($this->getTable())
            ->where($this->primaryKey, $this->attributes[$this->primaryKey])
            ->update($updateData);

        if ($result !== false) {
            $this->syncOriginal();
            $this->fireModelEvent('deleted');
        }
        return $result !== false;
    }

    protected function hasForceFilledAttributes(): bool
    {
        return $this->forceFilled !== [];
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    protected function filterPersistableAttributes(array $attributes, bool $forUpdate): array
    {
        $filtered = [];

        foreach ($attributes as $key => $value) {
            $key = (string) $key;
            if ($this->shouldPersistAttribute($key, $forUpdate)) {
                $filtered[$key] = $value;
            }
        }

        return $filtered;
    }

    protected function shouldPersistAttribute(string $key, bool $forUpdate): bool
    {
        if ($key === $this->primaryKey) {
            return $forUpdate ? false : !$this->incrementing;
        }

        if (isset($this->forceFilled[$key])) {
            return true;
        }

        if ($key === static::UPDATED_AT) {
            return $this->timestamps;
        }

        if ($key === static::CREATED_AT) {
            return $this->timestamps && !$forUpdate;
        }

        if ($key === static::DELETED_AT) {
            return $this->softDeletes;
        }

        return $this->isFillable($key);
    }

    protected function performHardDelete(bool $force = false): bool
    {
        $pkValue = $this->attributes[$this->primaryKey] ?? null;
        if ($pkValue === null) {
            return false;
        }

        if (!$this->fireModelEvent('deleting', true)) {
            return false;
        }

        if ($force && !$this->fireModelEvent('forceDeleting', true)) {
            return false;
        }

        $result = db($this->getConnectionName())
            ->table($this->getTable())
            ->where($this->primaryKey, $pkValue)
            ->delete();

        if ($result !== false) {
            $this->exists = false;
            $this->wasRecentlyCreated = false;
            if ($force) {
                $this->fireModelEvent('forceDeleted');
            }
            $this->fireModelEvent('deleted');
        }
        return $result !== false;
    }

    public static function observe(object|string $observer): void
    {
        static::bootIfNotBooted();

        if (is_string($observer)) {
            if (!class_exists($observer)) {
                throw new RuntimeException('Observer class not found: ' . $observer);
            }
            $observer = new $observer();
        }

        foreach (static::observableEvents() as $event) {
            if (is_callable([$observer, $event])) {
                static::registerModelEvent($event, [$observer, $event]);
            }
        }
    }

    public static function flushEventListeners(): void
    {
        unset(static::$modelEventListeners[static::class]);
    }

    public static function clearBootedModelState(): void
    {
        unset(
            static::$bootedModels[static::class],
            static::$modelEventListeners[static::class],
            static::$traitInitializers[static::class],
            static::$mutedEventDepth[static::class]
        );
    }

    public static function withoutEvents(callable $callback): mixed
    {
        static::bootIfNotBooted();
        static::$mutedEventDepth[static::class] = (static::$mutedEventDepth[static::class] ?? 0) + 1;

        try {
            return $callback();
        } finally {
            $remaining = (static::$mutedEventDepth[static::class] ?? 1) - 1;
            if ($remaining <= 0) {
                unset(static::$mutedEventDepth[static::class]);
            } else {
                static::$mutedEventDepth[static::class] = $remaining;
            }
        }
    }

    protected static function registerModelEvent(string $event, callable $listener): void
    {
        if (!in_array($event, static::observableEvents(), true)) {
            throw new RuntimeException('Unsupported model event: ' . $event);
        }

        static::$modelEventListeners[static::class][$event] ??= [];
        static::$modelEventListeners[static::class][$event][] = $listener;
    }

    protected static function observableEvents(): array
    {
        return self::OBSERVABLE_EVENTS;
    }

    protected function newBulkWriteBuilder(): mixed
    {
        $builder = db($this->getConnectionName())->table($this->getTable());

        if (!empty($this->fillable)) {
            $builder->setFillable($this->fillable);
        }

        if (!empty($this->guarded)) {
            $builder->setGuarded($this->guarded);
        }

        return $builder;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    protected function prepareBulkWriteRows(array $rows, bool $forUpsert): array
    {
        $timestamp = $this->timestamps ? $this->freshTimestamp() : null;
        $prepared = [];

        foreach ($rows as $row) {
            if (!is_array($row) || $row === []) {
                continue;
            }

            $row = $this->filterBulkWriteRow($row);
            if ($row === []) {
                continue;
            }

            if ($timestamp !== null) {
                if (!$forUpsert || !array_key_exists(static::CREATED_AT, $row)) {
                    $row[static::CREATED_AT] = $row[static::CREATED_AT] ?? $timestamp;
                }

                $row[static::UPDATED_AT] = $timestamp;
            }

            if ($row !== []) {
                $prepared[] = $row;
            }
        }

        return $prepared;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    protected function prepareBulkUpdateRows(array $rows): array
    {
        $timestamp = $this->timestamps ? $this->freshTimestamp() : null;
        $prepared = [];

        foreach ($rows as $row) {
            if (!is_array($row) || $row === []) {
                continue;
            }

            $primaryKeyValue = $row[$this->primaryKey] ?? null;
            if ($primaryKeyValue === null || $primaryKeyValue === '') {
                continue;
            }

            $filteredRow = $this->filterBulkWriteRow($row, preserveKeys: [$this->primaryKey]);
            $filteredRow[$this->primaryKey] = $primaryKeyValue;

            if ($timestamp !== null) {
                $filteredRow[static::UPDATED_AT] = $timestamp;
            }

            if (count($filteredRow) <= 1) {
                continue;
            }

            $prepared[] = $filteredRow;
        }

        return $prepared;
    }

    protected function processBulkStream(iterable $rows, ?int $batchSize, callable $prepareBatch, callable $writeBatch, string $operation, ?callable $progress = null): int
    {
        $resolvedBatchSize = $batchSize !== null ? $this->normalizeBulkBatchSize($batchSize) : null;
        $buffer = [];
        $processedRows = 0;
        $processedBatches = 0;

        $flush = function () use (&$buffer, &$processedRows, &$processedBatches, $prepareBatch, $writeBatch, $operation, $progress): bool {
            if ($buffer === []) {
                return true;
            }

            $preparedBatch = $prepareBatch($buffer);
            $buffer = [];

            if ($preparedBatch === []) {
                return true;
            }

            $result = $writeBatch($preparedBatch);
            if (!$this->bulkWriteSucceeded($result)) {
                throw new RuntimeException('Bulk ' . $operation . ' batch failed.');
            }

            $processedRows += count($preparedBatch);
            $processedBatches++;

            if ($progress !== null) {
                $shouldContinue = $progress([
                    'operation' => $operation,
                    'processed_rows' => $processedRows,
                    'batches_processed' => $processedBatches,
                    'last_batch_rows' => count($preparedBatch),
                ]);

                if ($shouldContinue === false) {
                    return false;
                }
            }

            return true;
        };

        foreach ($rows as $row) {
            if (!is_array($row) || $row === []) {
                continue;
            }

            if ($resolvedBatchSize === null) {
                $resolvedBatchSize = $this->normalizeBulkBatchSize(null, $row);
            }

            $buffer[] = $row;

            if (count($buffer) >= $resolvedBatchSize && !$flush()) {
                return $processedRows;
            }
        }

        $flush();

        return $processedRows;
    }

    protected function normalizeBulkBatchSize(?int $batchSize, ?array $sampleRow = null): int
    {
        $batchSize ??= $this->recommendedBulkWriteBatchSize($sampleRow);

        if ($batchSize < 1) {
            throw new RuntimeException('Bulk batch size must be greater than zero.');
        }

        return $batchSize;
    }

    protected function recommendedBulkWriteBatchSize(?array $sampleRow = null): int
    {
        $default = $this->getBulkWriteBatchSize();
        if ($sampleRow === null || $sampleRow === []) {
            return $default;
        }

        $columnCount = count($sampleRow);

        return match (true) {
            $columnCount <= 4 => $default,
            $columnCount <= 12 => min($default, 1000),
            $columnCount <= 24 => min($default, 500),
            default => min($default, 250),
        };
    }

    /**
     * @param array<string, mixed> $row
     * @param string[] $preserveKeys
     * @return array<string, mixed>
     */
    protected function filterBulkWriteRow(array $row, array $preserveKeys = []): array
    {
        if (!empty($this->fillable)) {
            $allowed = array_fill_keys($this->fillable, true);
            $allowed[static::CREATED_AT] = true;
            $allowed[static::UPDATED_AT] = true;
            foreach ($preserveKeys as $key) {
                $allowed[$key] = true;
            }
            $row = array_intersect_key($row, $allowed);
        }

        if (!empty($this->guarded)) {
            $blocked = array_fill_keys($this->guarded, true);
            foreach ($preserveKeys as $key) {
                unset($blocked[$key]);
            }
            $row = array_diff_key($row, $blocked);
        }

        return $row;
    }

    /**
     * @param string[]|null $updateColumns
     * @return string[]|null
     */
    protected function prepareBulkUpsertUpdateColumns(?array $updateColumns): ?array
    {
        if ($updateColumns === null) {
            return null;
        }

        $columns = array_values(array_unique($updateColumns));
        $columns = array_values(array_filter($columns, fn(string $column): bool => !$this->isBulkWriteColumnGuarded($column)));

        if ($this->timestamps && !in_array(static::UPDATED_AT, $columns, true)) {
            $columns[] = static::UPDATED_AT;
        }

        return $columns;
    }

    protected function isBulkWriteColumnGuarded(string $column): bool
    {
        if (!empty($this->fillable)) {
            return !in_array($column, $this->fillable, true)
                && $column !== static::CREATED_AT
                && $column !== static::UPDATED_AT;
        }

        return in_array($column, $this->guarded, true);
    }

    protected function bulkWriteSucceeded(mixed $result): bool
    {
        if ($result === false) {
            return false;
        }

        if (is_array($result) && isset($result['code']) && (int) $result['code'] >= 400) {
            return false;
        }

        if (is_object($result) && isset($result->code) && (int) $result->code >= 400) {
            return false;
        }

        return true;
    }

    protected function fireModelEvent(string $event, bool $halt = false): bool
    {
        static::bootIfNotBooted();

        if ((static::$mutedEventDepth[static::class] ?? 0) > 0) {
            return true;
        }

        foreach (static::$modelEventListeners[static::class][$event] ?? [] as $listener) {
            if ($listener($this) === false && $halt) {
                return false;
            }
        }

        return true;
    }

    protected function requiresPerModelDeleteLifecycle(): bool
    {
        if ($this->softDeletes) {
            return true;
        }

        foreach (['deleting', 'deleted', 'forceDeleting', 'forceDeleted'] as $event) {
            if (!empty(static::$modelEventListeners[static::class][$event] ?? [])) {
                return true;
            }
        }

        return false;
    }

    protected function requiresPerModelRestoreLifecycle(): bool
    {
        foreach (['restoring', 'restored'] as $event) {
            if (!empty(static::$modelEventListeners[static::class][$event] ?? [])) {
                return true;
            }
        }

        return false;
    }

    protected function requiresPerModelForceDeleteLifecycle(): bool
    {
        foreach (['deleting', 'deleted', 'forceDeleting', 'forceDeleted'] as $event) {
            if (!empty(static::$modelEventListeners[static::class][$event] ?? [])) {
                return true;
            }
        }

        return false;
    }

    public function saveQuietly(): bool
    {
        return static::withoutEvents(fn() => $this->save()) === true;
    }

    public function deleteQuietly(): bool
    {
        return static::withoutEvents(fn() => $this->delete()) === true;
    }

    public function restoreQuietly(): bool
    {
        return static::withoutEvents(fn() => $this->restore()) === true;
    }

    public function forceDeleteQuietly(): bool
    {
        return static::withoutEvents(fn() => $this->forceDelete()) === true;
    }

    /**
     * @return array<int, string>
     */
    protected static function classUsesRecursive(string $class): array
    {
        $results = [];

        foreach (array_reverse(class_parents($class) ?: []) as $parent) {
            foreach (class_uses($parent) ?: [] as $trait) {
                $results[$trait] = $trait;
                foreach (static::traitUsesRecursive($trait) as $nestedTrait) {
                    $results[$nestedTrait] = $nestedTrait;
                }
            }
        }

        foreach (class_uses($class) ?: [] as $trait) {
            $results[$trait] = $trait;
            foreach (static::traitUsesRecursive($trait) as $nestedTrait) {
                $results[$nestedTrait] = $nestedTrait;
            }
        }

        return array_values($results);
    }

    /**
     * @return array<int, string>
     */
    protected static function traitUsesRecursive(string $trait): array
    {
        $traits = class_uses($trait) ?: [];
        $results = [];

        foreach ($traits as $nestedTrait) {
            $results[$nestedTrait] = $nestedTrait;
            foreach (static::traitUsesRecursive($nestedTrait) as $deepTrait) {
                $results[$deepTrait] = $deepTrait;
            }
        }

        return array_values($results);
    }

    protected static function classBasename(string $class): string
    {
        $position = strrpos($class, '\\');
        return $position === false ? $class : substr($class, $position + 1);
    }

    // ── Serialization ─────────────────────────────────────────────────────

    /**
     * Return the model's attributes as an array, excluding $hidden columns.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = [];
        foreach ($this->attributes as $key => $value) {
            if (!in_array($key, $this->hidden, true)) {
                $result[$key] = $this->castAttribute($key, $value);
            }
        }
        return $result;
    }

    /** Return a JSON string of the visible attributes. */
    public function toJson(int $flags = JSON_UNESCAPED_UNICODE): string
    {
        return json_encode($this->toArray(), $flags) ?: '{}';
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    // ── Miscellaneous helpers ─────────────────────────────────────────────

    /** Return the value of the primary key attribute. */
    public function getKey(): mixed
    {
        return $this->attributes[$this->primaryKey] ?? null;
    }

    /** Return the primary key column name. */
    public function getKeyName(): string
    {
        return $this->primaryKey;
    }

    /** Return the default per-page count for paginate(). */
    public function getPerPage(): int
    {
        return $this->perPage;
    }

    /**
     * Check whether this model represents the same DB row as another.
     */
    public function is(mixed $other): bool
    {
        return $other instanceof static
            && $this->getKey() !== null
            && $this->getKey() === $other->getKey()
            && $this->getTable() === $other->getTable();
    }

    public function isNot(mixed $other): bool
    {
        return !$this->is($other);
    }
}
