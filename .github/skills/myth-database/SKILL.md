---
name: myth-database
description: >
  Database layer guide for MythPHP: query builder (db()->table()), schema builder,
  migrations, seeders, soft deletes, transactions, eager loading, cursor pagination,
  N+1 detection, scopes, macros, setFillable/setGuarded guards, debug/profiling tools.
  Use when: writing queries, building migrations, fixing N+1 queries, paginating,
  soft deletes, or schema changes. No model classes — always use db()->table() directly.
argument-hint: 'Task, e.g. "migration", "query builder", "soft deletes", "cursor pagination", "N+1"'
---

# MythPHP Database Layer

## When to Use This Skill

Load this skill for any database work — queries, schema, migrations, seeders,
or performance investigation. Always use `db()->table('table_name')` directly —
there are no model classes. The query builder is NOT Laravel Eloquent;
load this skill before assuming method names or behavior.

---

## Quick Command Reference

```bash
php myth make:model       UserModel       # Generate model stub
php myth make:repository  UserRepository  # Generate repository stub
php myth db:seed                          # Run seeders
php myth db:backup                        # Backup database
php myth route:list                       # Verify routes after schema change
```

---

## 1. Query Builder Basics

Reference: [12-database-query-builder.md](../../../docs/framework_knowledge/12-database-query-builder.md)

Entry point: `db()->table('table_name')` — returns a fresh `BaseDatabase` builder instance.

```php
// SELECT
$users = db()->table('users')
    ->select('id, name, email, status')
    ->whereNull('deleted_at')
    ->where('status', 'active')
    ->orderBy('name', 'asc')
    ->get();                 // array of rows

// Single row
$user = db()->table('users')->where('id', $id)->first();

// Find by PK
$user = db()->table('users')->find($id);
$user = db()->table('users')->findOrFail($id);   // 404 if missing

// Count
$count = db()->table('users')->whereNull('deleted_at')->count();
```

### Where variants

```php
// Basic
->where('status', 'active')
->where('age', '>=', 18)
->orWhere('role', 'admin')

// IN / NOT IN (large lists auto-chunked)
->whereIn('id', [1, 2, 3])
->whereNotIn('status', ['banned', 'suspended'])

// NULL checks
->whereNull('deleted_at')
->whereNotNull('verified_at')

// BETWEEN
->whereBetween('created_at', ['2024-01-01', '2024-12-31'])

// LIKE
->whereLike('name', '%alice%')

// Raw (use sparingly — never pass unsanitized user input)
->whereRaw('YEAR(created_at) = ?', [2024])

// Nested (grouped AND/OR)
->where(function ($q) {
    $q->where('role', 'admin')->orWhere('role', 'manager');
})
```

### Aggregates

```php
->count()
->sum('amount')
->avg('score')
->min('price')
->max('price')
```

---

## 2. CRUD Operations

```php
// INSERT — returns insert ID
$id = db()->table('users')->insert([
    'name'  => 'Alice',
    'email' => 'alice@example.com',
]);

// INSERT MANY
db()->table('logs')->insertMany([
    ['event' => 'login',  'user_id' => 1],
    ['event' => 'logout', 'user_id' => 1],
]);

// UPDATE
db()->table('users')
    ->where('id', $id)
    ->update(['status' => 'inactive', 'updated_at' => timestamp()]);

// UPSERT (insert or update by match condition)
db()->table('settings')
    ->insertOrUpdate(
        ['key' => 'theme'],     // match condition
        ['value' => 'dark']     // values to write
    );

// DELETE
db()->table('sessions')->where('user_id', $id)->delete();
```

---

## 3. Column Guard — `setFillable` / `setGuarded` (No Model Classes)

Reference: [27-security-component.md](../../../docs/framework_knowledge/27-security-component.md) · [12-database-query-builder.md](../../../docs/framework_knowledge/12-database-query-builder.md)

MythPHP does **not** use model classes. Use `setFillable()` or `setGuarded()` directly on
the query builder at the call site to prevent mass-assignment:

```php
// Option A — allowlist (safest): only listed columns are written
db()->table('users')
    ->setFillable(['name', 'email', 'status', 'phone'])
    ->insert($request->toDTO());

// Option B — denylist: block sensitive columns regardless of what toDTO() returns
db()->table('users')
    ->setGuarded(['id', 'password', 'remember_token', 'role'])
    ->where('id', $id)
    ->update($request->toDTO());
```

Two-layer protection always active:
1. **Schema guard** — strips columns not present in the actual table schema.
2. **`setFillable()` / `setGuarded()`** — call-site allowlist or denylist.

Never pass `$request->all()` directly to `insert()` or `update()` without one of the above.

---

## 4. Soft Deletes

```php
// Soft-delete a record (sets deleted_at)
db()->table('users')->where('id', $id)->softDelete();

// Query active records only
db()->table('users')->whereNull('deleted_at')->get();

// Include trashed
db()->table('users')->withTrashed()->get();

// Restore
db()->table('users')->where('id', $id)->restore();

// Force-delete permanently
db()->table('users')->where('id', $id)->forceDelete();
```

Controller helpers (from `Core\Http\Controller`):

```php
$this->softDeleteByEncodedId($encodedId, 'users');
$this->restoreByEncodedId($encodedId, 'users');
```

---

## 5. Pagination

### Standard AJAX Pagination (DataTable compatible)

```php
$result = db()->table('users')
    ->select('id, name, email, status, created_at')
    ->whereNull('deleted_at')
    ->setPaginateFilterColumn(['name', 'email'])
    ->paginate_ajax(request()->all());

$this->paginateResponse($result);  // From Core\Http\Controller
```

### Cursor Pagination (high-volume / infinite scroll)

Reference: [29-cursor-pagination-n1-csp.md](../../../docs/framework_knowledge/29-cursor-pagination-n1-csp.md)

Use for deep result sets where `OFFSET` pagination is too slow (O(n) → O(1)):

```php
$page = db()->table('orders')
    ->where('user_id', $userId)
    ->cursorPaginate(20, 'id', request()->input('cursor'));

return [
    'data' => $page['data'],
    'meta' => [
        'has_more'    => $page['has_more'],
        'next_cursor' => $page['next_cursor'],
        'prev_cursor' => $page['prev_cursor'],
    ],
];
```

### Paginate Count Cache (expensive queries)

```php
$result = db()->table('orders')
    ->whereNull('deleted_at')
    ->rememberCount(300, 'orders.active')       // Cache count for 5 min
    ->paginate_ajax(request()->all());
```

Invalidate after a write:

```php
db()->table('orders')
    ->removeCache('orders.active')
    ->insert($data);
```

---

## 6. Eager Loading (N+1 Prevention)

Reference: [12-database-query-builder.md](../../../docs/framework_knowledge/12-database-query-builder.md)

```php
// Load related rows in a second batched query (no N+1)
$posts = db()->table('posts')
    ->with('comments', 'post_id', 'id', 'post_id, content, created_at')
    ->whereNull('deleted_at')
    ->get();

// Single related record
$orders = db()->table('orders')
    ->withOne('user', 'user_id', 'id', 'id, name, email')
    ->get();

// Aggregate on related table
$categories = db()->table('categories')
    ->withCount('products', 'category_id', 'id')
    ->withSum('products', 'category_id', 'id', 'price')
    ->get();
```

### N+1 Detection

```php
// Enable in CLI/debug mode
db()->table('users')->detectNPlus1()->get();

// Debug helpers (CLI/debug mode only)
db()->table('users')->where('status', 'active')->toSql();
db()->table('users')->where('status', 'active')->toRawSql();
db()->table('users')->where('status', 'active')->toDebugSql();
db()->table('users')->where('status', 'active')->dump();   // prints and continues
db()->table('users')->where('status', 'active')->dd();     // prints and halts
```

---

## 7. Transactions

```php
db()->transaction(function () {
    db()->table('orders')->insert($order);
    db()->table('inventory')->where('product_id', $productId)->decrement('qty', 1);
});

// Manual
db()->beginTransaction();
try {
    db()->table('accounts')->where('id', $from)->decrement('balance', $amount);
    db()->table('accounts')->where('id', $to)->increment('balance', $amount);
    db()->commit();
} catch (\Throwable $e) {
    db()->rollBack();
    throw $e;
}
```

---

## 8. Large-Data Operations

```php
// Chunk through large tables (memory-safe)
db()->table('users')->chunk(500, function ($rows) {
    foreach ($rows as $user) {
        // process $user
    }
});

// Cursor iterator (lazy evaluation)
foreach (db()->table('events')->cursor() as $event) {
    // process $event
}

// Iterable batch inserts
db()->table('logs')->insertInBatches($largeIterable, 500, function ($count) {
    echo "Inserted $count rows\n";
});
```

---

## 9. Schema Builder & Migrations

Reference: [26-schema-builder-migration.md](../../../docs/framework_knowledge/26-schema-builder-migration.md)

### Migration file format: `app/database/migrations/YYYYMMDD_00x_name.php`

```php
use Core\Database\Schema\Migration;
use Core\Database\Schema\Schema;
use Core\Database\Schema\Blueprint;

class CreateUsersTable extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('email', 150)->unique();
            $table->string('password', 255);
            $table->enum('status', ['active', 'inactive', 'banned'])->default('active');
            $table->string('phone', 20)->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamps();   // created_at + updated_at
            $table->softDeletes();  // deleted_at
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
}
```

### Common column types

```php
$table->id();                           // BIGINT UNSIGNED AUTO_INCREMENT PK
$table->string('name', 100);            // VARCHAR(100)
$table->text('bio');                    // TEXT
$table->integer('count');              // INT
$table->bigInteger('views');           // BIGINT
$table->boolean('is_active');          // TINYINT(1)
$table->decimal('price', 10, 2);       // DECIMAL(10,2)
$table->enum('status', ['a','b']);     // ENUM
$table->json('metadata');              // JSON
$table->uuid('uuid');                  // CHAR(36)
$table->timestamp('expires_at');       // TIMESTAMP
$table->timestamps();                  // created_at + updated_at TIMESTAMP NULL
$table->softDeletes();                 // deleted_at TIMESTAMP NULL
```

### Column modifiers

```php
->nullable()
->default('active')
->unsigned()
->after('email')
->comment('User status flag')
```

### Indexes & foreign keys

```php
$table->index(['status', 'created_at']);     // Composite index
$table->unique('email');
$table->fulltext('bio');

// Foreign key (explicit)
$table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

// Drop index
$table->dropIndex('users_status_index');
```

### Schema inspection

```php
Schema::hasTable('users');
Schema::hasColumn('users', 'email');
Schema::getColumnListing('users');

// Preview DDL without executing
$ddl = Schema::previewCreate('test_table', function (Blueprint $table) {
    $table->id();
    $table->string('name');
});
```

### Run seeders

```bash
php myth db:seed
```

Seeder format: `app/database/seeders/YYYYMMDD_00x_NameSeeder.php`

---

## 10. Performance & Profiling

```bash
php myth perf:benchmark    # Routing/validation/query benchmark
php myth perf:report       # View report
php myth perf:report --json --export --reset
```

```php
// Per-query profiling
$report = db()->table('users')->getPerformanceReport();

// Slow query log (automatic — see config/database.php slow_query_threshold)
// Stored to logs/database/
```

---

## Database Checklist

- [ ] No model classes — always use `db()->table('table_name')` directly.
- [ ] Every `insert()` / `update()` uses `setFillable([...])` or `setGuarded([...])`.
- [ ] `whereNull('deleted_at')` applied on all active-record queries.
- [ ] No `whereRaw()` with unsanitized user input.
- [ ] `toDTO()` used (not `all()`) when writing FormRequest data to DB.
- [ ] Migrations include `down()` method for reversibility.
- [ ] Large data sets use `chunk()`, `cursor()`, or `insertInBatches()` — not `get()`.
- [ ] N+1: use `with()` / `withOne()` / `withCount()` for related data.
- [ ] Cursor pagination for feeds/tables > 50K rows.
