# 26. Schema Builder & Migration System

## Overview

The Schema Builder provides a fluent, database-agnostic API for creating and modifying database tables, indexes, procedures, functions, triggers, and views. It compiles to driver-specific DDL (currently MySQL/MariaDB, extensible to PostgreSQL, SQLite, etc.).

**Files:**
- `systems/Core/Database/Schema/Schema.php` — Static facade (main API)
- `systems/Core/Database/Schema/Blueprint.php` — Table definition builder
- `systems/Core/Database/Schema/ColumnDefinition.php` — Fluent column modifiers
- `systems/Core/Database/Schema/ForeignKeyDefinition.php` — Foreign key builder
- `systems/Core/Database/Schema/Migration.php` — Base migration class (with `$table`, `$connection`)
- `systems/Core/Database/Schema/Seeder.php` — Base seeder class (with `$table`, `$connection`)
- `systems/Core/Database/Schema/MigrationRunner.php` — Migration/seeder runner with deploy.json tracking
- `systems/Core/Database/Schema/Grammars/SchemaGrammar.php` — Abstract grammar base
- `systems/Core/Database/Schema/Grammars/MySQLGrammar.php` — MySQL/MariaDB DDL compiler

**Directories:**
- `app/database/migrations/` — Migration files (format: `YYYYMMDD_00x_name.php`)
- `app/database/seeders/` — Seeder files (format: `YYYYMMDD_00x_NameSeeder.php`)
- `app/database/deploy.json` — Migration & seeder state tracking (auto-generated)

**Helper:** `schema()` in `systems/hooks.php` returns the Schema class FQCN.

---

## Complete Schema API

### Table Operations

| Method | Signature | Description |
|--------|-----------|-------------|
| `create` | `Schema::create(string $table, Closure $callback): void` | Create a new table |
| `createIfNotExists` | `Schema::createIfNotExists(string $table, Closure $callback): void` | Create table only if it doesn't exist |
| `table` | `Schema::table(string $table, Closure $callback): void` | Modify an existing table |
| `drop` | `Schema::drop(string $table): void` | Drop a table |
| `dropIfExists` | `Schema::dropIfExists(string $table): void` | Drop table if it exists |
| `rename` | `Schema::rename(string $from, string $to): void` | Rename a table |
| `truncate` | `Schema::truncate(string $table): void` | Truncate a table |

### Introspection

| Method | Signature | Return | Description |
|--------|-----------|--------|-------------|
| `hasTable` | `Schema::hasTable(string $table): bool` | `bool` | Check if table exists |
| `hasColumn` | `Schema::hasColumn(string $table, string $column): bool` | `bool` | Check if column exists |
| `getColumnListing` | `Schema::getColumnListing(string $table): array` | `array` | Get all column names |

### Stored Procedures

| Method | Signature | Description |
|--------|-----------|-------------|
| `createProcedure` | `Schema::createProcedure(string $name, array $params, string $body, array $options = [])` | Create stored procedure |
| `dropProcedure` | `Schema::dropProcedure(string $name)` | Drop stored procedure |

### Functions

| Method | Signature | Description |
|--------|-----------|-------------|
| `createFunction` | `Schema::createFunction(string $name, array $params, string $returnType, string $body, array $options = [])` | Create stored function |
| `dropFunction` | `Schema::dropFunction(string $name)` | Drop stored function |

### Triggers

| Method | Signature | Description |
|--------|-----------|-------------|
| `createTrigger` | `Schema::createTrigger(string $name, string $table, string $timing, string $event, string $body, array $options = [])` | Create trigger |
| `dropTrigger` | `Schema::dropTrigger(string $name)` | Drop trigger |

### Views

| Method | Signature | Description |
|--------|-----------|-------------|
| `createView` | `Schema::createView(string $name, string $query, array $options = [])` | Create view |
| `dropView` | `Schema::dropView(string $name)` | Drop view |

### Utility

| Method | Signature | Description |
|--------|-----------|-------------|
| `previewCreate` | `Schema::previewCreate(string $table, Closure $callback): array` | Preview DDL without executing |
| `previewAlter` | `Schema::previewAlter(string $table, Closure $callback): array` | Preview alter DDL |
| `statement` | `Schema::statement(string $sql): void` | Execute raw DDL |
| `connection` | `Schema::connection(string $name): Schema` | Use a named connection |

---

## Blueprint Column Types

### Integer Types

| Method | Signature | Description |
|--------|-----------|-------------|
| `id` | `id(string $column = 'id')` | Auto-increment BIGINT UNSIGNED PRIMARY KEY |
| `bigIncrements` | `bigIncrements(string $column)` | Auto-increment BIGINT UNSIGNED |
| `increments` | `increments(string $column)` | Auto-increment INT UNSIGNED |
| `bigInteger` | `bigInteger(string $column)` | BIGINT |
| `integer` | `integer(string $column)` | INT |
| `mediumInteger` | `mediumInteger(string $column)` | MEDIUMINT |
| `smallInteger` | `smallInteger(string $column)` | SMALLINT |
| `tinyInteger` | `tinyInteger(string $column)` | TINYINT |
| `unsignedBigInteger` | `unsignedBigInteger(string $column)` | BIGINT UNSIGNED |
| `unsignedInteger` | `unsignedInteger(string $column)` | INT UNSIGNED |
| `unsignedMediumInteger` | `unsignedMediumInteger(string $column)` | MEDIUMINT UNSIGNED |
| `unsignedSmallInteger` | `unsignedSmallInteger(string $column)` | SMALLINT UNSIGNED |
| `unsignedTinyInteger` | `unsignedTinyInteger(string $column)` | TINYINT UNSIGNED |

### Decimal / Float

| Method | Signature | Description |
|--------|-----------|-------------|
| `decimal` | `decimal(string $column, int $precision = 8, int $scale = 2)` | DECIMAL(p,s) |
| `float` | `float(string $column, int $precision = 8, int $scale = 2)` | FLOAT(p,s) |
| `double` | `double(string $column, ?int $precision = null, ?int $scale = null)` | DOUBLE |

### String / Text

| Method | Signature | Description |
|--------|-----------|-------------|
| `string` | `string(string $column, int $length = 255)` | VARCHAR(length) |
| `char` | `char(string $column, int $length = 255)` | CHAR(length) |
| `text` | `text(string $column)` | TEXT |
| `mediumText` | `mediumText(string $column)` | MEDIUMTEXT |
| `longText` | `longText(string $column)` | LONGTEXT |
| `tinyText` | `tinyText(string $column)` | TINYTEXT |

### Date / Time

| Method | Signature | Description |
|--------|-----------|-------------|
| `date` | `date(string $column)` | DATE |
| `dateTime` | `dateTime(string $column, int $precision = 0)` | DATETIME |
| `time` | `time(string $column, int $precision = 0)` | TIME |
| `timestamp` | `timestamp(string $column, int $precision = 0)` | TIMESTAMP |
| `year` | `year(string $column)` | YEAR |

### Other Types

| Method | Signature | Description |
|--------|-----------|-------------|
| `boolean` | `boolean(string $column)` | TINYINT(1) |
| `enum` | `enum(string $column, array $allowed)` | ENUM('a','b','c') |
| `set` | `set(string $column, array $allowed)` | SET('a','b','c') |
| `json` | `json(string $column)` | JSON |
| `jsonb` | `jsonb(string $column)` | JSON (alias for MySQL) |
| `binary` | `binary(string $column, int $length = 255)` | BINARY(length) |
| `blob` | `blob(string $column)` | BLOB |
| `uuid` | `uuid(string $column)` | CHAR(36) |

### Shortcut Columns

| Method | Description |
|--------|-------------|
| `timestamps()` | Adds `created_at` and `updated_at` TIMESTAMP columns |
| `softDeletes(string $column = 'deleted_at')` | Adds nullable TIMESTAMP for soft deletion |
| `morphs(string $name)` | Adds `{name}_id` BIGINT UNSIGNED + `{name}_type` VARCHAR(255) + index |
| `foreignId(string $column)` | BIGINT UNSIGNED with constrained() support |
| `rememberToken()` | VARCHAR(100) nullable `remember_token` column |
| `ipAddress(string $column = 'ip_address')` | VARCHAR(45) for IPv4/IPv6 |
| `macAddress(string $column = 'mac_address')` | VARCHAR(17) |

---

## Column Modifiers (ColumnDefinition)

All column methods return a `ColumnDefinition` which supports fluent chaining:

| Modifier | Description |
|----------|-------------|
| `->nullable()` | Allow NULL values |
| `->unsigned()` | UNSIGNED (numeric columns) |
| `->autoIncrement()` | AUTO_INCREMENT |
| `->primary()` | PRIMARY KEY |
| `->unique()` | UNIQUE index |
| `->index()` | Standard index |
| `->default($value)` | Default value |
| `->useCurrent()` | DEFAULT CURRENT_TIMESTAMP |
| `->useCurrentOnUpdate()` | ON UPDATE CURRENT_TIMESTAMP |
| `->after(string $column)` | Place column AFTER another (ALTER) |
| `->first()` | Place column FIRST (ALTER) |
| `->comment(string $text)` | Column comment |
| `->collation(string $collation)` | Column-level collation |
| `->charset(string $charset)` | Column-level charset |
| `->virtualAs(string $expression)` | Virtual generated column |
| `->storedAs(string $expression)` | Stored generated column |
| `->change()` | Modify existing column (ALTER) |
| `->constrained(?string $table, string $column)` | Add foreign key constraint |
| `->cascadeOnDelete()` | ON DELETE CASCADE |
| `->nullOnDelete()` | ON DELETE SET NULL |
| `->cascadeOnUpdate()` | ON UPDATE CASCADE |

---

## Foreign Keys

```php
// Explicit foreign key definition
$table->foreign('user_id')
    ->references('id')
    ->on('users')
    ->onDelete('CASCADE')
    ->onUpdate('CASCADE');

// Shorthand with foreignId + constrained
$table->foreignId('user_id')->constrained()->cascadeOnDelete();

// Custom referenced table/column
$table->foreignId('author_id')->constrained('users', 'id')->nullOnDelete();
```

---

## Index Operations

| Method | Signature | Description |
|--------|-----------|-------------|
| `primary` | `$table->primary(string\|array $columns, ?string $name)` | Primary key |
| `unique` | `$table->unique(string\|array $columns, ?string $name)` | Unique index |
| `index` | `$table->index(string\|array $columns, ?string $name)` | Standard index |
| `fulltext` | `$table->fulltext(string\|array $columns, ?string $name)` | Fulltext index |
| `spatialIndex` | `$table->spatialIndex(string\|array $columns, ?string $name)` | Spatial index |
| `dropPrimary` | `$table->dropPrimary(?string $name)` | Drop primary key |
| `dropUnique` | `$table->dropUnique(string $name)` | Drop unique index |
| `dropIndex` | `$table->dropIndex(string $name)` | Drop index |
| `dropFulltext` | `$table->dropFulltext(string $name)` | Drop fulltext index |
| `dropForeign` | `$table->dropForeign(string $name)` | Drop foreign key |

---

## Table Options

```php
$table->engine('InnoDB');              // Storage engine
$table->charset('utf8mb4');            // Character set
$table->collation('utf8mb4_unicode_ci'); // Collation
$table->comment('User accounts');      // Table comment
$table->temporary();                   // TEMPORARY table
$table->ifNotExists();                 // CREATE IF NOT EXISTS
```

---

## Examples

### 1) Create a Complete Table

```php
use Core\Database\Schema\Schema;

Schema::create('posts', function ($table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->string('title');
    $table->string('slug')->unique();
    $table->text('body')->nullable();
    $table->enum('status', ['draft', 'published', 'archived'])->default('draft');
    $table->json('metadata')->nullable();
    $table->unsignedInteger('view_count')->default(0);
    $table->timestamps();
    $table->softDeletes();

    $table->index('status');
    $table->fulltext(['title', 'body']);
    $table->engine('InnoDB');
    $table->comment('Blog posts');
});
```

### 2) Alter an Existing Table

```php
Schema::table('posts', function ($table) {
    $table->string('subtitle')->nullable()->after('title');
    $table->boolean('is_featured')->default(false);
    $table->dropColumn('metadata');
    $table->dropIndex('posts_status_index');
});
```

### 3) Create If Not Exists

```php
Schema::createIfNotExists('settings', function ($table) {
    $table->id();
    $table->string('key')->unique();
    $table->text('value')->nullable();
    $table->timestamps();
});
```

### 4) Drop & Rename

```php
Schema::dropIfExists('temp_data');
Schema::rename('posts', 'articles');
Schema::truncate('logs');
```

### 5) Stored Procedures

```php
Schema::createProcedure('calculate_order_total', [
    'IN p_order_id INT',
    'OUT p_total DECIMAL(10,2)',
], "
    SELECT SUM(quantity * price) INTO p_total
    FROM order_items
    WHERE order_id = p_order_id;
", [
    'comment' => 'Calculate total for a given order',
    'deterministic' => false,
]);

Schema::dropProcedure('calculate_order_total');
```

### 6) Stored Functions

```php
Schema::createFunction('format_full_name', [
    ['name' => 'p_first', 'type' => 'VARCHAR(50)'],
    ['name' => 'p_last', 'type' => 'VARCHAR(50)'],
], 'VARCHAR(101)', "
    RETURN CONCAT(p_first, ' ', p_last);
", [
    'deterministic' => true,
]);

Schema::dropFunction('format_full_name');
```

### 7) Triggers

```php
Schema::createTrigger(
    'set_created_at',
    'posts',
    'BEFORE',
    'INSERT',
    "SET NEW.created_at = NOW();"
);

Schema::dropTrigger('set_created_at');
```

### 8) Views

```php
Schema::createView('active_users',
    "SELECT id, name, email FROM users WHERE status = 'active' AND deleted_at IS NULL"
);

Schema::dropView('active_users');
```

### 9) Preview DDL Without Executing

```php
$statements = Schema::previewCreate('users', function ($table) {
    $table->id();
    $table->string('name');
    $table->string('email')->unique();
    $table->timestamps();
});

// Returns array of SQL strings — inspect without running
foreach ($statements as $sql) {
    echo $sql . "\n";
}
```

### 10) Introspection

```php
if (Schema::hasTable('users')) {
    $columns = Schema::getColumnListing('users');
    // ['id', 'name', 'email', 'created_at', 'updated_at']

    if (!Schema::hasColumn('users', 'phone')) {
        Schema::table('users', function ($table) {
            $table->string('phone', 20)->nullable()->after('email');
        });
    }
}
```

### 11) Multiple Connections

```php
// Use a different database connection
Schema::connection('analytics')->create('events', function ($table) {
    $table->id();
    $table->string('event_type');
    $table->json('payload');
    $table->timestamp('occurred_at')->useCurrent();
    $table->index('event_type');
});
```

---

## Migration Base Class

For structured schema versioning, extend `Core\Database\Schema\Migration`:

```php
<?php

use Core\Database\Schema\Migration;
use Core\Database\Schema\Schema;
use Core\Database\Schema\Blueprint;

return new class extends Migration
{
    protected string $table = 'users';
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::create($this->table, function (Blueprint $table) {
            $table->id();                  // BIGINT UNSIGNED AUTO_INCREMENT PK
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();          // created_at + updated_at
            $table->softDeletes();         // deleted_at (nullable TIMESTAMP)
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table);
    }
};
```

**Properties:**
| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `$table` | `string` | `''` | The table associated with this migration |
| `$connection` | `string` | `'default'` | Database connection name |

The `Migration` class implements `ForgeInterface` (with `create()` and `alter()` methods) and provides the `up()` / `down()` contract for reversible schema changes. Grammar is cached per instance.

---

## Seeder Base Class

Seeders extend `Core\Database\Schema\Seeder`:

```php
<?php

use Core\Database\Schema\Seeder;

return new class extends Seeder
{
    protected string $table = 'master_roles';
    protected string $connection = 'default';

    public function run(): void
    {
        // Simple insert
        $this->insert($this->table, [
            'role_name' => 'Administrator',
            'role_rank' => 1000,
            'role_status' => 1,
        ]);

        // Idempotent insert or update (upsert)
        $this->insertOrUpdate($this->table, ['id' => 1], [
            'role_name' => 'Super Administrator',
            'role_rank' => 9999,
            'role_status' => 1,
        ]);
    }
};
```

**Properties:**
| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `$table` | `string` | `''` | The table associated with this seeder |
| `$connection` | `string` | `'default'` | Database connection name |

**Methods:**
| Method | Signature | Description |
|--------|-----------|-------------|
| `run` | `abstract run(): void` | Execute the seeder logic |
| `execute` | `execute(string $sql): void` | Run raw SQL |
| `insert` | `insert(string $table, array $data): void` | Insert data (single or bulk) |
| `insertOrUpdate` | `insertOrUpdate(string $table, array $conditions, array $data, string $primaryKey = 'id'): ?array` | Insert or update (upsert) a record |
| `truncate` | `truncate(string $table): void` | Truncate a table |

---

## MigrationRunner (deploy.json Tracking)

The `MigrationRunner` manages all migration/seeder operations and tracks state in `app/database/deploy.json` — no database migration table needed.

**deploy.json structure:**
```json
[
  {
    "file": "20260308_001_create_users_table.php",
    "type": "migrate",
    "batch": 1,
    "migrated_at": "2026-03-08 15:00:00",
    "status": "migrated"
  },
  {
    "file": "20260308_001_MasterRolesSeeder.php",
    "type": "seed",
    "batch": 0,
    "migrated_at": "2026-03-08 15:00:05",
    "status": "seeded"
  }
]
```

**Runner Methods:**
| Method | Description |
|--------|-------------|
| `migrate(?callable $output)` | Run all pending migrations |
| `rollback(?callable $output, ?int $steps)` | Rollback last batch or N batches |
| `reset(?callable $output)` | Rollback all migrations |
| `fresh(?callable $output)` | Drop all tables + re-run all migrations |
| `seed(?string $specific, ?callable $output)` | Run pending seeders or specific seeder |
| `status()` | Get status of all migrations and seeders |

---

## Migration File Format

**Naming:** `YYYYMMDD_00x_descriptive_name.php`

Examples:
- `20260308_001_create_users_table.php`
- `20260308_002_create_master_roles_table.php`
- `20260308_005_create_users_access_tokens_table.php`

The `make:migration` command auto-numbers within a given date.

## Seeder File Format

**Naming:** `YYYYMMDD_00x_DescriptiveNameSeeder.php`

Examples:
- `20260308_001_MasterRolesSeeder.php`
- `20260308_002_SystemAbilitiesSeeder.php`

The `make:seeder` command auto-numbers within a given date, same as migrations.

---

## Console Commands

| Command | Description |
|---------|-------------|
| `migrate` | Run all pending database migrations |
| `migrate:rollback` | Rollback the last batch (`--step=N`) |
| `migrate:reset` | Rollback all migrations |
| `migrate:fresh` | Drop all tables and re-run all migrations (destructive) |
| `migrate:status` | Show migration & seeder status table |
| `db:seed` | Run pending seeders (`--class=SeederFile`) |
| `make:migration` | Generate a new migration file |
| `make:seeder` | Generate a new seeder file |

---

## Extending for New Database Drivers

To add PostgreSQL (or SQLite, SQL Server, etc.):

1. Create `systems/Core/Database/Schema/Grammars/PostgreSQLGrammar.php` extending `SchemaGrammar`
2. Implement all abstract methods (type mapping, compile functions)
3. Register the grammar in `Schema::getGrammar()`:

```php
protected static function getGrammar(): SchemaGrammar
{
    $driver = self::$instance->getDriver();
    return match ($driver) {
        'mysql', 'mariadb' => new MySQLGrammar(),
        'pgsql'            => new PostgreSQLGrammar(),
        default            => throw new \RuntimeException("No schema grammar for driver: {$driver}"),
    };
}
```

The abstract `SchemaGrammar` defines all required compilation methods — the new grammar just needs to provide driver-specific SQL syntax.

---

## How To Use

1. Use `Schema::create()` for new tables with full column definitions.
2. Use `Schema::table()` for modifications to existing tables.
3. Use `Schema::previewCreate()` / `previewAlter()` to inspect DDL before executing.
4. Use `foreignId()->constrained()` shorthand for standard foreign key patterns.
5. Always add `$table->timestamps()` for `created_at`/`updated_at` and `$table->softDeletes()` for `deleted_at`.
6. Use `Schema::hasTable()` / `hasColumn()` for conditional schema changes.
7. Create migration files in `app/database/migrations/` with format `YYYYMMDD_00x_name.php`.
8. Create seeder files in `app/database/seeders/` with format `YYYYMMDD_00x_NameSeeder.php`.
9. Set `protected string $table` and `protected string $connection` in each migration/seeder.
10. Run `php myth migrate` to apply, `php myth migrate:rollback` to revert.
11. Use `php myth make:migration` and `php myth make:seeder` for scaffolding.
12. `$table->id()` creates BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY (like Laravel).
13. Use `insertOrUpdate()` in seeders for idempotent seeding.
14. API tables (`users_access_tokens`, `api_rate_limits`) are managed via migrations — the `Api` component no longer auto-creates them.

## What To Avoid

- Avoid raw DDL strings when the Schema Builder can express the operation.
- Avoid `addslashes()` for SQL values in custom grammars — use proper quote escaping.
- Avoid skipping `->nullable()` on optional fields (MySQL strict mode will reject inserts).
- Avoid creating procedures/functions without the `deterministic` flag when applicable.
- Avoid using `Schema::drop()` without checking `hasTable()` first — use `dropIfExists()`.
- Avoid manually editing `deploy.json` — let `MigrationRunner` manage it.
- Avoid naming migration files without the `YYYYMMDD_00x_` prefix — use `make:migration`.
- Avoid naming seeder files without the `YYYYMMDD_00x_` prefix — use `make:seeder`.

## Benefits

- Database-agnostic schema definitions (swap drivers without rewriting DDL).
- Fluent, readable API matching Laravel conventions.
- Preview/dry-run support for safe deployment.
- Built-in foreign key, index, and table option management.
- Extensible grammar system for multi-driver support.
- deploy.json tracking requires no database table — works before schema exists.
- `timestamps()` and `softDeletes()` shortcuts reduce boilerplate.
- `$table->id()` creates BIGINT UNSIGNED by default (like Laravel).
- Auto-numbered migration filenames (`YYYYMMDD_00x_`) prevent collisions.
- Auto-numbered seeder filenames (`YYYYMMDD_00x_`) match migration conventions.
- Seeder `insertOrUpdate()` method enables idempotent seeding.
- API tables managed via proper migrations instead of auto-creation.

## Evidence

- `systems/Core/Database/Schema/Schema.php`
- `systems/Core/Database/Schema/Blueprint.php`
- `systems/Core/Database/Schema/ColumnDefinition.php`
- `systems/Core/Database/Schema/ForeignKeyDefinition.php`
- `systems/Core/Database/Schema/Migration.php`
- `systems/Core/Database/Schema/Seeder.php`
- `systems/Core/Database/Schema/MigrationRunner.php`
- `systems/Core/Database/Schema/Grammars/SchemaGrammar.php`
- `systems/Core/Database/Schema/Grammars/MySQLGrammar.php`
- `systems/Core/Console/Commands.php` (migrationCommands section)
- `app/database/migrations/` (migration files)
- `app/database/seeders/` (seeder files)
- `systems/hooks.php` (schema() helper)
