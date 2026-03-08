# 21. Collection (`Core\Collection`)

## Overview

`Core\Collection` is a fluent array wrapper implementing `ArrayAccess`, `Countable`, `IteratorAggregate`, and `JsonSerializable`. Entry point: `collect(array $items)` helper from `systems/hooks.php`.

Source: `systems/Core/Collection.php` (~1006 lines, 70+ public methods).

## Complete API Reference

### Creation & Conversion

| Method | Signature | Return | Description |
|--------|-----------|--------|-------------|
| `__construct` | `__construct(array\|self $items = [])` | — | Create from array or another Collection |
| `all` | `all(): array` | `array` | Get underlying array |
| `toArray` | `toArray(): array` | `array` | Recursively convert to array |
| `toJson` | `toJson(int $options = 0): string` | `string` | JSON encode |
| `toQueryString` | `toQueryString(): string` | `string` | URL query string `http_build_query` |
| `count` | `count(): int` | `int` | Count items |
| `isEmpty` | `isEmpty(): bool` | `bool` | Check if empty |
| `isNotEmpty` | `isNotEmpty(): bool` | `bool` | Check if not empty |

### Retrieval / Inspection

| Method | Signature | Description |
|--------|-----------|-------------|
| `first` | `first(?callable $callback = null, mixed $default = null): mixed` | First item, or first matching callback |
| `last` | `last(?callable $callback = null, mixed $default = null): mixed` | Last item, or last matching callback |
| `get` | `get(mixed $key, mixed $default = null): mixed` | Get by key with optional default |
| `dot` | `dot(string $key, mixed $default = null): mixed` | Dot-notation access (e.g., `'user.address.city'`) |
| `has` | `has(string\|int $key): bool` | Check key existence |
| `contains` | `contains(mixed $key, mixed $value = null): bool` | Check value existence. Supports: direct value, key-value pair, or callback. |
| `search` | `search(mixed $value, bool $strict = false): mixed` | Search for value, returns key or `false` |
| `pluck` | `pluck(string\|int $valueKey, string\|int\|null $indexKey = null): static` | Extract single column, optionally keyed |
| `only` | `only(array $keys): static` | Keep only given keys |
| `except` | `except(array $keys): static` | Remove given keys |

### Transformation

| Method | Signature | Description |
|--------|-----------|-------------|
| `map` | `map(callable $callback): static` | Transform each item. Callback: `fn($value, $key)` |
| `flatMap` | `flatMap(callable $callback): static` | Map and flatten one level |
| `flatten` | `flatten(int $depth = INF): static` | Flatten nested arrays |
| `filter` | `filter(?callable $callback = null): static` | Keep items passing callback (or truthy if no callback) |
| `reject` | `reject(callable $callback): static` | Remove items matching callback |
| `each` | `each(callable $callback): static` | Iterate with side effects. Return `false` to break. |
| `pipe` | `pipe(callable $callback): mixed` | Pass entire collection to callback, return whatever callback returns |
| `tap` | `tap(callable $callback): static` | Inspect collection without modifying (callback receives `$this`) |
| `when` | `when(bool $condition, callable $callback, ?callable $default = null): static` | Conditional transform |
| `unless` | `unless(bool $condition, callable $callback, ?callable $default = null): static` | Inverse conditional |

### Query-like Filtering

| Method | Signature | Description |
|--------|-----------|-------------|
| `where` | `where(string $key, mixed $operator = null, mixed $value = null): static` | Filter by key/value with operator (`=`, `!=`, `>`, `<`, `>=`, `<=`, `===`, `!==`). 2-arg defaults to `=`. |
| `whereIn` | `whereIn(string $key, array $values): static` | Keep items where key is in values |
| `whereNotIn` | `whereNotIn(string $key, array $values): static` | Keep items where key not in values |
| `whereNull` | `whereNull(string $key): static` | Keep items where key is null |
| `whereNotNull` | `whereNotNull(string $key): static` | Keep items where key is not null |
| `whereBetween` | `whereBetween(string $key, array $values): static` | Keep items where key between `[$min, $max]` |
| `unique` | `unique(?string $key = null): static` | Remove duplicates (optionally by key) |

### Sorting

| Method | Signature | Description |
|--------|-----------|-------------|
| `sortBy` | `sortBy(string\|callable $callback, int $options = SORT_REGULAR, bool $descending = false): static` | Sort by column or callback |
| `sortByDesc` | `sortByDesc(string\|callable $callback, int $options = SORT_REGULAR): static` | Sort descending |
| `sortKeys` | `sortKeys(int $options = SORT_REGULAR, bool $descending = false): static` | Sort by keys |
| `reverse` | `reverse(): static` | Reverse order |
| `shuffle` | `shuffle(): static` | Randomize order |

### Grouping / Restructuring

| Method | Signature | Description |
|--------|-----------|-------------|
| `groupBy` | `groupBy(string\|callable $groupBy): static` | Group items by key or callback |
| `keyBy` | `keyBy(string\|callable $keyBy): static` | Re-key items by column or callback |
| `collapse` | `collapse(): static` | Collapse array of arrays into single flat collection |
| `values` | `values(): static` | Reset keys to sequential integers |
| `keys` | `keys(): static` | Get keys as new collection |
| `flip` | `flip(): static` | Swap keys and values |
| `chunk` | `chunk(int $size): static` | Split into chunks (collection of collections) |
| `zip` | `zip(array ...$arrays): static` | Merge corresponding elements from arrays |
| `pad` | `pad(int $size, mixed $value): static` | Pad to size with value |

### Set Operations

| Method | Signature | Description |
|--------|-----------|-------------|
| `merge` | `merge(array\|self $items): static` | Merge items (overwrites matching keys) |
| `combine` | `combine(array\|self $values): static` | Current items as keys, $values as values |
| `intersect` | `intersect(array\|self $items): static` | Items present in both |
| `diff` | `diff(array\|self $items): static` | Items not in $items |
| `diffKeys` | `diffKeys(array\|self $items): static` | Items with keys not in $items |

### Mutation

| Method | Signature | Description |
|--------|-----------|-------------|
| `push` | `push(mixed ...$values): static` | Append values |
| `put` | `put(mixed $key, mixed $value): static` | Set key/value |
| `pull` | `pull(mixed $key, mixed $default = null): mixed` | Get and remove key |
| `forget` | `forget(string\|int\|array $keys): static` | Remove keys |
| `random` | `random(int $number = 1): static` | Get random items |

### Slicing

| Method | Signature | Description |
|--------|-----------|-------------|
| `take` | `take(int $limit): static` | Take first N items (negative = take last N) |
| `skip` | `skip(int $count): static` | Skip first N items |
| `slice` | `slice(int $offset, ?int $length = null): static` | Slice at offset with optional length |

### Aggregation

| Method | Signature | Return |
|--------|-----------|--------|
| `sum` | `sum(string\|callable\|null $callback = null): int\|float` | Sum values (optionally by key or callback) |
| `avg` / `average` | `avg(string\|callable\|null $callback = null): int\|float\|null` | Average |
| `min` | `min(string\|callable\|null $callback = null): mixed` | Minimum |
| `max` | `max(string\|callable\|null $callback = null): mixed` | Maximum |
| `median` | `median(string\|callable\|null $callback = null): int\|float\|null` | Median value |
| `reduce` | `reduce(callable $callback, mixed $initial = null): mixed` | Accumulate/reduce to single value |

### String Output

| Method | Signature | Description |
|--------|-----------|-------------|
| `implode` | `implode(string\|callable $value, ?string $glue = null): string` | Join items. 1-arg: join values by glue. 2-arg: pluck key then join. |
| `join` | `join(string $glue, string $finalGlue = ''): string` | Join with final separator (e.g., `', '` with `' and '`) |

### Debug

| Method | Signature | Description |
|--------|-----------|-------------|
| `dump` | `dump(): static` | Print contents and continue |
| `dd` | `dd(): never` | Dump and die |

---

## Examples

### 1) Basic filtering and transformation pipeline

```php
$users = db()->table('users')->whereNull('deleted_at')->get();

$activeEmails = collect($users)
    ->where('user_status', 1)
    ->reject(function ($user) {
        return empty($user['email']);
    })
    ->pluck('email')
    ->values()
    ->all();
// ['john@example.com', 'jane@example.com', ...]
```

### 2) Grouping and aggregation

```php
$orders = db()->table('orders')->get();

$byStatus = collect($orders)
    ->groupBy('status')
    ->map(function ($group) {
        return [
            'count' => $group->count(),
            'total' => $group->sum('total'),
            'avg'   => $group->avg('total'),
        ];
    })
    ->all();
// ['pending' => ['count' => 5, 'total' => 400, 'avg' => 80], 'completed' => [...]]
```

### 3) Dot-notation access for nested data

```php
$config = collect([
    'database' => [
        'connections' => [
            'mysql' => ['host' => '127.0.0.1', 'port' => 3306]
        ]
    ]
]);

$host = $config->dot('database.connections.mysql.host'); // '127.0.0.1'
```

### 4) Complex sorting and slicing

```php
$topSpenders = collect($users)
    ->whereNotNull('total_spent')
    ->sortByDesc('total_spent')
    ->take(10)
    ->map(function ($user) {
        return ['name' => $user['name'], 'spent' => $user['total_spent']];
    })
    ->values()
    ->all();
```

### 5) Reduce — build summary from collection

```php
$summary = collect($orders)->reduce(function ($carry, $order) {
    $carry['total_amount'] += $order['total'];
    $carry['order_count']++;
    if ($order['status'] === 'refunded') {
        $carry['refund_count']++;
    }
    return $carry;
}, ['total_amount' => 0, 'order_count' => 0, 'refund_count' => 0]);
// ['total_amount' => 12500, 'order_count' => 42, 'refund_count' => 3]
```

### 6) where with operators

```php
$expensive = collect($products)
    ->where('price', '>', 100)
    ->where('category', 'electronics')
    ->sortBy('price')
    ->values();
```

### 7) flatMap and collapse

```php
// flatMap: extract tags from posts, flatten one level
$allTags = collect($posts)->flatMap(function ($post) {
    return $post['tags']; // each post has array of tags
})->unique()->values()->all();

// collapse: flatten array of arrays
$merged = collect([[1, 2], [3, 4], [5, 6]])->collapse()->all();
// [1, 2, 3, 4, 5, 6]
```

### 8) pipe and tap for debugging

```php
$result = collect($users)
    ->where('user_status', 1)
    ->tap(function ($collection) {
        logger()->info('Active users count: ' . $collection->count());
    })
    ->pluck('name')
    ->pipe(function ($names) {
        return $names->implode(', ');
    });
// "John, Jane, Bob" — and logged the count during processing
```

### 9) Conditional processing with when/unless

```php
$query = collect($users)
    ->when($filterRole, function ($c) use ($filterRole) {
        return $c->where('role', $filterRole);
    })
    ->unless($includeInactive, function ($c) {
        return $c->where('user_status', 1);
    })
    ->sortBy('name');
```

### 10) keyBy for quick lookup maps

```php
$usersById = collect($users)->keyBy('id');
// Access: $usersById->get(42) → ['id' => 42, 'name' => 'John', ...]
```

## How To Use

1. Use `collect(...)` helper to begin fluent operations.
2. Keep pure transforms (`map`/`filter`) separate from side-effects (`each`).
3. Use `where`/`whereIn`/`whereNull` for SQL-like filtering on arrays.
4. Use `groupBy` + `sum`/`avg` for in-memory aggregation.
5. Use `pluck` to extract single columns, `keyBy` for lookup maps.
6. Chain `->values()` after filtering to reset sequential keys.
7. Use `pipe` for terminal operations that return non-collection values.

## What To Avoid

- Avoid using collection chains where plain loops are clearer for very simple operations.
- Avoid mutating external state inside `map()` — use `each()` for side effects.
- Avoid calling `all()` in the middle of a chain (it returns a plain array).
- Avoid `random()` with a number larger than the collection size (throws exception).

## Benefits

- Expressive, readable data pipelines replacing nested loops.
- SQL-like querying (`where`, `whereIn`, `whereBetween`) on in-memory arrays.
- Rich aggregation (`sum`, `avg`, `min`, `max`, `median`, `reduce`).
- Functional patterns (`map`, `filter`, `reduce`, `pipe`, `tap`).
- Debug-friendly with `dump()`/`dd()`.

## Evidence

- `systems/Core/Collection.php`
- `systems/hooks.php` (`collect(...)` helper)
