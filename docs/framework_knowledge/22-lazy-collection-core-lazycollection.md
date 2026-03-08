# 22. LazyCollection (`Core\LazyCollection`)

## Overview

`Core\LazyCollection` supports iterator-based lazy pipelines for large datasets. Implements `\Iterator` and `\Countable`. Items are loaded in chunks from a source generator and processed one at a time — never loading the full dataset into memory.

Source: `systems/Core/LazyCollection.php` (~460 lines).  
Entry points: `cursor($chunkSize)` and `lazy($chunkSize)` from `BaseDatabase.php`.

## Complete API Reference

### Iterator Methods

| Method | Signature | Description |
|--------|-----------|-------------|
| `__construct` | `__construct(callable $source)` | Create from generator/callable source |
| `current` | `current(): mixed` | Get current item |
| `key` | `key(): mixed` | Get current position |
| `next` | `next(): void` | Advance to next item |
| `rewind` | `rewind(): void` | Reset to start |
| `valid` | `valid(): bool` | Check if current position is valid |
| `count` | `count(): int` | Count all items (materializes full set) |

### Transform Operations (return new LazyCollection — lazy, not executed until consumed)

| Method | Signature | Description |
|--------|-----------|-------------|
| `map` | `map(callable $callback): LazyCollection` | Transform each item lazily. Callback: `fn($item)` |
| `filter` | `filter(callable $callback): LazyCollection` | Keep items passing callback |
| `reject` | `reject(callable $callback): LazyCollection` | Remove items matching callback |

### Extraction / Terminal Operations

| Method | Signature | Return | Description |
|--------|-----------|--------|-------------|
| `all` | `all(): array` | `array` | Materialize entire collection into array (**use with caution on large sets**) |
| `first` | `first(?callable $callback = null, mixed $default = null): mixed` | `mixed` | Get first item (or first matching callback, or `$default`) |
| `pluck` | `pluck(string $key, string\|null $valueKey = null): LazyCollection\|array` | mixed | Extract single key from each item |
| `implode` | `implode(string $key, string $glue = ''): string` | `string` | Concatenate values with glue |

### Flow Control

| Method | Signature | Description |
|--------|-----------|-------------|
| `each` | `each(callable $callback): LazyCollection` | Execute callback per item (side effects). Return `false` to stop. |
| `tap` | `tap(callable $callback): LazyCollection` | Inspect without modifying |
| `take` | `take(int $limit): LazyCollection` | Take first N items, stop iteration after |
| `skip` | `skip(int $count): LazyCollection` | Skip first N items |
| `chunk` | `chunk(int $size): LazyCollection` | Yield arrays of N items at a time |

### Controls

| Method | Signature | Description |
|--------|-----------|-------------|
| `setChunkSize` | `setChunkSize(int $size): LazyCollection` | Change internal chunk loading size (default: 100) |

---

## Examples

### 1) Basic cursor streaming — process millions of rows

```php
db()->table('users')
    ->select('id, name, email')
    ->whereNull('deleted_at')
    ->cursor(1000)
    ->each(function ($row) {
        // Process one row at a time — only ~1000 rows in memory at once
        sendNewsletter($row['email'], $row['name']);
    });
```

### 2) Lazy filter + map pipeline

```php
$activeEmails = db()->table('users')
    ->whereNull('deleted_at')
    ->cursor(500)
    ->filter(function ($row) {
        return $row['user_status'] == 1 && !empty($row['email']);
    })
    ->map(function ($row) {
        return strtolower($row['email']);
    })
    ->all();
// Only active users with emails, lowercased — streamed efficiently
```

### 3) Take first N from large table

```php
$first100 = db()->table('orders')
    ->where('status', 'pending')
    ->latest()
    ->lazy(500)
    ->take(100)
    ->all();
// Stops loading after 100 items even if query would yield thousands
```

### 4) Skip + take for manual pagination

```php
$page2 = db()->table('products')
    ->orderBy('id', 'ASC')
    ->cursor(200)
    ->skip(50)
    ->take(50)
    ->all();
// Items 51–100
```

### 5) Chunk for batch processing

```php
db()->table('logs')
    ->whereDate('created_at', '<', '2025-01-01')
    ->lazy(2000)
    ->chunk(500)
    ->each(function ($batch) {
        // $batch is an array of 500 items
        archiveBatch($batch);
    });
```

### 6) Export large dataset to CSV

```php
$file = fopen('export/users.csv', 'w');
fputcsv($file, ['ID', 'Name', 'Email', 'Created']);

db()->table('users')
    ->select('id, name, email, created_at')
    ->whereNull('deleted_at')
    ->cursor(1000)
    ->each(function ($row) use ($file) {
        fputcsv($file, [$row['id'], $row['name'], $row['email'], $row['created_at']]);
    });

fclose($file);
```

### 7) Pluck + implode for quick extraction

```php
$names = db()->table('users')
    ->where('role', 'admin')
    ->cursor(100)
    ->pluck('name');
// Returns LazyCollection of name values

$nameList = db()->table('users')
    ->where('role', 'admin')
    ->cursor(100)
    ->implode('name', ', ');
// "Alice, Bob, Charlie"
```

### 8) Custom chunk size for memory tuning

```php
db()->table('large_table')
    ->cursor()
    ->setChunkSize(5000)   // load 5000 rows per internal chunk
    ->filter(function ($row) {
        return $row['score'] > 90;
    })
    ->each(function ($row) {
        notifyHighScorer($row);
    });
```

## How To Use

1. Build DB query normally, then call `cursor()` or `lazy()` to get LazyCollection.
2. Chain transforms (`map`, `filter`, `reject`) — they are lazy, not executed yet.
3. Terminate with `each()`, `all()`, `first()`, `implode()`, or `count()`.
4. Use `take()` / `skip()` to limit how many items are processed.
5. Use `chunk()` to process in batches when external APIs need batch input.
6. Tune `setChunkSize()` based on available memory and row size.

## What To Avoid

- Avoid calling `all()` on unbounded queries; it materializes everything into memory.
- Avoid calling `count()` unless you need the total — it iterates all items.
- Avoid heavy side effects inside `map()`; use `each()` for effectful operations.
- Avoid mixing eager `Collection` expectations with lazy iterators.
- Avoid forgetting `take()` for unbounded streams in long-running jobs.

## Benefits

- Constant memory usage regardless of dataset size.
- Pipeline-style functional processing.
- Natural fit for exports, batch processing, and background jobs.
- Composable transforms that only execute when consumed.
- Seamless integration with query builder `cursor()`/`lazy()`.

## Evidence

- `systems/Core/LazyCollection.php`
- `systems/Core/Database/BaseDatabase.php` (`cursor()`, `lazy()`)
