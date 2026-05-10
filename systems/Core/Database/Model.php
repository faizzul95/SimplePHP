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

    // ── Constructor ───────────────────────────────────────────────────

    public function __construct(array $attributes = [])
    {
        $this->fill($attributes);
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
        $builder = db($this->getConnectionName())->table($this->getTable());

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
        $result   = db($instance->getConnectionName())
            ->table($instance->getTable())
            ->insert($rows);
        return $result !== false;
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
        $instance = new static();
        $result   = db($instance->getConnectionName())
            ->table($instance->getTable())
            ->whereIn($instance->primaryKey, $ids)
            ->delete();
        return ($result !== false) ? count($ids) : 0;
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
        return (new static())->newQuery()->{$method}(...$args);
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
            $this->setAttribute((string) $key, $value);
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
        return $this->performHardDelete();
    }

    /**
     * Restore a soft-deleted row (sets deleted_at = null).
     */
    public function restore(): bool
    {
        if (!$this->softDeletes || !$this->exists) {
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
        }
        return $this;
    }

    protected function performInsert(): bool
    {
        $this->touchTimestamps(isCreate: true);

        $builder = db($this->getConnectionName())->table($this->getTable());
        if (!empty($this->fillable)) {
            $builder->setFillable($this->fillable);
        }
        if (!empty($this->guarded)) {
            $builder->setGuarded($this->guarded);
        }

        $id = $builder->insertGetId($this->attributes);

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
        return true;
    }

    protected function performUpdate(): bool
    {
        $dirty = $this->getDirty();
        if (empty($dirty)) {
            return true;
        }

        $this->touchTimestamps();
        $dirty = $this->getDirty(); // re-read after touching timestamps

        $pkValue = $this->attributes[$this->primaryKey] ?? null;
        if ($pkValue === null) {
            throw new RuntimeException(
                static::class . '::performUpdate() — primary key attribute is null.'
            );
        }

        $result = db($this->getConnectionName())
            ->table($this->getTable())
            ->where($this->primaryKey, $pkValue)
            ->update($dirty);

        if ($result !== false) {
            $this->syncOriginal();
        }
        return $result !== false;
    }

    protected function performSoftDelete(): bool
    {
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
        }
        return $result !== false;
    }

    protected function performHardDelete(): bool
    {
        $pkValue = $this->attributes[$this->primaryKey] ?? null;
        if ($pkValue === null) {
            return false;
        }

        $result = db($this->getConnectionName())
            ->table($this->getTable())
            ->where($this->primaryKey, $pkValue)
            ->delete();

        if ($result !== false) {
            $this->exists = false;
        }
        return $result !== false;
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
