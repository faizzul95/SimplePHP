# SimplePHP Framework - Knowledge Base

> **Purpose:** This document captures the conventions, patterns, and reusable
> components of the SimplePHP framework as used in this project. All developers
> should study this before writing new modules.

---

## 1. Architecture Overview

```
index.php                 ← Entry point (routes to web or API)
bootstrap.php             ← Config loader, session, menu, DB init
systems/
  hooks.php               ← Autoloader, helpers, global functions
  app.php                 ← Database connection, scopes/macros, middleware
  Components/             ← Core classes (Request, PageRouter, Api, CSRF, Logger, etc.)
  Core/Database/          ← Query builder, Database class, QueryCache
  Middleware/             ← XMLHttpRequest, DynamicModal middleware + Traits
controllers/              ← Action-based controllers (plain PHP functions)
  ScopeMacroQuery/        ← DB scope & macro registrations
app/
  config/                 ← Config files (auto-loaded by bootstrap)
  cron/                   ← Scheduled task scripts (cron jobs)
  helpers/                ← Helper functions (auto-loaded by hooks)
  routes/                 ← Web & API route definitions
  views/                  ← PHP view templates (Sneat theme)
public/                   ← Static assets (CSS, JS, images, uploads)
```

### Request Lifecycle

```
Browser → index.php → bootstrap.php (config, session, helpers, menu)
                     → systems/app.php (DB connection, scopes, middleware)
                     
  Web:  → app/routes/web.php → PageRouter → loads view file
  API:  → app/routes/api.php → Api component → route handler
  Ajax: → Middleware (XMLHttpRequestMiddleware) → Controller function
  Cron: → php app/cron/script.php → bootstrap.php → direct DB/helper access
```

---

## 2. Routing System

### 2.1 Web Routes (Page-Based)

Pages are routed via **query parameters**, not clean URLs:

```
?_p=dashboard                    → app/views/dashboard/admin.php
?_p=directory                    → app/views/directory/users.php
?_p=rbac&_sp=roles               → app/views/rbac/roles.php
?_p=login                        → app/views/auth/login.php
```

**Key parameters:**
| Param | Purpose |
|-------|---------|
| `_mt` | Menu type (default: `main`) |
| `_p`  | Page key (maps to `$menuList`) |
| `_sp` | Subpage key (maps to `$menuList[type][page]['subpage']`) |

### 2.2 Menu Registration (bootstrap.php)

Every accessible page **must** be registered in `$menuList` in `bootstrap.php`:

```php
$menuList = [
    'main' => [
        'page_key' => [
            'desc' => 'Page Title',
            'url' => paramUrl(['_p' => 'page_key'], true),
            'file' => 'app/views/folder/file.php',
            'icon' => 'tf-icons bx bx-icon',
            'permission' => 'permission-slug',  // null = no check
            'authenticate' => true,              // requires login
            'active' => true,                    // feature toggle
            'subpage' => [
                'sub_key' => [
                    'desc' => 'Sub Title',
                    'url' => paramUrl(['_p' => 'page_key', '_sp' => 'sub_key'], true),
                    'file' => 'app/views/folder/sub.php',
                    'permission' => 'sub-permission-slug',
                    'active' => true,
                    'authenticate' => true,
                ],
            ],
        ],
    ],
];
```

### 2.3 API Routes (RESTful)

> **⛔ CRITICAL WARNING — DO NOT USE API ROUTES**
>
> The API routing system (`app/routes/api.php` and the `Api` component) is **currently broken and non-functional**. Do **NOT** create or rely on API routes for any feature.
>
> **All server communication MUST use the Controller + AJAX pattern** (Section 3) — call controllers directly via `callApi()` / `submitApi()` with the `action` parameter. This is the only supported and working approach.
>
> API route definitions below are kept for reference only. They will be removed or reworked in a future release.

Defined in `app/routes/api.php` using the `Api` component:

```php
$api->post('/v1/auth/login', function () use ($api, $db) { ... });
$api->get('/v1/system/info', function () use ($api, $db) { ... });
```

---

## 3. Controller Pattern

### 3.1 Structure

Controllers are **plain PHP files** with **standalone functions** (no classes). Each function handles one action.

```php
<?php
// controllers/ExampleController.php
require_once '../bootstrap.php';

function list($request) { ... }
function show($request) { ... }
function save($request) { ... }
function destroy($request) { ... }
```

### 3.2 Invocation

Controllers are called via AJAX with an `action` parameter that maps to the function name:

**Frontend (JS):**
```javascript
const res = await callApi('post', 'controllers/UserController.php', {
    'action': 'save',
    'name': 'John',
    'email': 'john@example.com'
});
```

**Form submission:**
```html
<form method="POST" action="controllers/ExampleController.php">
    <input type="hidden" name="action" value="save" readonly>
    <!-- fields -->
</form>
```

### 3.3 Standard Function Signatures

| Function | Purpose | Convention |
|----------|---------|------------|
| `listXxxDatatable($request)` | Server-side datatable listing | Uses `paginate_ajax()` |
| `show($request)` | Get single record | Returns `jsonResponse` |
| `save($request)` | Insert or update | Uses `insertOrUpdate()` |
| `destroy($request)` | Soft delete | Uses `softDelete()` |
| `listSelectOptionXxx($request)` | Dropdown data | Returns array for `<select>` |

### 3.4 Standard Response Pattern

```php
// Success
jsonResponse(['code' => 200, 'message' => 'Saved successfully']);
jsonResponse(['code' => 200, 'data' => $result]);

// Validation error
jsonResponse(['code' => 400, 'message' => $validation->getFirstError()]);

// Not found
jsonResponse(['code' => 404, 'message' => 'Record not found']);

// Operation failure
jsonResponse(['code' => 422, 'message' => 'Failed to save']);
```

---

## 4. Database Query Builder

### 4.1 Getting a Connection

```php
$db = db();           // Default connection
$db = db('slave');    // Named connection
```

### 4.2 CRUD Operations

```php
// SELECT
$users = db()->table('users')->where('status', 1)->get();       // Multiple (returns array of associative arrays)
$user  = db()->table('users')->where('id', 1)->fetch();          // Single (returns associative array)
$count = db()->table('users')->where('status', 1)->count();      // Count (returns integer)
$exists = db()->table('users')->where('email', $e)->exists();    // Boolean

// ⚠️ IMPORTANT: Default return type is ARRAY (multidimensional)
// get()   returns: [['id' => 1, 'name' => 'John'], ['id' => 2, 'name' => 'Jane']]
// fetch() returns: ['id' => 1, 'name' => 'John', 'email' => 'john@example.com']
//
// Access data using ARRAY SYNTAX:
// Correct:   $user['name']  or  foreach($users as $u) { echo $u['name']; }
// Incorrect: $user->name    or  foreach($users as $u) { echo $u->name; }
//
// To convert results to objects or JSON, use these methods BEFORE get()/fetch():
$users = db()->table('users')->toObject()->get();  // Returns array of stdClass objects
$users = db()->table('users')->toJson()->get();    // Returns JSON string
$users = db()->table('users')->toArray()->get();   // Explicit array (default, not needed)
//
// With toObject(), you can then use object syntax:
// foreach($users as $u) { echo $u->name; }  // ✅ Works with toObject()

// INSERT
$result = db()->table('users')->insert([...]);

// UPDATE
$result = db()->table('users')->where('id', 1)->update([...]);

// INSERT OR UPDATE (upsert)
$result = db()->table('users')->insertOrUpdate(
    ['id' => $id],      // match condition
    $data,               // data to save
    'id'                 // primary key column (default: 'id')
);
```

> **⚡ Optimization Rule:**  When the operation is **confirmed** (you know it's a create or an update), use `insert()` or `update()` directly instead of `insertOrUpdate()`.
> - Use `insert($data)` when you are certain it's a **new record** (e.g., logging, creating deliveries, recording transactions).
> - Use `update($data)` with `->where('id', $id)` when you are certain you're **modifying an existing record** (e.g., status changes, callback updates).
> - Only use `insertOrUpdate()` when the **same form/code path** genuinely handles both create and update (e.g., a shared save form where `id` may or may not be present), or for true upsert logic (e.g., `['user_id' => $userId]`).

```php
// SOFT DELETE
$result = db()->table('users')->where('id', $id)->softDelete(
    'deleted_at',        // column name (default: 'deleted_at')
    timestamp()          // value (default: null = current timestamp)
);
// Also supports array: ->softDelete(['status' => 0, 'deleted_at' => timestamp()])

// HARD DELETE
$result = db()->table('users')->where('id', $id)->delete();
// Note: delete() auto-uses soft delete if table has `deleted_at` column
```

**Additional query methods:**

```php
// Conditional clauses
->when($condition, function($q) { ... })    // execute if truthy
->unless($condition, function($q) { ... })  // execute if falsy

// ⭐ BEST PRACTICE: Always use when() for conditional filters instead of
//    wrapping queries in if/else blocks. This produces cleaner, more
//    readable query chains. See Section 4.7 for detailed examples.

// Advanced WHERE clauses
->orWhere('col', 'value')                   // OR condition
->whereNot('col', 'value')                  // NOT equal
->whereIn('col', [1, 2, 3])                 // IN clause
->whereNotIn('col', [1, 2])                 // NOT IN
->whereBetween('col', $start, $end)         // BETWEEN (3 params, not array)
->whereNull('col')                           // IS NULL
->whereNotNull('col')                        // IS NOT NULL
->whereLike('col', '%val%')                  // LIKE (native, no auto-wrap)
->whereColumn('col1', 'col2')               // compare columns
->whereDate('created_at', '2024-01-01')     // date comparison
->whereAny(['col1', 'col2'], 'LIKE', '%v%') // match any column
->whereAll(['col1', 'col2'], 'LIKE', '%v%') // match all columns
->whereHas('table', 'fk', 'lk', callback)  // EXISTS subquery
->whereDoesntHave(...)                       // NOT EXISTS subquery
->whereRaw('col > ?', [5])                  // raw WHERE with bindings

// Aggregates
->count()                                    // COUNT(*)
->sum('amount')                              // SUM
->avg('price')                               // AVG
->min('price') / ->max('price')              // MIN / MAX
->exists() / ->doesntExist()                 // boolean existence

// Result retrieval
->get()                                      // all rows (array)
->fetch()                                    // single row (auto limit 1)
->value('col')                               // single column value
->pluck('col', 'keyCol')                     // extract column values
->firstOrFail()                              // fetch or throw exception
->sole()                                     // exactly one row or throw
->chunk(100, function($rows) { ... })        // process in chunks
->cursor()                                   // generator-based iteration
->lazy()                                     // LazyCollection

// Ordering & limiting
->orderBy('col', 'DESC')                    // order
->orderByDesc('col') / ->orderByAsc('col')  // shorthand
->inRandomOrder()                            // random order
->reorder()                                  // clear ordering
->limit(10) / ->take(10)                     // limit
->offset(20) / ->skip(20)                    // offset
->forPage($page, $perPage)                  // page-based pagination

// Joins
->join('table', 'fk', 'lk', 'LEFT')         // generic join
->leftJoin('table', 'fk', 'lk')             // LEFT JOIN
->rightJoin('table', 'fk', 'lk')            // RIGHT JOIN
->innerJoin('table', 'fk', 'lk')            // INNER JOIN
->outerJoin('table', 'fk', 'lk')            // OUTER JOIN
->crossJoin('table')                         // CROSS JOIN

// Grouping
->groupBy('col')                             // GROUP BY
->having('col', $value, '=')                 // HAVING
->havingRaw('SUM(amount) > ?', [100])        // raw HAVING

// Other
->select('col1, col2') / ->select(['col1'])  // column selection
->selectRaw('COUNT(*) as total')             // raw select expression
->distinct()                                 // DISTINCT
->union($otherQuery) / ->unionAll($query)    // UNION queries
->increment('views', 1)                      // increment column
->decrement('stock', 1)                      // decrement column
->truncate()                                 // truncate table
->toSql() / ->toRawSql()                     // debug SQL output
->dump() / ->dd()                            // debug helpers
->dryRun()                                   // build query without executing
->toArray() / ->toObject() / ->toJson()      // result format conversion
```

### 4.3 Eager Loading (Relationships)

```php
// One-to-One: withOne(alias, table, foreignKey, localKey, callback)
$user = db()->table('users')
    ->withOne('profile', 'user_profile', 'user_id', 'id', function($db) {
        $db->select('id, user_id, role_id')->where('is_main', 1);
    })
    ->fetch();

// One-to-Many: with(alias, table, foreignKey, localKey, callback)
$user = db()->table('users')
    ->with('profiles', 'user_profile', 'user_id', 'id', function($db) {
        $db->select('id, user_id, role_id');
    })
    ->fetch();

// Count: withCount(alias, table, foreignKey, localKey, callback)
$roles = db()->table('master_roles')
    ->withCount('profile', 'user_profile', 'role_id', 'id')
    ->get();

// Aggregate eager loads
->withSum('alias', 'table', 'fk', 'lk', callback)   // SUM of related
->withAvg('alias', 'table', 'fk', 'lk', callback)   // AVG of related
->withMin('alias', 'table', 'fk', 'lk', callback)   // MIN of related
->withMax('alias', 'table', 'fk', 'lk', callback)   // MAX of related

// Nested relationships (chain inside callback)
->withOne('profile', 'user_profile', 'user_id', 'id', function($db) {
    $db->withOne('roles', 'master_roles', 'id', 'role_id', function($db) {
        $db->with('permission', 'system_permission', 'role_id', 'id', function($db) {
            $db->withOne('abilities', 'system_abilities', 'id', 'abilities_id');
        });
    });
})
```

### 4.4 Server-Side Datatable

```php
$result = db()->table('users')
    ->select('id, name, email, status')
    ->whereNull('deleted_at')
    ->when($filter, function($q) use ($filter) {
        $q->where('status', $filter);
    })
    ->setPaginateFilterColumn(['name', 'email'])   // searchable columns
    ->safeOutput()                                  // XSS prevention
    ->paginate_ajax(request()->all());              // handles DataTables params

// Then format $result['data'] with array_map before returning
jsonResponse($result);
```

### 4.5 Transactions

```php
$result = db()->transaction(function($db) {
    $db->table('users')->insert([...]);
    $db->table('user_profile')->insert([...]);
    return true;
});
```

### 4.6 Query Scopes & Macros

All scope and macro files live in `controllers/ScopeMacroQuery/`. The framework auto-loads every PHP file in this folder via `loadScopeMacroDBFunctions()` in `systems/app.php` — no manual registration needed.

#### File Organisation

| File | Purpose | Rule |
|------|---------|------|
| `Scope.php` | **Framework-level** scopes (shipped with SimplePHP) | ⛔ Do NOT modify |
| `Macro.php` | **Framework-level** macros (shipped with SimplePHP) | ⛔ Do NOT modify |
| `ProjectScope.php` | **Project-specific** scopes you create for this project | ✅ Add your scopes here |
| `ProjectMacro.php` | **Project-specific** macros you create for this project | ✅ Add your macros here |

> **⛔ RULE: Never modify `Scope.php` or `Macro.php`** — these are framework base files. Always create project-specific scopes and macros in **separate files** (`ProjectScope.php`, `ProjectMacro.php`). This keeps framework updates clean and avoids merge conflicts.

#### Built-in Scopes (Scope.php)

`withTrashed`, `onlyTrashed`, `latest($column)`, `oldest($column)`, `recent($days, $column)`

#### Built-in Macros (Macro.php)

`whereLike($column, $value)` — auto-wraps value with `%` wildcards

```php
// Usage:
db()->table('users')->latest()->get();
db()->table('users')->whereLike('name', 'john')->get();  // macro: auto-wraps with %
```

#### Adding Project-Specific Scopes (`ProjectScope.php`)

```php
<?php
// controllers/ScopeMacroQuery/ProjectScope.php

if (!function_exists('projectScopeQuery')) {
    function projectScopeQuery($db)
    {
        try {
            $listOfScope = [
                'scopeName' => function ($param1, $param2 = null) {
                    // $this refers to the query builder instance
                    return $this->where('column', $param1);
                },
            ];

            if (!empty($listOfScope)) {
                $db->scopes($listOfScope);
            }
        } catch (Exception $e) {
            logger()->logException($e);
        }
    }
}
```

#### Adding Project-Specific Macros (`ProjectMacro.php`)

```php
<?php
// controllers/ScopeMacroQuery/ProjectMacro.php

if (!function_exists('projectMacroQuery')) {
    function projectMacroQuery($db)
    {
        try {
            $listOfMacros = [
                'macroName' => function ($param1) {
                    return $this->where('column', $param1);
                },
            ];

            if (!empty($listOfMacros)) {
                $db->macros($listOfMacros);
            }
        } catch (Exception $e) {
            logger()->logException($e);
        }
    }
}
```

> **IMPORTANT — No auto soft-delete filtering on reads:** Unlike Laravel, this framework does **NOT** automatically add `whereNull('deleted_at')` to SELECT queries. The `withTrashed` and `onlyTrashed` scopes are registered but non-functional for reads — `BaseDatabase._buildSelectQuery()` never checks the `$this->softDelete` property they set. **You MUST manually add `->whereNull('deleted_at')` to every query** on tables that have a `deleted_at` column. Only the `delete()` method has auto soft-delete behavior (redirects to `softDelete()` if the table has a `deleted_at` column).

> **Note:** `latest` and `oldest` also exist as native query builder methods. The native `whereLike()` does NOT auto-wrap with `%` — use the macro version for convenience.

### 4.7 Best Practice: Use `when()` for Clean Conditional Queries

Always prefer `when()` over wrapping query parts in `if/else` blocks. This keeps the query chain readable, reduces nesting, and avoids breaking the fluent builder pattern.

**❌ Avoid — messy if/else breaks the chain:**

```php
$query = db()->table('users')->whereNull('deleted_at');

if (!empty($status)) {
    $query->where('status', $status);
}
if (!empty($role)) {
    $query->where('role_id', $role);
}
if (!empty($search)) {
    $query->whereLike('name', $search);
}

$result = $query->get();
```

**✅ Preferred — clean fluent chain with `when()`:**

```php
$result = db()->table('users')
    ->whereNull('deleted_at')
    ->when($status, function($q) use ($status) {
        $q->where('status', $status);
    })
    ->when($role, function($q) use ($role) {
        $q->where('role_id', $role);
    })
    ->when($search, function($q) use ($search) {
        $q->whereLike('name', $search);
    })
    ->get();
```

**Common `when()` use cases:**

```php
// Filter by date range (only if both dates provided)
->when($startDate && $endDate, function($q) use ($startDate, $endDate) {
    $q->whereBetween('created_at', $startDate, $endDate);
})

// Include trashed records only for admin
// NOTE: when() does NOT support a second "else" callback — use unless() instead
->when($includeDeleted, function($q) {
    // truthy: skip soft-delete filter (show all including trashed)
})
->unless($includeDeleted, function($q) {
    $q->whereNull('deleted_at');  // falsy: exclude trashed records
})

// Sort direction from user input
->when($sortBy, function($q) use ($sortBy, $sortDir) {
    $q->orderBy($sortBy, $sortDir ?? 'ASC');
})
->unless($sortBy, function($q) {
    $q->orderByDesc('created_at');  // default sort when no sortBy provided
})
```

> **Rule of thumb:** If a query has optional filters (status, search, date range, role, etc.), always use `when()`. It applies to `where()`, `orderBy()`, `select()`, `join()`, and any other builder method.

### 4.8 Mandatory: Use `safeOutput()` for All User-Facing Queries

`safeOutput()` HTML-encodes all string values in query results to prevent **stored XSS attacks**. It must be used on **every query that renders data in the browser**.

```php
// ✅ ALWAYS use safeOutput() before rendering data
$users = db()->table('users')
    ->whereNull('deleted_at')
    ->safeOutput()        // ← MANDATORY for any data shown to users
    ->get();

// ✅ DataTable listing — safeOutput() before paginate_ajax()
$result = db()->table('invoices')
    ->whereNull('deleted_at')
    ->setPaginateFilterColumn(['invoice_no', 'owner_name'])
    ->safeOutput()        // ← MUST be before paginate_ajax()
    ->paginate_ajax(request()->all());

// ✅ Single record for display
$data = db()->table('complaints')->where('id', $id)->safeOutput()->fetch();
```

**When safeOutput() is NOT needed (skip for performance):**

```php
// ❌ Internal processing only — no browser rendering
$record = db()->table('users')->where('id', $id)->fetch();  // used for logic, not display
$count = db()->table('invoices')->where('status', 'overdue')->count();
$exists = db()->table('users')->where('email', $email)->exists();

// ❌ Data used for calculations, comparisons, or backend logic
$balance = db()->table('invoices')->where('unit_id', $unitId)->sum('amount');
```

> **⚠️ Performance note:** `safeOutput()` iterates over every field in every row and applies `htmlspecialchars()`. For large datasets, this adds processing time. Use it **only when data is rendered in HTML** — skip it for internal logic, aggregates, existence checks, and backend processing. For exports, prefer raw data (the export format handles encoding).

> **Rule:** When in doubt, **use `safeOutput()`**. Security takes priority over performance. Only omit it when you are certain the data will never be rendered in a browser.

### 4.9 Memory-Efficient Data Export with `chunk()` and `lazy()`

For **any data export** (CSV, Excel, PDF) or batch processing of large datasets, **never use `->get()`** — it loads the entire result set into memory at once, which can crash the server for large tables.

**Always use `chunk()` or `lazy()` instead:**

#### `chunk()` — Process in fixed-size batches

```php
// ✅ Export to CSV — process 500 rows at a time
function exportInvoicesCsv($request) {
    $filename = 'invoices_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Invoice No', 'Unit', 'Amount', 'Status', 'Due Date']);
    
    db()->table('invoices')
        ->whereNull('deleted_at')
        ->when($status, function($q) use ($status) {
            $q->where('status', $status);
        })
        ->orderByDesc('created_at')
        ->chunk(500, function($rows) use ($output) {
            foreach ($rows as $row) {
                fputcsv($output, [
                    $row['invoice_no'],
                    $row['unit_name'],
                    $row['amount'],
                    $row['status'],
                    $row['due_date'],
                ]);
            }
        });
    
    fclose($output);
    exit;
}
```

#### `lazy()` — Generator-based streaming (lower memory)

```php
// ✅ Export with lazy() — streams rows one at a time
function exportResidentsCsv($request) {
    $filename = 'residents_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Name', 'Email', 'Unit', 'Occupancy Type']);
    
    $rows = db()->table('users')
        ->whereNull('deleted_at')
        ->where('status', 1)
        ->lazy();  // returns a generator — rows fetched one at a time
    
    foreach ($rows as $row) {
        fputcsv($output, [
            $row['name'],
            $row['email'],
            $row['unit_name'],
            $row['occupancy_type'],
        ]);
    }
    
    fclose($output);
    exit;
}
```

**When to use which:**

| Method | Use When | Memory Usage |
|--------|----------|-------------|
| `->get()` | Small datasets (< 1,000 rows), display in browser | ⚠️ Loads ALL rows into memory |
| `->chunk(N, callback)` | Exports, batch updates, large reports | ✅ Only N rows in memory at a time |
| `->lazy()` | Streaming exports, very large datasets | ✅ One row at a time (generator) |
| `->cursor()` | Same as lazy() (alias) | ✅ One row at a time |

> **⛔ RULE: All export functions (CSV, Excel, PDF) MUST use `chunk()` or `lazy()`** — never `get()`. This prevents out-of-memory errors when exporting thousands of records (invoices, residents, fines, delivery history, etc.).

> **Note:** `safeOutput()` is generally **not needed** for exports — the export format (CSV, Excel) handles encoding natively. Skip it for better performance in export functions.

---

## 5. Request Handling

### 5.1 Input Access

```php
$value = request()->input('field_name');           // single field
$value = request()->input('field', 'default');     // with default
$all   = request()->all();                         // all input
$method = request()->method();                     // GET, POST, etc.
$ip    = request()->ip();                          // client IP
$ua    = request()->userAgent();                   // user agent
$browser = request()->browser();                   // browser name
$os    = request()->platform();                    // OS name
$has   = request()->has('field');                   // check field exists
$only  = request()->only(['field1', 'field2']);      // subset of input
$except = request()->except(['password']);           // all except listed
$header = request()->header('X-Custom');            // request header
$isAjax = request()->ajax();                        // is XMLHttpRequest
$uri   = request()->uri();                          // request URI
$url   = request()->url();                          // full URL
$seg   = request()->segment(1);                     // URL segment
```

### 5.2 Validation

> **⛔ MANDATORY RULE: Always validate on BOTH front-end AND back-end.**
>
> - **Front-end validation** (Section 7.6 — `validationJs()`) provides instant feedback to the user and prevents unnecessary server calls.
> - **Back-end validation** (`request()->validate()` below) is the **security gate** — it must NEVER be skipped, even if front-end validation exists.
> - Front-end validation can be bypassed by disabling JavaScript or manipulating requests. Back-end validation is the **only reliable defence**.
> - Both validation layers should use **matching rules** — if the back-end requires `min_length:3|max_length:255`, the front-end should enforce the same.
> - Every form submission flow must follow: **`validationJs()` → `submitApi()` → `request()->validate()`**.

```php
$validation = request()->validate([
    'name'   => 'required|string|min_length:3|max_length:255|secure_value',
    'email'  => 'required|email|max_length:255|secure_value',
    'status' => 'required|integer|min:0|max_length:2',
    'id'     => 'numeric',
], [
    // Optional custom messages
    'name.required' => 'Name is mandatory',
]);

if (!$validation->passed()) {
    jsonResponse(['code' => 400, 'message' => $validation->getFirstError()]);
}
```

**Available rules:** `required`, `required_if`, `string`, `numeric`, `integer`, `boolean`, `email`, `url`, `ip`, `min_length:N`, `max_length:N`, `min:N`, `max:N`, `between:N,M`, `size:N`, `in:a,b,c`, `not_in:a,b`, `same:field`, `different:field`, `confirmed`, `alpha`, `alpha_num`, `alpha_dash`, `regex:pattern`, `date`, `date_format:fmt`, `before:date`, `after:date`, `date_equals:date`, `accepted`, `array`, `image`, `file`, `mimes:ext1,ext2`, `file_extension:ext1,ext2`, `max_file_size:KB`, `secure_value`, `xss`, `safe_html`, `no_sql_injection`, `secure_filename`, `base64`, `password`, `gt:field`, `gte:field`, `lt:field`, `lte:field`, `starts_with:val`, `ends_with:val`, `deep_array`, `array_keys:k1,k2`, `json`, `uuid`

### 5.3 File Uploads

```php
$files = request()->files('field_name');           // uploaded files
```

---

## 6. Authentication & Authorization

### 6.1 Session Management

Login stores session data via `startSession()`:

```php
startSession([
    'userID'       => $userData['id'],
    'userFullName' => $userData['name'],
    'userNickname' => $userData['user_preferred_name'],
    'userEmail'    => $userData['email'],
    'roleID'       => $profile['role_id'],
    'roleRank'     => $roles['role_rank'],
    'roleName'     => $roles['role_name'],
    'permissions'  => getPermissionSlug($permissions),  // array of slugs
    'userAvatar'   => $avatarPath,
    'isLoggedIn'   => true,
]);
```

### 6.2 Permission Checking

```php
// In PHP (controller/view)
permission('user-view')                  // returns true/false
permission(['user-view', 'user-edit'])   // returns ['user-view' => true, 'user-edit' => false]

// In view (conditional rendering)
<?php if (permission('user-create')) : ?>
    <button>Add New</button>
<?php endif; ?>

// Page-level enforcement (at top of view)
<?php if (requirePagePermission()) : ?>
    <!-- page content -->
<?php endif; ?>
```

### 6.3 RBAC Database Structure

```
users → user_profile (user_id) → master_roles (role_id)
                                    ↓
                              system_permission (role_id)
                                    ↓
                              system_abilities (abilities_id)
```

- A user has **multiple profiles** (`user_profile`), each linked to a role
- One profile is marked `is_main = 1` (active profile)
- Each role has **permissions** (`system_permission`) linking to **abilities** (`system_abilities`)
- Abilities have a unique `abilities_slug` used for permission checks
- Special slug `*` = all access (superadmin)

### 6.4 ID Encoding/Decoding

All IDs exposed to the frontend are **encoded** to prevent enumeration:

```php
$encoded = encodeID($id);    // used in frontend
$decoded = decodeID($encoded); // used in controller
```

---

## 7. View Layer

### 7.1 Template Structure

Every page view follows this pattern:

```php
<?php includeTemplate('header'); ?>

<?php if (requirePagePermission()) : ?>
    <div class="container-fluid flex-grow-1 container-p-y">
        <!-- Page content -->
    </div>
<?php endif; ?>

<?php includeTemplate('footer'); ?>
```

### 7.2 Theme

The project uses **Sneat Bootstrap Admin Template** (located in `public/sneat/`).

### 7.3 Modal & Offcanvas System

The framework provides **two approaches** for modals/offcanvas:

#### 7.3.1 Approach A: Pre-defined Templates + `loadFormContent()` (Current Pattern)

The header template (`_modalGeneral.php`) includes pre-built Bootstrap modals in sizes `xs`, `sm`, `lg`, `xl`, `fullscreen`, and one offcanvas panel (`generaloffcanvas-right`). Content is loaded dynamically via AJAX.

**`loadFormContent()` — Load a form into a modal or offcanvas:**

```javascript
// Signature
loadFormContent(fileName, idToLoad, sizeModal, urlFunc, title, dataArray, typeModal)

// Parameters:
// fileName     - PHP view file path (relative): 'views/directory/_userForm.php'
// idToLoad     - Content container prefix: 'userForm' (not used for ID matching, but for reset)
// sizeModal    - Modal: 'xs'|'sm'|'lg'|'xl'|'fullscreen'. Offcanvas: CSS width e.g. '550px'
// urlFunc      - Form action URL: 'controllers/UserController.php'
// title        - Modal/offcanvas title: 'Add User'
// dataArray    - Data to pre-fill form fields (object or null for new)
// typeModal    - 'modal' (default) or 'offcanvas'
```

**How it works internally:**
1. POSTs to `bootstrap.php` with `action: 'modal'` + the `fileName`
2. The `DynamicModalRequestMiddleware` intercepts and renders the PHP view file
3. Response HTML is appended into the modal/offcanvas content div
4. After 50ms delay, calls `getPassData(baseUrl, dataArray)` if defined in the loaded view
5. Auto-detects the `<form>` element inside, resets it, sets its `action` URL
6. If `dataArray` is provided, auto-fills form fields by matching `name` attributes
7. Sets `data-modal` attribute on form (used by `submitApi()` to close modal after save)

**Example — Adding a new user (offcanvas):**

```javascript
function addUser() {
    loadFormContent(
        'views/directory/_userForm.php',  // view file
        'userForm',                        // content ID prefix
        '550px',                           // offcanvas width
        'controllers/UserController.php',  // form action URL
        'Add User',                        // title
        {},                                // empty = new record
        'offcanvas'                        // type
    );
}
```

**Example — Editing an existing user (offcanvas with pre-filled data):**

```javascript
async function editRecord(id) {
    const res = await callApi('post', 'controllers/UserController.php', {
        'action': 'show',
        'id': id
    });
    if (isSuccess(res)) {
        loadFormContent(
            'views/directory/_userForm.php',
            'userForm',
            '550px',
            'controllers/UserController.php',
            'Update User',
            res.data.data,     // pre-fill with fetched data
            'offcanvas'
        );
    }
}
```

**`loadFileContent()` — Load read-only content into a modal (no form):**

```javascript
// Same signature but no urlFunc parameter, uses modal to display content only
loadFileContent(fileName, idToLoad, sizeModal, title, dataArray, typeModal)
```

#### 7.3.2 Approach B: `ModalManager` Class (Dynamic, Programmatic)

Located in `public/general/js/ModalManager.js`. Creates modals/offcanvas entirely from JavaScript — no pre-built HTML templates needed. Auto-cleans up DOM on close.

**Constructor defaults:**

```javascript
const modalMgr = new ModalManager();
```

**`showModal(options)` — Simple content modal:**

```javascript
const ctrl = modalMgr.showModal({
    size: 'lg',           // 'sm'|'md'|'lg'|'xl'|'fullscreen'
    title: 'My Title',
    content: '<p>HTML content</p>',
    centered: true,       // vertically centered
    scrollable: true,     // scrollable body
    staticBackdrop: true, // prevent close on outside click
    showHeader: true,
    showFooter: true,
    showClose: true,
    headerClass: '',
    bodyClass: '',
    footerClass: '',
    modalClass: '',
    footerButtons: [
        { text: 'Close', class: 'btn btn-secondary', dismiss: true },
        { text: 'Save', class: 'btn btn-primary', id: 'saveBtn', onclick: 'handleSave()' }
    ],
    onShow: (e) => {},    // Bootstrap show.bs.modal event
    onShown: (e) => {},   // shown.bs.modal
    onHide: (e) => {},    // hide.bs.modal
    onHidden: (e) => {},  // hidden.bs.modal
});
```

**`showModalApi(options)` — Modal with API-loaded content:**

```javascript
const ctrl = await modalMgr.showModalApi({
    title: 'User Details',
    size: 'lg',
    api: {
        url: '/api/users/1',
        method: 'GET',
        responseType: 'html',    // 'json'|'text'|'html'
        timeout: 30000,
        retries: 3,              // auto-retry with exponential backoff
        retryDelay: 1000,
        showLoader: true,        // spinner while loading
        loaderText: 'Loading...',
        showRefreshOnError: true, // show refresh button on error
        onSuccess: (data, ctrl) => { /* process data */ },
        onError: (error, ctrl) => { /* handle error */ },
    }
});

// Controller methods available:
ctrl.updateTitle('New Title');
ctrl.updateContent('<p>New HTML</p>');
ctrl.loadContent('/new-url');    // reload with different URL
ctrl.refresh();                   // reload same URL
ctrl.hide();
ctrl.show();
ctrl.dispose();
```

**`showOffcanvas(options)` / `showOffcanvasApi(options)` — Same pattern for offcanvas:**

```javascript
const ctrl = await modalMgr.showOffcanvasApi({
    position: 'end',     // 'start'|'end'|'top'|'bottom'
    title: 'Details',
    width: '500px',      // for start/end position
    height: 'auto',      // for top/bottom position
    backdrop: true,
    scroll: false,       // allow body scroll behind
    api: { url: '/api/data', responseType: 'html' }
});
```

**Utility methods:**

```javascript
modalMgr.getActiveModals();     // returns array of active controllers
modalMgr.getModal(modalId);     // get specific modal by ID
modalMgr.closeAllModals();      // close all modals
modalMgr.closeAllOffcanvas();   // close all offcanvas
modalMgr.closeAll();            // close everything
modalMgr.disposeAll();          // dispose and cleanup all
modalMgr.getModalCount();       // number of active modals
```

#### 7.3.3 Modal Form View Pattern (`_prefixed` files)

Form views loaded into modals/offcanvas follow this pattern:

```php
<!-- _myForm.php -->
<form id="myForm" method="POST" class="mt-0">
    <div class="row">
        <div class="col-12">
            <label class="form-label"> Field Name <span class="text-danger">*</span> </label>
            <input type="text" id="field_name" name="field_name" class="form-control" required>
        </div>
    </div>

    <div class="row mt-2">
        <div class="col-lg-12">
            <span class="text-danger">* Indicates a required field</span>
            <input type="hidden" id="id" name="id" placeholder="id">
        </div>
    </div>

    <input type="hidden" name="action" value="save" readonly>
    <button id="submitBtn" type="submit" class="btn btn-md btn-info mb-3"
        style="position: absolute;bottom: 0;">
        <i class='bx bx-save'></i> Save
    </button>
</form>

<script>
    // Called after form loads — receives base URL and dataArray from loadFormContent()
    async function getPassData(baseUrl, data) {
        // Load dropdowns, set conditional visibility, etc.
        if (empty(data)) {
            // New record logic
        } else {
            // Edit record — data already auto-filled by loadFormContent()
            // Handle nested/special fields here
        }
    }

    // Form submission handler
    $("#myForm").submit(function(event) {
        event.preventDefault();

        if (validateMyData(this)) {
            const form = $(this);
            Swal.fire({
                title: 'Are you sure?',
                html: "Form will be submitted!",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Yes, Confirm!',
                reverseButtons: true,
            }).then(async (result) => {
                if (result.isConfirmed) {
                    const res = await submitApi(
                        form.attr('action'),      // URL from loadFormContent
                        form.serializeArray(),     // form data
                        'myForm'                   // form ID (for modal close)
                    );
                    if (isSuccess(res.data.code ?? res)) {
                        noti(res.data.code ?? res.status, res.data.message);
                        getDataList(); // reload parent datatable
                    }
                }
            });
        } else {
            validationJsError('toastr', 'single');
        }
    });

    // Client-side validation (Laravel-style rules)
    function validateMyData(formObj) {
        const rules = {
            'field_name': 'required|min_length:3|max_length:255',
            'id': 'integer',
        };
        const message = {
            'field_name': { label: 'Field Name' },
        };
        return validationJs(formObj, rules, message);
    }
</script>
```

### 7.4 DataTable Pattern (Server-Side)

#### 7.4.1 `generateDatatableServer()` — Full Signature & Parameters

```javascript
generateDatatableServer(id, url = null, nodatadiv = 'nodatadiv', dataObj = null, filterColumn = [], screenLoadID = null)
```

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `id` | string | required | HTML table element ID (e.g., `'dataList'`) |
| `url` | string | `null` | Controller URL (e.g., `'controllers/UserController.php'`) |
| `nodatadiv` | string | `'nodatadiv'` | ID of "no data" placeholder div (e.g., `'nodataDiv'`) |
| `dataObj` | object\|null | `null` | POST data including `action` key and filter values |
| `filterColumn` | array | `[]` | DataTables `columnDefs` array — column config |
| `screenLoadID` | string\|null | `null` | ID of container to show loading overlay via `loading()` |

**Behavior:**
- Calls `DataTable().clear().destroy()` first (safe to call repeatedly)
- Uses POST with `X-Requested-With: XMLHttpRequest` header
- Server-side processing (`serverSide: true`)
- 10 rows per page, responsive, searchable
- On `initComplete`: shows `#${id}Div` if data exists, else shows `#${nodatadiv}` with no-data graphic
- Returns the DataTable instance for further manipulation

**Complete real-world example (User list with filters):**

```javascript
async function getDataList() {
    generateDatatableServer(
        'dataList',                               // table ID
        'controllers/UserController.php',          // endpoint
        'nodataDiv',                               // no-data div
        {
            'action': 'listUserDatatable',         // controller action
            'user_status_filter': $("#filter_user_status").val(),
            'user_gender_filter': $("#filter_gender_status").val(),
            'user_profile_filter': $("#filter_profile").val(),
        },
        [                                          // column definitions
            { "data": "avatar",  "width": "5%",  "targets": 0 },
            { "data": "name",                    "targets": 1 },
            { "data": "contact", "width": "35%", "targets": 2 },
            { "data": "gender",  "width": "8%",  "targets": 3 },
            { "data": "status",  "width": "7%",  "targets": 4 },
            {
                "data": "action",
                "targets": -1,
                "width": "3%",
                "searchable": false,
                "orderable": false,
                "render": function(data, type, row) { return data; }
            }
        ],
        'bodyDiv'                                  // loading overlay container
    );
}
```

**Required HTML structure:**

```html
<div id="bodyDiv" class="row">
    <div class="col-xl-12 mb-4">
        <div id="nodataDiv" style="display: none;"> <?= nodata() ?> </div>
        <div id="dataListDiv" class="table-responsive" style="display: block;">
            <table id="dataList" class="table table-hover table-striped table-bordered">
                <thead class="table-dark">
                    <tr>
                        <th> Column 1 </th>
                        <th> Column 2 </th>
                        <!-- ... -->
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
</div>
```

> **Convention:** The table wrapper div ID must be `{tableId}Div` (e.g., table `dataList` → wrapper `dataListDiv`).

#### 7.4.2 `generateDatatableClient()` — Client-Side Alternative

```javascript
const table = await generateDatatableClient(id, url = null, dataObj = null, filterColumn = [], nodatadiv = 'nodatadiv', screenLoadID = 'nodata')
```

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `id` | string | required | HTML table element ID |
| `url` | string | `null` | API endpoint URL |
| `dataObj` | object | `null` | POST data to send |
| `filterColumn` | array | `[]` | DataTables `columnDefs` configuration |
| `nodatadiv` | string | `'nodatadiv'` | ID of "no data" placeholder div |
| `screenLoadID` | string | `'nodata'` | ID of element for loading spinner |

> **Note:** Parameter order differs from `generateDatatableServer()` — `dataObj` is 3rd (not 4th) and `nodatadiv` is 5th (not 3rd).

Fetches all data upfront via `callApi()`, then renders with client-side DataTable (`serverSide: false`). Useful for small datasets or when you need full client-side search. Returns the DataTable instance (or `undefined` if no data).

#### 7.4.3 Server-Side Controller Pattern

See Section 11.3 for the controller template. The controller must return DataTables-compatible JSON using `->paginate_ajax(request()->all())`.

### 7.5 Frontend Helper Functions Reference

Located in `public/general/js/helper.js` (3342 lines). Key functions by category:

#### 7.5.1 API Functions

| Function | Signature | Description |
|----------|-----------|-------------|
| `callApi` | `(method = 'POST', url, dataObj = null, option = {})` | Generic AJAX call via Axios. Returns promise. Auto-handles errors with `noti()`. |
| `submitApi` | `(url, dataObj, formID = null, reloadFunction = null, closedModal = true)` | Form submission. Serializes form via `FormData`, auto-attaches CSRF token, shows loading on submit button, closes modal on success. |
| `deleteApi` | `(id, url, reloadFunction = null)` | DELETE request via `DELETE` method to `url/id`. Auto-notifies and calls reload function on success. |
| `uploadApi` | `(url, formID = null, idProgressBar = null, reloadFunction = null, permissions = null)` | Multipart upload with progress bar. Shows color-coded progress (red→yellow→blue→green) with ETA. |
| `loginApi` | `(url, formID = null)` | Specialized login form submission. Manages login button loading state. |

**`callApi()` details:**

```javascript
const callApi = async (method = 'POST', url, dataObj = null, option = {}) => { ... }

// - Prepends base_url via urls() 
// - Content-Type: application/x-www-form-urlencoded
// - Always sends X-Requested-With: XMLHttpRequest
// - For POST/PUT: wraps data in URLSearchParams
// - Auto-shows noti() on error (400, 404, 422, 429, 500)
// - Returns Axios response object

// Usage:
const res = await callApi('post', 'controllers/RoleController.php', {
    'action': 'listSelectOptionRole'
});
if (isSuccess(res)) {
    const data = res.data.data;
    // process data...
}
```

**`submitApi()` details:**

```javascript
const submitApi = async (url, dataObj, formID = null, reloadFunction = null, closedModal = true) => { ... }

// Flow:
// 1. Finds submit button in form (button[type=submit] or input[type=submit])
// 2. Shows loading spinner on button via loadingBtn()
// 3. Creates FormData from form element
// 4. Appends CSRF token from <meta name="secure_token"> if present
// 5. POSTs with XMLHttpRequest header
// 6. On success: calls reloadFunction, closes modal/offcanvas (reads data-modal attr)
// 7. On error: shows noti() with error message
// 8. Always restores button state

// Usage (inside form submit handler):
const res = await submitApi(form.attr('action'), form.serializeArray(), 'myForm');
```

**`uploadApi()` details:**

```javascript
const uploadApi = async (url, formID = null, idProgressBar = null, reloadFunction = null, permissions = null) => { ... }

// - Uses multipart/form-data content type
// - Sends X-Requested-With: XMLHttpRequest header
// - Sends X-Permission header (value of `permissions` param)
// - Shows animated progress bar with color transitions:
//   0-40%: red (bg-danger), 40-60%: yellow (bg-warning), 60-99%: blue (bg-info), 100%: green (bg-success)
// - Displays bytes uploaded, total size, percentage, and estimated time remaining
// - Auto-cleans progress bar after 500ms on completion
// - On success: calls reloadFunction() if provided
// - On error: shows noti() with error message
// - Returns Axios response object

// Usage:
const res = await uploadApi(
    'controllers/UploadController.php',
    'uploadForm',
    'progressBarDiv',
    () => getDataList(),   // reload callback
    'upload-create'        // permission slug
);
```

#### 7.5.2 Response Check Functions

```javascript
const isSuccess = (res) => {
    // Returns true for status: 200, 201, 302
    const status = typeof res === 'number' ? res : res.status;
    return [200, 201, 302].includes(status);
}

const isError = (res) => {
    // Returns true for status: 400, 404, 422, 429, 500
    return [400, 404, 422, 429, 500].includes(status);
}

const isUnauthorized = (res) => {
    // Returns true for status: 401, 403
    return [401, 403].includes(status);
}
```

#### 7.5.3 UI Helper Functions

```javascript
// Button loading state
loadingBtn(id, display = false, text = "<i class='ti ti-device-floppy ti-xs mb-1'></i> Save")
// display=true:  shows "Please wait..." + spinner, disables button
// display=false: restores original text (from `text` param), enables button

// Button disable/enable
disableBtn(id, display = true, text = null)
// display=true:  disables button. display=false: enables button

// Container loading overlay (uses BlockUI)
loading(id = null, display = false)
// display=true:  blocks element with "Please wait..." wave animation
// display=false: unblocks after 80ms delay

// Modal shortcuts
showModal(id, timeSet = 0)       // $(id).modal('show') with optional delay
closeModal(id, timeSet = 250)    // $(id).modal('hide') with 250ms default delay
closeOffcanvas(id, timeSet = 250) // $(id).offcanvas('toggle') with 250ms delay
```

#### 7.5.4 Data Utility Functions

```javascript
// Check if variable is defined and not null
const isset = (variable) => typeof variable != 'undefined' && variable != null;

// Check if variable is defined
const isDef = (value) => typeof value !== undefined && value !== null;

// Check if variable is undefined
const isUndef = (value) => typeof value === undefined || value === null;

// Check if variable is empty (versatile - handles string, array, object, null, undefined)
const empty = (variable) => { ... }

// Versatile data existence checker (most used helper)
const hasData = (data = null, arrKey = null, returnData = false, defaultValue = null) => { ... }
// - hasData(myVar)                        → boolean: does it exist?
// - hasData(obj, 'user.profile.name')     → boolean: nested key exists?
// - hasData(obj, 'user.name', true)       → returns the value or null
// - hasData(obj, 'user.name', true, 'N/A') → returns value or 'N/A'
// Supports dot notation AND bracket notation: 'users[0].name'

// Type checking helpers
const isTrue = (value) => { ... }      // checks truthiness
const isFalse = (value) => { ... }     // checks falsiness
const isObject = (obj) => { ... }      // checks if plain object
const isArray = (val) => { ... }       // checks if array
const isPromise = (val) => { ... }     // checks if Promise
const isNumeric = (evt) => { ... }     // checks if numeric input
const isMobileJs = () => { ... }       // detects mobile browser

// URL helpers
const base_url = () => $('meta[name="base_url"]').attr('content');
const urls = (path) => new URL(path, base_url()).href;
const asset = (path, isPublic = true) => urls((isPublic ? 'public/' : '') + path);
const redirect = (url) => window.location.replace(base_url() + url);
const refreshPage = () => location.reload();

// String helpers
ucfirst(string)      // Capitalize first letter
capitalize(str)      // Capitalize each word
uppercase(obj)       // Convert to uppercase (for input elements)
trimData(text = null) // Trim whitespace from text

// Number/formatting
sizeToText(size, decimal = 2)                         // Bytes → "1.50 MB"
formatCurrency(value, code = null, includeSymbol = false) // 1000 → "RM 1,000.00"
currencySymbol(currencyCode = null)                   // 'MYR' → 'RM'

// Array utilities (PHP-style)
in_array(needle, data)              // Check if value exists in array
array_push(data, ...elements)       // Push elements to array
array_merge(...arrays)              // Merge multiple arrays
array_key_exists(arrKey, data)      // Check if key exists in object/array
array_search(needle, haystack)      // Search for value in array
implode(separator = ',', data)      // Join array into string
explode(delimiter = ',', data)      // Split string into array
remove_item_array(data, item)       // Remove item from array
chunkData(dataArr, perChunk)        // Split array into chunks
```

#### 7.5.5 Skeleton Loaders

```javascript
// Table skeleton with card wrapper (used while DataTable loads)
skeletonTableCard(hasFilter = null, buttonRefresh = true)
// hasFilter: number of filter dropdowns to show as skeleton
// buttonRefresh: show refresh button skeleton

// Table skeleton without card wrapper
skeletonTable(hasFilter = null, buttonRefresh = true)

// Table skeleton only (no filters)
skeletonTableOnly(totalData = 3)

// General no-data display
nodata(text = true, filesName = '4.png')  // Returns "no data" illustration HTML
// text: show/hide text description. filesName: image file from nodata/ folder

// No selection display (for master-detail views)
noSelectDataLeft(text = 'Type', filesName = '5.png')

// No access/forbidden display
nodataAccess(filesName = '403.png')
```

#### 7.5.6 Print & Export Helpers

```javascript
// Print helper — fetches HTML via API, renders to printable div
printHelper(method = 'get', url, filter = null, config = null)
// config: { id: 'printBtn', text: '<i>Print</i>', header: 'LIST' }

// Excel export — downloads via generated path
exportExcelHelper(method = 'get', url, filter = null, config = null)
// config: { id: 'exportBtn', text: '<i>Export</i>' }
```

#### 7.5.7 File Preview Helpers

```javascript
// Simple PDF/image preview
previewPDF(fileLoc, fileMime, divToLoadID, modalId = null)

// Advanced file preview with retry, fullscreen, download, rotation
previewFiles(fileLoc, fileMime, options = {})
// options: { display_id, modal_id, height, width, retry, maxFileSize, timeout, ... }
```

#### 7.5.8 Debug Functions

```javascript
// Console log wrapper (only logs if debug mode enabled)
log(...args)

// Dump and die — logs to console and throws error
dd(...args)
```

#### 7.5.9 Date/Time Functions

```javascript
// Get current time
getCurrentTime(use12HourFormat = false, hideSeconds = false)
// Returns formatted time string: "14:30:00" or "2:30 PM"

// Get current date
getCurrentDate()  // Returns "YYYY-MM-DD"

// Get current timestamp
getCurrentTimestamp()  // Returns "YYYY-MM-DD HH:MM:SS"

// Live clock display
getClock(format = '24', lang = 'en', showSeconds = true)
// Returns formatted clock string

showClock(id, customize = null)
// Renders live updating clock into element by ID

// Date formatting
date(formatted = null, timestamp = null)
// PHP-style date() function. Supports format tokens: Y, m, d, H, i, s, etc.

formatDate(dateToFormat, format = 'd.m.Y', defaultValue = null)
// Formats a date string/object into specified format

// Day checks
isWeekend(date = new Date(), weekendDays = ['SUN', 'SAT'])
isWeekday(date = new Date(), weekendDays = ['SUN', 'SAT'])

// Date calculations
calculateDays(date1, date2, exception = [])
// Calculates number of days between two dates, optionally excluding specific days

getDatesByDay(startDate, endDate, dayOfWeek)
// Returns array of all dates matching a specific day of week within range

getDayIndex(dayOfWeek)
// Converts day name (e.g., 'MON') to numeric index (0-6)
```

#### 7.5.10 Notification Function

```javascript
// Toast notification (uses toastr library)
noti(code = 400, text = 'Something went wrong')
// - Auto-detects success/error based on HTTP status code
// - Success (200, 201, 302): green toast with "{text} successfully"
// - Error (400, 404, 422, 429, 500): red toast with error message
// - Unauthorized (401, 403): red toast with "Unauthorized: Access is denied"
// - Mobile-responsive positioning (bottom-full-width on mobile, top-right on desktop)

// Simple toast notification
showToast(message, type = 'info')
// type: 'info' | 'success' | 'warning' | 'error'
```

### 7.6 Client-Side Validation (`validation.js`)

Located in `public/general/js/validation.js` (2345 lines). Provides **Laravel-style validation rules** for client-side form validation before submission.

#### 7.6.1 Basic Usage

```javascript
function validateMyData(formObj) {
    const rules = {
        'name': 'required|min_length:3|max_length:255',
        'email': 'required|email|max_length:255',
        'contact_no': 'required|integer|min_length:10|max_length:15',
        'role_id': 'required|integer|min:1',
        'gender': 'required|integer|in:1,2',
        'status': 'required|integer|min:0|in:0,1,2',
        'username': 'required_if:id,empty|min_length:3|max_length:15',
        'password': 'required_if:id,empty|min_length:8',
        'id': 'integer',
    };

    const message = {
        'name': { label: 'Full Name' },
        'gender': {
            label: 'Gender',
            in: 'The :label should be either Male or Female.',
        },
        'username': {
            required_if: 'The :label field is required.',
        },
    };

    return validationJs(formObj, rules, message);
    // Full signature: validationJs(formElement, rules, messages = {}, attributeType = 'name')
    // attributeType: 'name' (match by name attr) or 'id' (match by id attr)
}

// In form submit handler:
if (validateMyData(this)) {
    // proceed with submitApi...
} else {
    validationJsError('toastr', 'single'); // show first error as toast
}
```

#### 7.6.2 Available Validation Rules

| Rule | Example | Description |
|------|---------|-------------|
| `required` | `'required'` | Field must not be empty |
| `required_if` | `'required_if:field,operator,value'` | Required when another field matches |
| `required_with` | `'required_with:other_field'` | Required when another field is present |
| `required_unless` | `'required_unless:field,value'` | Required unless another field matches |
| `string` | `'string'` | Must be a string |
| `numeric` / `double` / `float` | `'numeric'` | Must be a number |
| `integer` | `'integer'` | Must be an integer |
| `email` | `'email'` | Must be valid email |
| `url` | `'url'` | Must be valid URL |
| `boolean` | `'boolean'` | Must be boolean-like |
| `min` | `'min:1'` | Minimum numeric value |
| `max` | `'max:100'` | Maximum numeric value |
| `min_length` | `'min_length:3'` | Minimum string length |
| `max_length` | `'max_length:255'` | Maximum string length |
| `between` | `'between:1,10'` | Numeric value between range |
| `in` | `'in:1,2,3'` | Value must be in list |
| `not_in` | `'not_in:0,99'` | Value must not be in list |
| `confirmed` | `'confirmed'` | Must match `{field}_confirmation` |
| `same` | `'same:other_field'` | Must match another field |
| `different` | `'different:other'` | Must differ from another field |
| `date` | `'date'` | Must be valid date |
| `date_format` | `'date_format:Y-m-d'` | Must match date format |
| `after` / `before` | `'after:2024-01-01'` | Date comparison |
| `after_or_equal` | `'after_or_equal:start_date'` | Compare with other field |
| `regex` | `'regex:^[A-Z]+$'` | Must match regex pattern |
| `alpha` | `'alpha'` | Only letters |
| `alpha_num` | `'alpha_num'` | Letters and numbers |
| `alpha_dash` | `'alpha_dash'` | Letters, numbers, dashes, underscores |
| `file` | `'file'` | Must be a file input |
| `image` | `'image'` | Must be an image file |
| `mimes` | `'mimes:jpg,png'` | Allowed file extensions |
| `size` | `'size:8'` | Max file size in MB |
| `json` | `'json'` | Must be valid JSON |
| `ip` / `ipv4` / `ipv6` | `'ipv4'` | Must be valid IP address |
| `uuid` | `'uuid'` | Must be valid UUID |
| `currency` | `'currency:MYR'` | Must be valid currency format |
| `nullable` | `'nullable'` | Allow null/empty |
| `accepted` | `'accepted'` | Must be "yes", "on", 1, true |
| `contains` | `'contains:word'` | Must contain substring |
| `gt` / `lt` / `lte` | `'gt:other_field'` | Greater/less than another field |
| `digits` | `'digits:4'` | Must be exactly N digits |
| `digits_between` | `'digits_between:4,6'` | Digit count within range |
| `lowercase` | `'lowercase'` | Must be all lowercase |
| `uppercase` | `'uppercase'` | Must be all uppercase |
| `decimal` | `'decimal:2'` | Must be decimal with precision |
| `time` | `'time'` | Must be valid time format |
| `weekend` | `'weekend'` | Date must fall on weekend |
| `sometimes` | `'sometimes'` | Only validate if field is present |
| `doesnt_contain` | `'doesnt_contain:word'` | Must not contain substring |
| `before_or_equal` | `'before_or_equal:end_date'` | Compare with other field/date |
| `dimensions` | `'dimensions:min_width=100'` | Image dimensions check |

#### 7.6.3 Error Display

```javascript
// Get raw errors object
const errors = validationJsError('raw');
// Returns: { field_name: 'Error message', ... }

// Show as toast notification (recommended)
validationJsError('toastr', 'single');  // shows first error only
validationJsError('toastr', 'multi');   // shows all errors
```

#### 7.6.4 Array Field Validation

```javascript
// Validates each element with [] suffix
const rules = {
    'items[]': 'required|string|max_length:100',
};
// Adds Bootstrap 'is-invalid' class to failing elements
```

---

## 8. File Upload System

### 8.1 Entity Files Table

All uploads are stored in `entity_files` with polymorphic linking:

| Column | Type | Purpose |
|--------|------|---------|
| `entity_type` | varchar(255) | Table/model name (e.g., `users`, `deliveries`) |
| `entity_id` | bigint | Record ID in that table |
| `entity_file_type` | varchar(255) | Category (e.g., `USER_PROFILE_PHOTO`, `DELIVERY_PHOTO`) |
| `user_id` | bigint | Uploader user ID |
| `files_name` | varchar(255) | Stored filename |
| `files_original_name` | varchar(255) | Original uploaded filename |
| `files_type` | varchar(50) | File type category |
| `files_mime` | varchar(50) | MIME type (e.g., `image/jpeg`) |
| `files_extension` | varchar(10) | File extension (e.g., `jpg`) |
| `files_size` | int | File size in bytes |
| `files_path` | varchar(255) | Stored file path on disk |
| `files_compression` | tinyint(1) | Whether compression is applied |
| `files_folder` | varchar(255) | Folder group for organization |
| `files_disk_storage` | varchar(20) | Storage disk identifier |
| `files_path_is_url` | tinyint(1) | Whether path is external URL |
| `files_description` | text | Optional file description |

> **Note:** This table does NOT have a `deleted_at` column — deletes are hard deletes.

### 8.2 Upload Flow (Image Cropper)

**Architecture:** Frontend (Croppie.js) → Base64 → `UploadController.php` → Disk + DB

**Step 1: Open Upload Modal**

Load the cropper view into offcanvas via `loadFormContent()`:

```javascript
function uploadPhoto(userId, existingFileId) {
    loadFormContent(
        'views/_templates/_uploadImageCropperModal.php',
        'changePictureUpload',
        '550px',
        'controllers/UploadController.php',
        'Upload Photo',
        {
            id: existingFileId,                // entity_files.id (for update) or empty
            entity_id: encodeID(userId),       // encoded parent record ID
            entity_type: 'users',              // table name
            entity_file_type: 'USER_PROFILE',  // file category
            folder_group: 'users',             // storage folder group
            folder_type: 'profile',            // storage subfolder
            url: 'controllers/UploadController.php',
            imagePath: existingImagePath,       // for edit — pre-loads existing image
            cropperConfig: {                   // optional Croppie configuration
                viewportWidth: 250,
                viewportHeight: 250,
                boundaryWidth: 350,
                boundaryHeight: 350,
                type: 'square'                 // 'square' or 'circle'
            }
        },
        'offcanvas'
    );
}
```

**Step 2: Cropper View Internals**

The `_uploadImageCropperModal.php` view:
1. Uses **Croppie.js** library for image cropping with rotate controls
2. Shows square + circle previews simultaneously
3. Validates file: `required|file|image|size:8|mimes:jpg,jpeg,png`
4. On file select: initializes cropper, enables upload button
5. On submit: extracts base64 from croppie, stores in hidden `#image64` input
6. Calls `uploadApi()` with progress bar
7. On success: closes offcanvas, shows success notification

**Step 3: Server-Side Processing (`uploadImageCropper`)**

```php
function uploadImageCropper($request) {
    // 1. Validate: entity_type, entity_file_type, entity_id (all with secure_value)
    // 2. Decode entity_id (it's encoded)
    // 3. Convert base64 string → binary image data
    // 4. Generate folder: folder($folder_group, $entity_id, $folder_type)
    // 5. Generate filename: {entity_id}_{date}_{time}.{ext}
    // 6. Create directory if needed (0755 permissions)
    // 7. Save file via file_put_contents()
    // 8. Process with moveFile() → returns metadata array
    // 9. INSERT or UPDATE entity_files record
    // 10. Remove old file from disk if updating
    // 11. Return: { code: 200, message: '...', data: fileRecord }
}
```

**Step 4: Delete Uploaded Files**

```javascript
// Frontend call
const res = await callApi('post', 'controllers/UploadController.php', {
    'action': 'removeUploadFiles',
    'id': encodeID(fileId)
});
```

```php
// Server: removeUploadFiles()
// 1. Decodes ID, fetches entity_files record
// 2. Deletes DB record
// 3. Unlinks physical file via unlinkOldFiles()
```

### 8.3 File Path Helpers

```php
// PHP helpers
$avatar = getFilesCompression($fileRecord);  // returns compressed path or original
$url = asset($path);                          // returns full URL

// Folder generation
$folder = folder($group, $entityId, $type);  // e.g., 'upload/users/123/profile'

// Base64 conversion
$result = convertBase64String($base64String);
// Returns: ['status' => true, 'data' => binaryData, 'extension' => 'jpeg']

// File cleanup
unlinkOldFiles($fileRecord);  // deletes physical file from disk
```

```javascript
// JavaScript helpers
const imageUrl = asset(filePath, false);      // false = not in public/ folder
const defaultImg = getImageDefault('noimage.png'); // default placeholder
```

### 8.4 Notification System (`noti()`)

See Section 7.5.10 for the full `noti()` and `showToast()` documentation. Quick reference:

```javascript
noti(code, text)
// code: HTTP status code (number) — MUST be numeric, not a string
// text: message text

// Examples:
noti(200, 'Record saved');     // → "Great! Record saved successfully"
noti(400, 'Invalid input');    // → "Ops! Invalid input"
noti(422);                     // → "Ops! Something went wrong"
```

---

## 9. Cron Jobs (Scheduled Tasks)

All cron/scheduled task scripts are placed in the `app/cron/` folder. Each script is a standalone PHP file that bootstraps the application and executes its logic independently — no web server or AJAX middleware involved.

### 9.1 File Location & Bootstrap

Every cron script **must** include `bootstrap.php` to access the database, helpers, config, and all framework utilities:

```php
<?php
// app/cron/my_task.php

// Bootstrap application — gives access to db(), helpers, config, etc.
require_once dirname(__DIR__, 2) . '/bootstrap.php';
```

> **⚠️ IMPORTANT:** The path `dirname(__DIR__, 2)` navigates from `app/cron/` up two levels to the project root where `bootstrap.php` lives. This must be the first executable line in every cron script.

After bootstrapping, you have full access to:
- `db()` — Database query builder (all methods from Section 4)
- `timestamp()` — Current timestamp helper
- `logError()` — Error logging
- All helper functions from `app/helpers/`
- All config values from `app/config/`

### 9.2 Standard Cron Script Structure

```php
<?php

/**
 * Task Name — Cron Job
 * 
 * Schedule: [frequency, e.g., Daily at 02:00 AM, Every 5 minutes, Hourly]
 * Command:  php /path/to/app/cron/task_name.php
 * 
 * [Brief description of what this cron does]
 * 
 * File: app/cron/task_name.php
 */

// Bootstrap application
require_once dirname(__DIR__, 2) . '/bootstrap.php';

// Result tracker — returned as JSON at the end
$result = [
    'status'    => 'ok',
    'processed' => 0,
    'timestamp' => date('Y-m-d H:i:s'),
];

try {

    // ── Main logic ──
    $records = db()->table('table_name')
        ->where('status', 'pending')
        ->whereNull('deleted_at')
        ->get();

    foreach ($records as $record) {
        db()->table('table_name')
            ->where('id', $record['id'])
            ->update([
                'status'     => 'processed',
                'updated_at' => timestamp(),
            ]);

        $result['processed']++;
    }

    $result['status'] = 'completed';
} catch (\Exception $e) {
    $result['status'] = 'error';
    $result['message'] = $e->getMessage();
}

header('Content-Type: application/json');
echo json_encode($result);
```

### 9.3 Naming Convention

Use descriptive, snake_case filenames that indicate the task purpose:

```
app/cron/
  queue_processor.php         ← Processes a message/job queue
  record_auto_close.php       ← Auto-closes records after a grace period
  overdue_check.php           ← Flags overdue items
  file_cleanup.php            ← Purges expired files from disk
  token_expiry_cleanup.php    ← Expires stale tokens
  expiry_notification.php     ← Sends warning notifications before expiry
```

**Typical schedule patterns:**

| Frequency | Use For |
|-----------|--------|
| Every 2–5 minutes | Queue processors, real-time notifications |
| Hourly | Token/session cleanup, cache invalidation |
| Daily (off-peak) | Status transitions, auto-close, overdue checks, expiry warnings |
| Weekly | File cleanup, report generation, data archival |

### 9.4 Conventions & Rules

1. **One file per task** — Each cron job is a single PHP file in `app/cron/`. Do not combine multiple tasks into one file.
2. **Always bootstrap** — Every cron file must `require_once dirname(__DIR__, 2) . '/bootstrap.php';` as its first executable line.
3. **JSON output** — Always output a JSON result object with at least `status` and `timestamp` fields. This enables monitoring and log parsing.
4. **Config-driven** — Use `getSystemConfig()` for thresholds, batch sizes, retention periods, and feature toggles. Allow tasks to be disabled via config without removing the cron entry.
5. **Audit trail** — Use `auditLog()` for every state-changing operation so changes are traceable.
6. **Error handling** — Wrap all logic in `try/catch`. Log errors via `logError('cron', ...)` and set `$result['status'] = 'error'`.
7. **Idempotent** — Cron scripts should be safe to run multiple times. Use status checks and date comparisons to avoid double-processing.
8. **No session/auth** — Cron scripts run outside the web context. There is no logged-in user, no session, and no permission checks. Use `null` for `created_by`/`updated_by` fields or a system-level marker.
9. **Batch processing** — For large datasets, use `->limit($batchSize)` or `->chunk()` to avoid memory issues (see Section 4.9).

### 9.5 Server Crontab Setup

```bash
# ┌───────────── minute (0-59)
# │ ┌───────────── hour (0-23)
# │ │ ┌───────────── day of month (1-31)
# │ │ │ ┌───────────── month (1-12)
# │ │ │ │ ┌───────────── day of week (0-7, 0=Sun)
# │ │ │ │ │
# │ │ │ │ │

# Every 5 minutes — queue processor
*/5 * * * * php /var/www/project/app/cron/queue_processor.php >> /var/log/cron/queue.log 2>&1

# Every hour — token cleanup
0 * * * * php /var/www/project/app/cron/token_expiry_cleanup.php >> /var/log/cron/token.log 2>&1

# Daily at 2:00 AM — expiry check
0 2 * * * php /var/www/project/app/cron/expiry_check.php >> /var/log/cron/expiry.log 2>&1

# Daily at 7:00 AM — auto-close
0 7 * * * php /var/www/project/app/cron/record_auto_close.php >> /var/log/cron/autoclose.log 2>&1

# Weekly Sunday 3:00 AM — file cleanup
0 3 * * 0 php /var/www/project/app/cron/file_cleanup.php >> /var/log/cron/cleanup.log 2>&1
```

> **Tip:** Always redirect output to a log file (`>> /var/log/cron/xxx.log 2>&1`) for debugging. The JSON output from each script will be appended to the log.

---

## 10. Security Features

| Feature | Config Key | Description |
|---------|-----------|-------------|
| XSS Protection | `security.xss_request` | Middleware checks all input for XSS |
| CSRF Protection | `security.csrf` | Token-based, per-controller/action |
| Rate Limiting | `security.throttle_request` | Request throttling |
| Permission Check | `security.permission_request` | Middleware-level permission enforcement |
| `safeOutput()` | Query builder method | HTML-encodes output to prevent stored XSS — **MANDATORY for all browser-rendered data** (see Section 4.8). Skip for internal logic and exports for performance. |
| `secure_value` | Validation rule | Strips dangerous input patterns |

> **⛔ Security rules that MUST be followed in every module:**
>
> 1. **`safeOutput()`** — Use on every query whose results are rendered in HTML (datatables, detail views, modals). See Section 4.8 for when to skip.
> 2. **Dual validation** — Every form must validate on front-end (`validationJs()`) AND back-end (`request()->validate()`). See Section 5.2.
> 3. **`chunk()` / `lazy()` for exports** — Never use `get()` for data exports. See Section 4.9.
> 4. **`when()` for conditional queries** — Use `when()` instead of if/else around query builders. See Section 4.7.
> 5. **DO NOT use API routes** — The API system is broken. Use Controller + AJAX pattern only. See Section 2.3.

---

## 11. Reusable Patterns for New Modules

### 11.1 New Module Checklist

1. **Database:** Create table(s) with `id, ..., created_at, updated_at, deleted_at`
2. **Controller:** Create `controllers/XxxController.php` with standard CRUD functions
3. **View:** Create `app/views/module_name/page.php` + `_form.php` (modal)
4. **Menu:** Register in `$menuList` in `bootstrap.php`
5. **Permissions:** Add abilities to `system_abilities`, assign to roles via `system_permission`
6. **Scopes:** Add module-specific scopes if needed in `ScopeMacroQuery/Scope.php`
7. **Cron (if needed):** Create `app/cron/module_task.php` with bootstrap + standard structure (Section 9)

### 11.2 Standard Table Schema Pattern

```sql
CREATE TABLE `table_name` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  -- business columns --
  `status` tinyint DEFAULT 1,
  `created_by` bigint DEFAULT NULL,
  `updated_by` bigint DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

### 11.3 Controller Template

```php
<?php
require_once '../bootstrap.php';

function listXxxDatatable($request) {
    $db = db();
    $result = $db->table('xxx')
        ->whereNull('deleted_at')
        ->setPaginateFilterColumn(['col1', 'col2'])
        ->safeOutput()
        ->paginate_ajax(request()->all());
    
    $result['data'] = array_map(function ($row) {
        $id = encodeID($row['id']);
        return [
            'col1' => $row['col1'],
            'action' => "..." // edit/delete buttons
        ];
    }, $result['data']);
    
    jsonResponse($result);
}

function show($request) {
    $id = decodeID(request()->input('id'));
    if (empty($id)) jsonResponse(['code' => 400, 'message' => 'ID is required']);
    
    $data = db()->table('xxx')->where('id', $id)->safeOutput()->fetch();
    if (!$data) jsonResponse(['code' => 404, 'message' => 'Not found']);
    
    jsonResponse(['code' => 200, 'data' => $data]);
}

function save($request) {
    $validation = request()->validate([...]);
    if (!$validation->passed()) {
        jsonResponse(['code' => 400, 'message' => $validation->getFirstError()]);
    }
    
    $result = db()->table('xxx')->insertOrUpdate(
        ['id' => request()->input('id')],
        request()->all()
    );
    
    if (isError($result['code'])) {
        jsonResponse(['code' => 422, 'message' => 'Failed to save']);
    }
    jsonResponse(['code' => 200, 'message' => 'Saved successfully']);
}

function destroy($request) {
    $id = decodeID(request()->input('id'));
    if (empty($id)) jsonResponse(['code' => 400, 'message' => 'ID is required']);
    
    $result = db()->table('xxx')->where('id', $id)->softDelete([
        'deleted_at' => timestamp()
    ]);
    
    if (isError($result['code'])) {
        jsonResponse(['code' => 422, 'message' => 'Failed to delete']);
    }
    jsonResponse(['code' => 200, 'message' => 'Deleted successfully']);
}
```

### 11.4 View Template

```php
<?php includeTemplate('header'); ?>
<?php if (requirePagePermission()) : ?>
<div class="container-fluid flex-grow-1 container-p-y">
    <h4 class="fw-bold py-3 mb-4"><?= showPageTitle() ?></h4>
    <div class="col-lg-12 order-2 mb-4">
        <div class="card h-100">
            <div class="card-body">
                <!-- Filters + buttons -->
                <!-- DataTable -->
            </div>
        </div>
    </div>
</div>
<script>
$(document).ready(async function() { await getDataList(); });

async function getDataList() {
    generateDatatableServer('dataList', 'controllers/XxxController.php', 'nodataDiv', {
        'action': 'listXxxDatatable'
    }, [/* column defs */]);
}
</script>
<?php endif; ?>
<?php includeTemplate('footer'); ?>
```

### 11.5 Modal Form Template

See Section 7.3.3 for the full modal form pattern with `getPassData()`, `submitApi()`, and `validationJs()`. Below is the simplified structure:

```php
<!-- _xxxForm.php -->
<form id="xxxForm" method="POST" class="mt-0">
    <div class="row">
        <div class="col-12">
            <label class="form-label"> Field Name <span class="text-danger">*</span> </label>
            <input type="text" id="field_name" name="field_name" class="form-control" required>
        </div>
    </div>

    <div class="row mt-2">
        <div class="col-lg-12">
            <span class="text-danger">* Indicates a required field</span>
            <input type="hidden" id="id" name="id" placeholder="id">
        </div>
    </div>

    <input type="hidden" name="action" value="save" readonly>
    <button id="submitBtn" type="submit" class="btn btn-md btn-info mb-3"
        style="position: absolute;bottom: 0;">
        <i class='bx bx-save'></i> Save
    </button>
</form>

<script>
    async function getPassData(baseUrl, data) {
        // Load dropdowns or additional data here
        if (empty(data)) {
            // New record
        } else {
            // Edit — fields auto-filled by loadFormContent()
        }
    }

    $("#xxxForm").submit(function(event) {
        event.preventDefault();
        if (validateXxxData(this)) {
            const form = $(this);
            Swal.fire({
                title: 'Are you sure?',
                html: "Form will be submitted!",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Yes, Confirm!',
                reverseButtons: true,
            }).then(async (result) => {
                if (result.isConfirmed) {
                    const res = await submitApi(
                        form.attr('action'),
                        form.serializeArray(),
                        'xxxForm'
                    );
                    if (isSuccess(res.data.code ?? res)) {
                        noti(res.data.code ?? res.status, res.data.message);
                        getDataList();
                    }
                }
            });
        } else {
            validationJsError('toastr', 'single');
        }
    });

    function validateXxxData(formObj) {
        const rules = { 'field_name': 'required|min_length:3|max_length:255' };
        const message = { 'field_name': { label: 'Field Name' } };
        return validationJs(formObj, rules, message);
    }
</script>
```

---

## 12. Key Helper Functions Reference

| Function | File | Purpose |
|----------|------|---------|
| `currentUserID()` | custom_project_helper | Get logged-in user ID |
| `currentRoleID()` | custom_project_helper | Get current role ID |
| `currentRank()` | custom_project_helper | Get role rank |
| `permission($slug)` | custom_project_helper | Check permission |
| `requirePagePermission()` | custom_project_helper | Enforce page permission |
| `isLogin()` | custom_project_helper | Check login status |
| `encodeID($id)` | custom_general_helper | Encode ID for frontend |
| `decodeID($encoded)` | custom_general_helper | Decode frontend ID |
| `jsonResponse($data)` | custom_api_helper | Send JSON response |
| `timestamp()` | custom_date_time_helper | Current datetime |
| `hasData($data, $arrKey, $returnData, $defaultValue)` | custom_debug_helper | Versatile data/key existence checker |
| `isError($code)` | custom_api_helper | Check if HTTP code is error |
| `asset($path)` | custom_general_helper | Generate asset URL |
| `redirect($url)` | custom_general_helper | HTTP redirect |
| `render($file, $params)` | custom_project_helper | Include view file |
| `startSession($data)` | custom_session_helper | Set session values |
| `getSession($key)` | custom_session_helper | Get session value |
| `nodata()` | custom_general_helper | "No data" placeholder HTML |
| `folder($group, $id, $type)` | custom_upload_helper | Generate upload folder path |

---

## 13. Existing Database Tables (Base System)

| Table | Purpose |
|-------|---------|
| `users` | User accounts (name, email, password, status) |
| `user_profile` | User-role mapping (supports multiple roles per user) |
| `master_roles` | Role definitions (name, rank, status) |
| `system_abilities` | Permission definitions (name, slug, description) |
| `system_permission` | Role-permission mapping |
| `entity_files` | Polymorphic file uploads |
| `master_email_templates` | Email templates |
| `system_login_attempt` | Failed login tracking (rate limiting) |
| `system_login_history` | Login audit trail |
