# 13. Database Scopes & Macros

## Overview

Scopes and macros extend the query builder without modifying core files. They are loaded at bootstrap via `loadScopeMacroDBFunctions(...)` from `systems/hooks.php`.

- Config source: `framework.scope_macro` in `app/config/framework.php`.
- Default base path: `app/database/`.
- Default folder: `ScopeMacroQuery`.
- Files: `Scope.php` (scopes), `Macro.php` (macros).

## How Scopes Work

Scopes are pre-defined query modifiers registered on the `$db` builder via `$db->scopes(array $scopes)`. Once registered, they can be called fluently on any query.

### Built-in Scopes (from `app/database/ScopeMacroQuery/Scope.php`)

| Scope | Signature | Description |
|-------|-----------|-------------|
| `withTrashed` | `scopeWithTrashed($query)` | Disable soft-delete filter — include deleted records |
| `onlyTrashed` | `scopeOnlyTrashed($query)` | Show only soft-deleted records (where `deleted_at IS NOT NULL`) |
| `latest` | `function($column = 'created_at')` | `orderBy($column, 'DESC')` |
| `oldest` | `function($column = 'created_at')` | `orderBy($column, 'ASC')` |
| `recent` | `function(int $days = 7, $column = 'created_at')` | `whereDate($column, '>=', date - $days)` — records from last N days |

The `scopeSystemQuery($db)` function registers `latest`, `oldest`, and `recent` via `$db->scopes()`.

## How Macros Work

Macros add custom chainable methods to the query builder via `$db->macros(array $macros)`. They extend the builder's vocabulary for project-specific patterns.

### Built-in Macros (from `app/database/ScopeMacroQuery/Macro.php`)

| Macro | Usage | Description |
|-------|-------|-------------|
| `whereLike` | `->whereLike($column, $value)` | `->where($column, 'LIKE', "%{$value}%")` — already built-in to builder, but this is the macro registration pattern |

The `macroQuery($db, $includeOnly = ['*'])` function registers macros on the builder. The `$includeOnly` array filters which macros are registered (use `['*']` for all).

## Config

```php
// app/config/framework.php
'scope_macro' => [
    'base_path' => 'app/database/',
    'folders' => ['ScopeMacroQuery'],
    'files' => [],
],
```

## Examples

### 1) Using built-in scopes

```php
// Get recent records (last 7 days by default)
$recentUsers = db()->table('users')
    ->recent()
    ->get();

// Get recent records from last 30 days
$recentOrders = db()->table('orders')
    ->recent(30)
    ->get();

// Get all records including soft-deleted
$allUsers = db()->table('users')
    ->withTrashed()
    ->get();

// Get only soft-deleted records
$deletedUsers = db()->table('users')
    ->onlyTrashed()
    ->get();
```

### 2) Creating a custom scope file

Create `app/database/ScopeMacroQuery/ProjectScope.php`:

```php
<?php

function scopeProjectQuery($db)
{
    $db->scopes([
        // Active records (non-deleted, status = 1)
        'active' => function () {
            $this->whereNull('deleted_at')->where('user_status', 1);
        },
        // Verified users only
        'verified' => function () {
            $this->whereNotNull('email_verified_at');
        },
        // Created within a date range
        'createdBetween' => function ($from, $to) {
            $this->whereDate('created_at', '>=', $from)
                 ->whereDate('created_at', '<=', $to);
        },
    ]);
}
```

Usage:

```php
$users = db()->table('users')
    ->active()
    ->verified()
    ->createdBetween('2025-01-01', '2025-12-31')
    ->get();
```

### 3) Creating a custom macro file

Create `app/database/ScopeMacroQuery/ProjectMacro.php`:

```php
<?php

function macroProjectQuery($db, $includeOnly = ['*'])
{
    $db->macros([
        // Search across multiple columns
        'searchAcross' => function (array $columns, string $term) {
            $this->whereAny($columns, 'LIKE', '%' . $term . '%');
        },
        // Date range filter
        'dateRange' => function (string $column, string $from, string $to) {
            $this->whereDate($column, '>=', $from)->whereDate($column, '<=', $to);
        },
    ]);
}
```

Usage:

```php
$users = db()->table('users')
    ->searchAcross(['name', 'email', 'phone'], 'john')
    ->dateRange('created_at', '2025-01-01', '2025-06-30')
    ->get();
```

### 4) Loading function signature

```php
// From systems/hooks.php — called at bootstrap
loadScopeMacroDBFunctions(
    $params,             // config array with base_path, folders, files
    $filename = [],      // specific filenames to load
    $foldername = [],    // specific folder names
    $base_path = null,   // override base path
    $silent = false      // suppress errors
);
```

## How To Use

1. Put scope/macro PHP files in `app/database/ScopeMacroQuery/`.
2. Name scope functions as `scopeYourName($db)` — use `$db->scopes([...])` inside.
3. Name macro functions as `macroYourName($db, $includeOnly)` — use `$db->macros([...])` inside.
4. Scopes and macros are loaded automatically at bootstrap from configured folders.
5. Additional folders can be added in `framework.scope_macro.folders` config.

## What To Avoid

- Avoid editing framework baseline scope/macro files directly.
- Avoid scattering scope files across unconfigured folders (they won't load).
- Avoid naming collisions — scope/macro names share the query builder namespace.
- Avoid heavy logic in scopes; they should only modify query state.

## Benefits

- Extends query builder without modifying core system files.
- Reusable query patterns across controllers and services.
- Centralized conventions for common filtering patterns.
- Easy to add project-specific query extensions.
- Multiple folders support for modular organization.

## Evidence

- `systems/hooks.php` (`loadScopeMacroDBFunctions`)
- `app/config/framework.php` (`scope_macro`)
- `app/database/ScopeMacroQuery/Scope.php`
- `app/database/ScopeMacroQuery/Macro.php`
- `systems/app.php` (bootstrapping flow)
