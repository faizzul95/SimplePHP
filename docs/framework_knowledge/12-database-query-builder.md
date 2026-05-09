# 12. Database Query Builder

## Core Builder

- Builder implementation: `systems/Core/Database/BaseDatabase.php` plus focused concern traits in `systems/Core/Database/Concerns/`.
- The DB facade delegates driver calls through `Database::__call`.
- Drivers: `MySQLDriver.php`, `MariaDBDriver.php` (in `systems/Core/Database/Drivers/`).
- Entry point: `db()->table('table_name')` to begin any query chain.
- Driver metadata now resolves through `systems/Core/Database/DriverRegistry.php`.
- `Database` exposes `capabilities()`, `schemaGrammar()`, and `queryGrammar()` for the configured driver.

### Internal Architecture

- `BaseDatabase` keeps connection state, query execution, CRUD, pagination, result conversion, transactions, and driver-abstract hooks.
- `HasWhereConditions` owns WHERE, relationship-existence predicates, temporal helper compilation, and raw-where normalization.
- `HasJoins` owns JOIN builders and JOIN condition escaping.
- `HasAggregates` owns aggregate functions, grouping, having, ordering, unions, index hints, and eager aggregate helpers.
- `HasEagerLoading` owns batched eager-load processing and incremental relation attachment.
- `HasStreaming` owns `chunk`, `cursor`, `lazy`, `chunkById`, and `lazyById` large-data iteration helpers.
- `HasProfiling` owns query profiling, slow-query logging, retry behavior, and per-session performance rules.
- `HasDebugHelpers` owns SQL/debug rendering helpers such as `toSql`, `toRawSql`, `dump`, `dd`, and `toDebugSql`.
- Primary-key lookup helpers now include `find`, `findMany`, and `findOrFail` in addition to `fetch`, `firstOrFail`, and `sole`.
- Record-creation helpers now include `firstOrNew`, `firstOrCreate`, `insertOrUpdate`, `updateOrInsert`, and `updateOrCreate`.
- Soft-delete flows now expose `softDelete`, `delete`, `forceDelete`, and `restore` explicitly.

### Connection Pool and Statement Cache

- `ConnectionPool` has an in-request static PDO pool keyed by connection name.
- PDO persistent connections are enabled by default through `PDO::ATTR_PERSISTENT`, allowing the same PHP worker to reuse the underlying database socket across requests.
- `ConnectionPool` stores only lightweight APCu metadata (`last_used`, hit/miss counters, worker PID) when APCu is available; it never stores PDO objects in shared memory.
- `StatementCache` keeps PDOStatement handles in an in-process LRU cache. Handles are request/process resources and are not cross-process serializable.
- `StatementCache` uses APCu, when available, as a SQL warmth registry so new workers can pre-prepare hot SQL strings with `prewarmFromRegistry()`.
- APCu calls are optional and dynamically guarded. When APCu is missing, disabled, or unavailable in CLI, the database layer falls back to local pooling/caching without changing the public API.
- Long-running CLI workers can opt out of persistent PDO by setting a connection config value `persistent => false`.

### Driver registry and grammars

- `DriverRegistry::resolveClass($driver)` resolves the concrete driver class.
- `DriverRegistry::capabilities($driver)` returns `DriverCapabilities` feature flags.
- `DriverRegistry::schemaGrammar($driver)` resolves schema DDL grammars.
- `DriverRegistry::queryGrammar($driver)` resolves driver-specific query-expression helpers.
- Temporal builder methods such as `whereDate()` and `whereTime()` now compile through the registered query grammar instead of hardcoded driver matches in each concrete driver.

---

## Complete Query Capabilities (Verified from Source)

### Table & Select

| Method | Signature | Description |
|--------|-----------|-------------|
| `table` | `table(string $table): self` | Set target table |
| `select` | `select(string\|array $columns = ['*']): self` | Column selection |
| `selectRaw` | `selectRaw(string $expression): self` | Raw select expression |
| `selectSub` | `selectSub(BaseDatabase\|string $query, string $alias): self` | Subquery as aliased column |
| `distinct` | `distinct(string\|null $columns = null): self` | Distinct filter |

### Where Conditions (Full List)

#### Basic

| Method | Signature | Description |
|--------|-----------|-------------|
| `where` | `where(string\|array $col, $op = null, $val = null): self` | Supports 2-arg (`=`), 3-arg, and array-of-conditions syntax |
| `orWhere` | `orWhere(string\|array $col, $op = null, $val = null): self` | OR variant |
| `whereRaw` | `whereRaw(string $sql, array $value = [], string $whereType = 'AND'): self` | Raw SQL condition with bindings |
| `whereColumn` | `whereColumn(string $col1, string $op = null, string $col2 = null): self` | Compare two columns |
| `orWhereColumn` | `orWhereColumn(string $col1, string $op = null, string $col2 = null): self` | OR column comparison |

#### IN / NOT IN

| Method | Signature |
|--------|-----------|
| `whereIn` | `whereIn(string $column, array $value = []): self` |
| `orWhereIn` | `orWhereIn(string $column, array $value = []): self` |
| `whereNotIn` | `whereNotIn(string $column, array $value = []): self` |
| `orWhereNotIn` | `orWhereNotIn(string $column, array $value = []): self` |

#### BETWEEN

| Method | Signature |
|--------|-----------|
| `whereBetween` | `whereBetween(string $col, $start, $end): self` |
| `orWhereBetween` | `orWhereBetween(string $col, $start, $end): self` |
| `whereNotBetween` | `whereNotBetween(string $col, $start, $end): self` |
| `orWhereNotBetween` | `orWhereNotBetween(string $col, $start, $end): self` |
| `whereBetweenColumns` | `whereBetweenColumns(string $col, array [$colA, $colB]): self` | Column-range check |

#### NULL Checks

| Method | Signature |
|--------|-----------|
| `whereNull` | `whereNull(string $column): self` |
| `orWhereNull` | `orWhereNull(string $column): self` |
| `whereNotNull` | `whereNotNull(string $column): self` |
| `orWhereNotNull` | `orWhereNotNull(string $column): self` |

#### NOT

| Method | Signature |
|--------|-----------|
| `whereNot` | `whereNot(string $column, $operator = null, $value = null): self` |
| `orWhereNot` | `orWhereNot(string $column, $operator = null, $value = null): self` |

#### LIKE

| Method | Signature |
|--------|-----------|
| `whereLike` | `whereLike(string $column, string $value): self` |
| `orWhereLike` | `orWhereLike(string $column, string $value): self` |
| `whereNotLike` | `whereNotLike(string $column, string $value): self` |
| `orWhereNotLike` | `orWhereNotLike(string $column, string $value): self` |

#### Multi-Column Helpers

| Method | Signature | Description |
|--------|-----------|-------------|
| `whereAny` | `whereAny(array $columns, string $op, $value): self` | Any column matches (OR logic) |
| `whereAll` | `whereAll(array $columns, string $op, $value): self` | All columns match (AND logic) |
| `whereNone` | `whereNone(array $columns, string $op, $value): self` | No column matches |

#### Date / Time (driver-abstract)

| Method | Signature |
|--------|-----------|
| `whereDate` | `whereDate(string $col, string $op, string $val): self` |
| `orWhereDate` | `orWhereDate(string $col, string $op, string $val): self` |
| `whereDay` | `whereDay(string $col, string $op, string $val): self` |
| `orWhereDay` | `orWhereDay(string $col, string $op, string $val): self` |
| `whereMonth` | `whereMonth(string $col, string $op, string $val): self` |
| `orWhereMonth` | `orWhereMonth(string $col, string $op, string $val): self` |
| `whereYear` | `whereYear(string $col, string $op, string $val): self` |
| `orWhereYear` | `orWhereYear(string $col, string $op, string $val): self` |
| `whereTime` | `whereTime(string $col, string $op, string $val): self` |
| `orWhereTime` | `orWhereTime(string $col, string $op, string $val): self` |

#### JSON

| Method | Signature | Description |
|--------|-----------|-------------|
| `whereJsonContains` | `whereJsonContains(string $col, string $jsonPath, $value): self` | JSON path containment check |

#### Full-Text

| Method | Signature | Description |
|--------|-----------|-------------|
| `whereFullText` | `whereFullText(array $columns, string $value, array $options = []): self` | MATCH...AGAINST (boolean mode supported via `['mode' => 'boolean']`) |

#### Integer Raw (no binding, high-performance)

| Method | Signature | Description |
|--------|-----------|-------------|
| `whereIntegerInRaw` | `whereIntegerInRaw(string $column, array $values): self` | IN with raw integer values (no PDO binding, fast for large ID sets) |
| `whereIntegerNotInRaw` | `whereIntegerNotInRaw(string $column, array $values): self` | NOT IN with raw integer values |

#### Relationship Existence (EXISTS / NOT EXISTS subquery)

| Method | Signature | Description |
|--------|-----------|-------------|
| `whereHas` | `whereHas(string $relationTable, string $foreignKey, string $localKey, ?Closure $callback = null): self` | AND EXISTS subquery |
| `orWhereHas` | `orWhereHas(string $relationTable, string $foreignKey, string $localKey, ?Closure $callback = null): self` | OR EXISTS subquery |
| `whereDoesntHave` | `whereDoesntHave(string $relationTable, string $foreignKey, string $localKey, ?Closure $callback = null): self` | AND NOT EXISTS subquery |
| `orWhereDoesntHave` | `orWhereDoesntHave(string $relationTable, string $foreignKey, string $localKey, ?Closure $callback = null): self` | OR NOT EXISTS subquery |

### Conditional Building

| Method | Signature | Description |
|--------|-----------|-------------|
| `when` | `when($condition, callable $callback): self` | Apply clauses only when condition is truthy |
| `unless` | `unless($condition, callable $callback): self` | Inverse of `when` |
| `tap` | `tap(callable $callback): self` | Inspect builder without modifying query |

### Join / Group / Sort

#### Joins

| Method | Signature | Description |
|--------|-----------|-------------|
| `join` | `join(string $table, string $fk, string $lk, string $joinType = 'LEFT'): self` | Generic join |
| `leftJoin` | `leftJoin(string $table, string $fk, string $lk, $conditions = null): self` | LEFT JOIN with optional extra conditions |
| `rightJoin` | `rightJoin(string $table, string $fk, string $lk, $conditions = null): self` | RIGHT JOIN |
| `innerJoin` | `innerJoin(string $table, string $fk, string $lk, $conditions = null): self` | INNER JOIN |
| `outerJoin` | `outerJoin(string $table, string $fk, string $lk, $conditions = null): self` | OUTER JOIN |
| `crossJoin` | `crossJoin(string $table): self` | CROSS JOIN |

#### Group By / Having

| Method | Signature |
|--------|-----------|
| `groupBy` | `groupBy(string\|array $columns): self` |
| `groupByRaw` | `groupByRaw(string $expression, array $bindings = []): self` |
| `having` | `having(string $column, $value, string $operator = '='): self` |
| `havingRaw` | `havingRaw(string $conditions): self` |
| `havingBetween` | `havingBetween(string $column, array $values): self` |

#### Order By

| Method | Signature | Description |
|--------|-----------|-------------|
| `orderBy` | `orderBy(string\|array $columns, string $direction = 'DESC'): self` | Sort results |
| `orderByAsc` | `orderByAsc(string $column): self` | Shorthand ASC |
| `orderByDesc` | `orderByDesc(string $column): self` | Shorthand DESC |
| `orderByRaw` | `orderByRaw(string $expr, $bindParams = null): self` | Raw order expression |
| `latest` | `latest(string $column = 'created_at'): self` | DESC on column |
| `oldest` | `oldest(string $column = 'created_at'): self` | ASC on column |
| `reorder` | `reorder(string\|null $column = null, string $direction = 'DESC'): self` | Clear existing order and re-set |
| `inRandomOrder` | `inRandomOrder(): self` | Random order |

### Limit / Offset / Union

| Method | Signature | Description |
|--------|-----------|-------------|
| `limit` | `limit(int $n): self` | Limit results (driver-abstract) |
| `offset` | `offset(int $n): self` | Offset results (driver-abstract) |
| `skip` | `skip(int $offset): self` | Alias for `offset` |
| `take` | `take(int $limit): self` | Alias for `limit` |
| `forPage` | `forPage(int $page, int $perPage = 15): self` | Shortcut for offset-based paging |
| `union` | `union(BaseDatabase\|string $query, bool $all = false): self` | UNION query |
| `unionAll` | `unionAll(BaseDatabase\|string $query): self` | UNION ALL query |

### Index Hints (MySQL/MariaDB)

| Method | Signature | Description |
|--------|-----------|-------------|
| `useIndex` | `useIndex(string\|array $indexes): self` | USE INDEX hint — suggest optimizer use specific index |
| `forceIndex` | `forceIndex(string\|array $indexes): self` | FORCE INDEX hint — stronger than USE INDEX |
| `ignoreIndex` | `ignoreIndex(string\|array $indexes): self` | IGNORE INDEX hint — tell optimizer to skip an index |

### Eager Loading (Relationship-like)

| Method | Signature | Description |
|--------|-----------|-------------|
| `with` | `with(string $alias, string $table, string $fk, string $lk, ?Closure $callback = null): self` | HasMany eager load — each parent row gets array of children |
| `withOne` | `withOne(string $alias, string $table, string $fk, string $lk, ?Closure $callback = null): self` | HasOne eager load — each parent row gets single child or null |
| `withCount` | `withCount(string $alias, string $table, string $fk, string $lk, ?Closure $callback = null): self` | Count subquery — alias auto-suffixed with `_count` |
| `withSum` | `withSum(string $alias, string $table, string $fk, string $lk, string $sumCol, ?Closure $callback = null): self` | SUM subquery with COALESCE |
| `withAvg` | `withAvg(string $alias, string $table, string $fk, string $lk, string $avgCol, ?Closure $callback = null): self` | AVG subquery with COALESCE |
| `withMin` | `withMin(string $alias, string $table, string $fk, string $lk, string $minCol, ?Closure $callback = null): self` | MIN subquery |
| `withMax` | `withMax(string $alias, string $table, string $fk, string $lk, string $maxCol, ?Closure $callback = null): self` | MAX subquery |

> **Note:** All aggregate eager loaders (`withCount`/`withSum`/etc.) automatically append the aggregate keyword to the alias if not already present. E.g., `withCount('posts', ...)` becomes `posts_count`. The callback receives a sub-query builder to add extra conditions.

> **Large-dataset behavior:** when eager loading runs in batched mode, MythPHP uses adaptive chunk sizes and lazy chunk iteration to avoid building huge chunk arrays in memory. Related rows are attached incrementally per chunk, which lowers peak memory pressure on large tables.

### Fetch / Retrieval

| Method | Signature | Return | Description |
|--------|-----------|--------|-------------|
| `get` | `get(string\|null $table = null)` | `array` | Fetch all matching rows |
| `fetch` | `fetch(string\|null $table = null)` | `array\|null` | Fetch single row |
| `firstOrFail` | `firstOrFail(string\|null $table = null)` | `array` | Fetch single row or throw `\Exception('No records found matching the query')` |
| `sole` | `sole(string\|null $table = null)` | `array` | Fetch exactly one row. Throws if 0 records (`'No records found'`) or >1 records (`'Multiple records found, expected only one'`). Internally uses `limit(2)` to detect multiples. |
| `find` | `find(mixed $id, array\|string $columns = ['*'])` | `array\|null` | Fetch one row by primary key. Arrays delegate to `findMany`. |
| `findMany` | `findMany(array $ids, array\|string $columns = ['*'])` | `array` | Fetch multiple rows by primary key list. Empty lists return `[]`. |
| `findOrFail` | `findOrFail(mixed $id, array\|string $columns = ['*'])` | `array` | Primary-key lookup that throws when no row exists. |
| `pluck` | `pluck(string $column, string\|null $keyColumn = null)` | `array` | Extract single column (optionally keyed by another column) |
| `value` | `value(string $column)` | `mixed` | Get single column value from first row. Qualified columns such as `users.name` resolve against the fetched column alias safely. |
| `count` | `count(string\|null $table = null)` | `int` | Count matching rows |
| `exists` | `exists(string\|null $table = null)` | `bool` | Check if any rows match |
| `doesntExist` | `doesntExist(string\|null $table = null)` | `bool` | Inverse of `exists` |

### Create / Update / Delete Helpers

| Method | Signature | Return | Description |
|--------|-----------|--------|-------------|
| `firstOrNew` | `firstOrNew(array $conditions, array $data = [])` | `array` | Return the first matching row or an unsaved merged attribute payload |
| `firstOrCreate` | `firstOrCreate(array $conditions, array $data = [])` | `array` | Return the first matching row or insert a new one |
| `insertOrUpdate` | `insertOrUpdate(array $conditions, array $data, string $primaryKey = 'id')` | `mixed` | Update an existing matching row or insert a new one |
| `updateOrInsert` | `updateOrInsert(array $conditions, array $data)` | `mixed` | Alias for `insertOrUpdate` |
| `updateOrCreate` | `updateOrCreate(array $conditions, array $data = [], string $primaryKey = 'id')` | `mixed` | Persist then refetch the matching row |
| `softDelete` | `softDelete(string\|array $column = 'deleted_at', $value = null)` | `mixed` | Mark rows deleted by updating the soft-delete column |
| `delete` | `delete(bool $returnData = false)` | `mixed` | Delete rows, routing through `softDelete()` when the table supports it |
| `forceDelete` | `forceDelete(bool $returnData = false)` | `mixed` | Hard-delete rows even when the table supports soft deletes |
| `restore` | `restore(string $column = 'deleted_at')` | `mixed` | Clear the soft-delete marker column |

### Verified Builder Corrections

- Empty `whereIn([])` and `orWhereIn([])` compile to a false predicate instead of invalid `IN ()` SQL.
- Empty `whereNotIn([])` and `orWhereNotIn([])` are treated as no-ops.
- Closure-based grouped `where()` / `orWhere()` clauses reuse trusted builder-generated SQL instead of passing the generated fragment back through the public raw-query guard.
- Temporal where helpers qualify the column before handing it to the driver grammar.
- Wildcard expansion only rewrites true `SELECT *` statements and leaves explicit select lists untouched.

### Pagination / Streaming

| Method | Signature | Return | Description |
|--------|-----------|--------|-------------|
| `paginate` | `paginate(int $start = 0, int $limit = 10, int $draw = 1)` | `array` | Offset pagination |
| `paginate_ajax` | `paginate_ajax(array $dataPost)` | `array` | DataTables server-side compatible (reads `start`, `length`, `draw`, `search`, `order` from `$dataPost`) |
| `setPaginateFilterColumn` | `setPaginateFilterColumn(array $cols): self` | `self` | Configure searchable columns for `paginate_ajax` |
| `setAllowedSortColumns` | `setAllowedSortColumns(array $cols): self` | `self` | Configure the only columns `paginate_ajax` may order by |
| `cursorPaginate` | `cursorPaginate(int $perPage, string $column, ?string $cursorToken): array` | `array` | **Keyset pagination** — O(1) at any depth. Returns `['data', 'per_page', 'has_more', 'next_cursor', 'prev_cursor']`. Cursor token is base64url JSON. |
| `exportCsv` | `exportCsv(string $filename, array $columns = [], int $chunkSize = 500): void` | `void` | **Stream CSV to browser** — sends headers + UTF-8 BOM; uses `chunkById()` when eligible; O(1) peak memory per chunk. |
| `chunk` | `chunk(int $size, callable $callback): void` | `void` | Process rows in fixed-size batches. Respects existing `limit()` if set. Callback receives `$rows` array per batch; return `false` to stop early. |
| `cursor` | `cursor(int $chunkSize = 1000)` | `\Generator` | Yields rows one at a time from chunked reads |
| `lazy` | `lazy(int $chunkSize = 1000)` | `LazyCollection` | Alternative to cursor. Returns `LazyCollection` |
| `chunkById` | `chunkById(int $size, callable $callback, string $column = 'id', ?string $alias = null)` | `self` | Keyset pagination for large indexed scans |
| `lazyById` | `lazyById(int $chunkSize = 1000, string $column = 'id', ?string $alias = null)` | `LazyCollection` | Lazy keyset pagination for large indexed scans |

`chunk()`, `cursor()`, and `lazy()` conservatively auto-delegate to ID-based pagination only when the query shape is simple enough to preserve behavior: no raw query, no existing offset/group/join/having/union, selected columns still expose the key column, and any explicit ordering remains a single ascending order on that same key.

When `chunkById()` or `lazyById()` run against a builder that already had `LIMIT` applied, the original limit is preserved across keyset pages so iteration stops at the same row cap as the original query.

`paginate_ajax()` now clamps unsafe client pagination input and can keep search columns separate from sortable columns. For DataTables-style endpoints, set both `setPaginateFilterColumn([...])` and `setAllowedSortColumns([...])` when the rendered table columns do not map 1:1 to searchable columns.

For deep pagination (search results, API feeds, infinite scroll), use `cursorPaginate()` instead of `paginate()`. For bulk exports, use `exportCsv()` instead of buffering all rows in memory. See [29-cursor-pagination-n1-csp.md](29-cursor-pagination-n1-csp.md) for full documentation and examples.

### CRUD & Mutations

| Method | Signature | Return | Description |
|--------|-----------|--------|-------------|
| `insert` | `insert(array $data)` | `['code' => int, 'message' => string, 'id' => int, 'action' => 'create']` | Insert one row. Auto-sanitizes via `getTableColumns()`. Returns last insert ID. |
| `update` | `update(array $data)` | `['code' => int, 'message' => string, 'action' => 'update', 'affected_rows' => int]` | Update matched rows |
| `delete` | `delete(bool $returnData = false)` | `['code' => int, 'message' => string, 'action' => 'delete']` | Hard delete. When `$returnData = true`, returns deleted rows in `'data'` key. |
| `truncate` | `truncate(string\|null $table = null)` | `array` | Remove all rows from table |
| `softDelete` | `softDelete(string $column = 'deleted_at', $value = null)` | `array` | Set timestamp column (default `deleted_at`, value defaults to `date('Y-m-d H:i:s')`) |
| `insertOrUpdate` | `insertOrUpdate(array $conditions, array $data, string $primaryKey = 'id')` | `array` | Find by conditions → update if exists, insert if not. Auto-adds `updated_at`/`created_at`. |
| `updateOrInsert` | `updateOrInsert(array $conditions, array $data)` | `array` | Alternative upsert (alias behavior) |
| `firstOrCreate` | `firstOrCreate(array $conditions, array $data = [])` | `['code' => int, 'action' => 'found'\|'create', 'data' => array]` | Find first match or create new. Returns existing record with `action: 'found'`, or insert result. |
| `increment` | `increment(string $column, int $amount = 1, array $extra = [])` | `array` | Atomic `SET col = col + ?`. Extra columns updated simultaneously. |
| `decrement` | `decrement(string $column, int $amount = 1, array $extra = [])` | `array` | Atomic `SET col = col - ?`. |

### Batch / Bulk Operations

| Method | Signature | Description |
|--------|-----------|-------------|
| `upsert` | `upsert(array $values, string\|array $uniqueBy = 'id', array\|null $updateColumns = null, int $batchSize = 2000)` | Bulk INSERT ... ON DUPLICATE KEY UPDATE. Auto-chunks large datasets. For ≥100 rows: disables autocommit, unique checks, foreign key checks, increases buffer. Returns `['code', 'affected_rows', 'message', 'batches_processed', 'total_records']`. |
| `batchInsert` | `batchInsert(array $data): self` | Batch insert (driver implementation) |
| `batchUpdate` | `batchUpdate(array $data): self` | Batch update (driver implementation) |

### Aggregate

| Method | Signature | Return |
|--------|-----------|--------|
| `sum` | `sum(string $column)` | `int\|float` |
| `avg` | `avg(string $column)` | `int\|float` |
| `min` | `min(string $column)` | `mixed` |
| `max` | `max(string $column)` | `mixed` |
| `aggregate` | `aggregate(string $function, string $column = '*')` | `mixed` |

### Return Type Transformers

| Method | Signature | Description |
|--------|-----------|-------------|
| `toArray` | `toArray(): self` | Set return type to `array` (default) |
| `toObject` | `toObject(): self` | Set return type to `object` — results returned as `stdClass` |
| `toJson` | `toJson(): self` | Set return type to `json` — results returned as JSON string |

### SQL Debug & Inspection

| Method | Signature | Return | Description |
|--------|-----------|--------|-------------|
| `toSql` | `toSql()` | `['query' => string, 'binds' => array, 'full_query' => string]` | Get SQL with placeholders and full interpolated query |
| `toRawSql` | `toRawSql()` | `string` | Get SQL with values interpolated |
| `toDebugSql` | `toDebugSql()` | `['main_query' => string, 'with_*' => ...]` | Debug SQL for main query + all eager loaded relation queries |
| `dump` | `dump(): self` | Prints query, binds, and full query to output as preformatted HTML. Returns `$this` for chaining. |
| `dd` | `dd(): never` | Dump and die (`exit(1)`) |

### Raw Query Execution

| Method | Signature | Return | Description |
|--------|-----------|--------|-------------|
| `selectQuery` | `selectQuery(string $statement, array\|null $binds = null, string $fetchType = 'get')` | `array` | Execute raw SELECT statement. Only SELECT allowed; throws on other types. `$fetchType`: `'get'` (all rows) or `'fetch'` (first row). |
| `query` | `query(string $statement, array $bindParams = []): self` | `self` | Set raw SQL for execution. Supports any SQL type: SELECT, INSERT, UPDATE, DELETE, TRUNCATE, DROP, ALTER, CREATE, etc. Must call `execute()` after. |
| `execute` | `execute()` | `array` | Execute the raw query set by `query()`. For SELECT/SHOW/DESCRIBE: returns rows. For DML/DDL: returns `['code', 'affected_rows', 'message', 'action']`. Status codes: INSERT/CREATE → 201 on success; UPDATE/DELETE/others → 200 on success; all → 422 on failure. |

### Safety / Security

| Method | Signature | Description |
|--------|-----------|-------------|
| `safeInput` | `safeInput(): self` | Enable input sanitization on INSERT/UPDATE data |
| `safeOutput` | `safeOutput(bool $enable = true): self` | Enable HTML entity encoding on query results |
| `safeOutputWithException` | `safeOutputWithException(array $data = []): self` | Enable safe output but exclude specific columns from encoding |

### Mass-Assignment Guard (`$fillable` / `$guarded`)

`BaseDatabase` ships a two-layer column guard applied automatically on every `insert()` and `update()` call via `sanitizeColumn()`.

#### How it works

1. **Layer 1 — Schema guard (always active):** Only columns that exist in the real database table survive. Unknown keys are silently dropped. Uses `getTableColumns()` to resolve the schema.
2. **Layer 2a — Allowlist (`$fillable`):** When non-empty, only the listed columns pass through. All others are stripped even if they are real schema columns.
3. **Layer 2b — Denylist (`$guarded`):** Always strips the listed columns regardless of `$fillable`. Applied after the allowlist so a column in both lists is always blocked.

#### Declaring guards at runtime (the only pattern in this framework)

MythPHP does not use Model classes. All database access goes through `db()->table(...)`. Guards must be set via the runtime setter methods before each insert or update that handles user input.

```php
// In your controller, restrict what the user can write
db()->table('users')
    ->setFillable(['name', 'email', 'bio'])   // only these columns accepted
    ->setGuarded(['role_id', 'is_admin'])      // never accepted — even if in $fillable
    ->insert($request->all());
```

#### Runtime setters (ad-hoc, single-request scope)

| Method | Signature | Description |
|--------|-----------|-------------|
| `setFillable` | `setFillable(array $columns): static` | Replace the allowlist for this builder instance |
| `getFillable` | `getFillable(): array` | Return the current allowlist |
| `setGuarded` | `setGuarded(array $columns): static` | Replace the denylist for this builder instance |
| `getGuarded` | `getGuarded(): array` | Return the current denylist |

```php
// Temporarily restrict what a one-off insert can write
db()->table('users')
    ->setFillable(['name', 'email'])
    ->setGuarded(['role_id'])
    ->insert($request->all());
```

#### Persistence across calls

- Runtime setters (`setFillable()` / `setGuarded()`) update instance-level properties, so they persist across subsequent queries on the same builder instance.
- `reset()` clears query state (table, where, joins, etc.) but does **not** clear `$fillable`/`$guarded`. Call `setFillable([])` / `setGuarded([])` explicitly if you need to clear them.
- Because `db()` returns a shared instance, always call `setFillable()` / `setGuarded()` immediately before the `insert()` or `update()` that needs them.

#### Known design difference vs Laravel

Laravel's Eloquent throws `MassAssignmentException` when `$fillable` is not declared and unguarded mode is off. MythPHP's guard is opt-in: when both `$fillable` and `$guarded` are empty, all schema-valid columns pass through (only the schema guard runs). Always call `setFillable()` before any insert or update that processes user-supplied input.

### Transaction Control

| Method | Signature | Description |
|--------|-----------|-------------|
| `transaction` | `transaction(callable $callback): mixed` | Execute callback in transaction. Auto-commits on success, auto-rollbacks on exception. Callback receives `$this` as argument. |
| `beginTransaction` | `beginTransaction(): void` | Manual transaction start |
| `commit` | `commit(): void` | Manual commit |
| `rollback` | `rollback(): void` | Manual rollback |

### Performance / Profiling

| Method | Signature | Description |
|--------|-----------|-------------|
| `enableQueryCache` | `enableQueryCache(int $ttl = 3600): self` | Enable query caching via `QueryCache`. Default TTL: 3600s. |
| `disableQueryCache` | `disableQueryCache(): self` | Disable query caching |
| `profiler` | `profiler()` | Get query execution profiling data |
| `getPerformanceReport` | `getPerformanceReport(array $options = []): array` | Full performance stats via `PerformanceMonitor::generateReport()`, including slow, recent, frequent, and heaviest-query slices |
| `setProfilingEnabled` | `setProfilingEnabled(bool $enable = true): self` | Enable/disable profiling globally |
| `isProfilingEnabled` | `isProfilingEnabled(): bool` | Check if profiling is active |

### Connection Management

| Method | Signature | Description |
|--------|-----------|-------------|
| `addConnection` | `addConnection(string $name, array $params): self` | Add a new PDO connection. Params: `driver`, `host`, `username`, `password`, `database`, `port`, `socket`, `charset` |
| `setConnection` | `setConnection(string $connectionID): void` | Switch active connection |
| `getConnection` | `getConnection(string\|null $connectionID = null): string` | Get current connection name |
| `setDatabase` | `setDatabase(string\|null $databaseName = null): void` | Switch database schema |
| `getDatabase` | `getDatabase(): string\|null` | Get current database schema |
| `getPlatform` | `getPlatform(): string` | Get database platform name from driver map |
| `getDriver` | `getDriver(): string` | Get PDO driver name attribute |
| `getVersion` | `getVersion(): string` | Get database server version |
| `getPdo` | `getPdo(): PDO` | Get raw PDO instance for current connection |
| `disconnect` | `disconnect(string $connection = 'default', bool $remove = false): void` | Disconnect. If `$remove = true`, also removes connection config. |
| `cleanupConnections` | `cleanupConnections(): void` | Cleanup idle connections via `ConnectionPool::cleanupIdleConnections()` |
| `reset` | `reset(): void` | Clear all builder state (table, columns, where, joins, order, limit, etc.) for reuse |

### Dry Run

| Method | Signature | Description |
|--------|-----------|-------------|
| `dryRun` | `dryRun(bool $enable = true): self` | Enable dry-run mode — builds query without executing. Returns `['query', 'binds']` instead of results. |

### Table Inspection

| Method | Signature | Description |
|--------|-----------|-------------|
| `hasColumn` | `hasColumn(string $column): bool` | Check if column exists in current table. Uses cached table column list. |
| `analyze` | `analyze(): bool` | Execute `ANALYZE TABLE` on current table. Returns `true` on success. |

---

## Examples

### 1) Filtered select + pagination

```php
$result = db()->table('users')
    ->select('id, name, email, user_status')
    ->whereNull('deleted_at')
    ->where('user_status', 1)
    ->orderBy('id', 'DESC')
    ->paginate(0, 20, 1);
```

### 2) Conditional query building with `when()`

```php
$users = db()->table('users')
    ->select('id, name, email, role_id')
    ->whereNull('deleted_at')
    ->when($request->input('role_id'), function ($q) use ($request) {
        $q->where('role_id', $request->input('role_id'));
    })
    ->when($request->input('search'), function ($q) use ($request) {
        $q->whereLike('name', '%' . $request->input('search') . '%');
    })
    ->when($request->input('date_from'), function ($q) use ($request) {
        $q->whereDate('created_at', '>=', $request->input('date_from'));
    })
    ->latest()
    ->get();
```

### 3) Eager loading relationships with callbacks

```php
$users = db()->table('users')
    ->select('id, name, email')
    ->with('roles', 'user_roles', 'user_id', 'id', function ($q) {
        $q->select('role_id, role_name')
          ->where('is_active', 1);
    })
    ->withOne('profile', 'user_profiles', 'user_id', 'id', function ($q) {
        $q->select('avatar, phone, address');
    })
    ->withCount('posts', 'posts', 'user_id', 'id')
    ->withSum('order_total', 'orders', 'user_id', 'id', 'total_amount', function ($q) {
        $q->where('status', 'completed');
    })
    ->whereNull('deleted_at')
    ->get();
// Each user row will contain:
//   'roles' => [['role_id' => 1, 'role_name' => 'Admin'], ...]
//   'profile' => ['avatar' => '...', 'phone' => '...'] or null
//   'posts_count' => 15
//   'order_total_sum' => 2500.00
```

### 4) Date / time filtering

```php
// Orders from 2025, in March, created after 9am
$recent = db()->table('orders')
    ->whereYear('created_at', '=', 2025)
    ->whereMonth('created_at', '=', 3)
    ->whereTime('created_at', '>=', '09:00:00')
    ->latest()
    ->get();

// Use OR for date ranges
$records = db()->table('events')
    ->whereDate('start_date', '>=', '2025-01-01')
    ->orWhereDate('end_date', '<=', '2025-12-31')
    ->get();
```

### 5) Full-text search

```php
$results = db()->table('articles')
    ->select('id, title, body, created_at')
    ->whereFullText(['title', 'body'], 'framework tutorial', ['mode' => 'boolean'])
    ->orderBy('created_at', 'DESC')
    ->get();
```

### 6) Safe transactional update with audit log

```php
$result = db()->transaction(function ($db) use ($payload) {
    $db->table('users')->where('id', $payload['id'])->update([
        'name' => $payload['name'],
        'email' => $payload['email'],
    ]);

    $db->table('audit_logs')->insert([
        'entity_type' => 'user',
        'entity_id' => $payload['id'],
        'event' => 'user.updated',
        'changes' => json_encode($payload),
        'created_at' => date('Y-m-d H:i:s'),
    ]);

    return true; // returned from transaction()
});
```

### 7) Streaming with cursor (LazyCollection)

```php
db()->table('users')
    ->select('id, name, email')
    ->whereNull('deleted_at')
    ->cursor(500)
    ->filter(function ($row) {
        return $row['email'] !== null;
    })
    ->each(function ($row) {
        // Process row one at a time, low memory
        sendNewsletter($row['email'], $row['name']);
    });
```

### 8) Insert-or-update (upsert single row)

```php
$result = db()->table('settings')
    ->insertOrUpdate(
        ['key' => 'site_name'],          // conditions to match
        ['value' => 'My New Site'],       // data to set
        'id'                              // primary key column
    );
// If record with key='site_name' exists → updates value + updated_at
// If not → inserts key + value + created_at
// Returns ['code' => 200|201, 'message' => '...', 'action' => 'update'|'create']
```

### 9) Bulk upsert (thousands of rows)

```php
$result = db()->table('product_prices')
    ->upsert(
        [
            ['sku' => 'A001', 'price' => 29.99, 'updated_at' => date('Y-m-d H:i:s')],
            ['sku' => 'A002', 'price' => 49.99, 'updated_at' => date('Y-m-d H:i:s')],
            ['sku' => 'A003', 'price' => 19.99, 'updated_at' => date('Y-m-d H:i:s')],
            // ... thousands more
        ],
        'sku',                         // unique column(s)
        ['price', 'updated_at'],       // columns to update on duplicate
        2000                           // batch size
    );
// $result = ['code' => 200, 'affected_rows' => 3, 'batches_processed' => 1, 'total_records' => 3]
```

### 10) Subquery select + selectSub

```php
$users = db()->table('users')
    ->select('id, name')
    ->selectSub(
        db()->table('orders')
            ->selectRaw('COUNT(*)')
            ->whereRaw('orders.user_id = users.id'),
        'order_count'
    )
    ->selectSub(
        db()->table('orders')
            ->selectRaw('COALESCE(SUM(total), 0)')
            ->whereRaw('orders.user_id = users.id')
            ->where('status', 'completed'),
        'total_spent'
    )
    ->get();
// [['id' => 1, 'name' => 'John', 'order_count' => 5, 'total_spent' => 1250.00], ...]
```

### 11) Union queries

```php
$admins = db()->table('users')->select('id, name, email')->where('role', 'admin');
$editors = db()->table('users')->select('id, name, email')->where('role', 'editor');
$combined = $admins->unionAll($editors)->orderBy('name', 'ASC')->get();
```

### 12) Index hints for performance optimization

```php
// Force MySQL to use a specific index
$users = db()->table('users')
    ->forceIndex('idx_user_status_created')
    ->where('user_status', 1)
    ->whereDate('created_at', '>=', '2025-01-01')
    ->get();

// Ignore a problematic index
$data = db()->table('orders')
    ->ignoreIndex('idx_orders_total')
    ->where('status', 'pending')
    ->get();

// Suggest index usage
$data = db()->table('products')
    ->useIndex(['idx_category', 'idx_price'])
    ->where('category_id', 5)
    ->whereBetween('price', 10, 100)
    ->get();
```

### 13) whereHas — relationship existence filter

```php
// Get users who HAVE at least one order
$activeUsers = db()->table('users')
    ->whereHas('orders', 'user_id', 'id')
    ->get();

// Get users who have orders with total > 100
$bigSpenders = db()->table('users')
    ->whereHas('orders', 'user_id', 'id', function ($q) {
        $q->where('total', '>', 100);
    })
    ->get();

// Get users who DON'T have any orders
$inactiveUsers = db()->table('users')
    ->whereDoesntHave('orders', 'user_id', 'id')
    ->get();

// Combine with OR
$users = db()->table('users')
    ->whereHas('orders', 'user_id', 'id')
    ->orWhereHas('subscriptions', 'user_id', 'id')
    ->get();
```

### 14) sole() — exactly one record or exception

```php
// Fetch exactly one matching record — throws if 0 or 2+ found
try {
    $user = db()->table('users')
        ->where('email', 'john@example.com')
        ->sole();
    // Safe to use $user directly
} catch (\Exception $e) {
    // "No records found matching the query"
    // or "Multiple records found, expected only one"
}
```

### 15) firstOrCreate — find or insert

```php
$result = db()->table('tags')
    ->firstOrCreate(
        ['slug' => 'php-framework'],        // search conditions
        ['name' => 'PHP Framework']          // extra insert data
    );
// Result: ['code' => 200, 'action' => 'found', 'data' => [...]] if exists
// Result: ['code' => 201, 'action' => 'create', 'id' => 5] if created
```

### 16) Increment / decrement with extra columns

```php
// Increment view count and update last_viewed_at
db()->table('articles')
    ->where('id', 42)
    ->increment('view_count', 1, ['last_viewed_at' => date('Y-m-d H:i:s')]);

// Decrement stock
db()->table('products')
    ->where('sku', 'A001')
    ->decrement('stock', 5);
```

### 17) Return type transformers

```php
// Get results as objects instead of arrays
$users = db()->table('users')
    ->toObject()
    ->where('user_status', 1)
    ->get();
// Each row: $users[0]->name, $users[0]->email

// Get results as JSON string
$json = db()->table('users')
    ->toJson()
    ->where('user_status', 1)
    ->get();
// Returns JSON string: '[{"id":1,"name":"John",...},...]'
```

### 18) Raw query execution with query() + execute()

```php
// Raw SELECT
$results = db()->selectQuery(
    'SELECT u.*, COUNT(o.id) as order_count
     FROM users u
     LEFT JOIN orders o ON o.user_id = u.id
     WHERE u.user_status = ?
     GROUP BY u.id
     HAVING order_count > ?',
    [1, 5],          // bind values
    'get'            // 'get' for all rows, 'fetch' for first only
);

// Raw DML with query() + execute()
$result = db()->query(
    'UPDATE users SET last_login = NOW() WHERE id = ?',
    [42]
)->execute();
// Returns: ['code' => 200, 'affected_rows' => 1, 'message' => 'Data updated successfully', 'action' => 'update']

// Raw DDL
$result = db()->query(
    'ALTER TABLE users ADD COLUMN phone VARCHAR(20) DEFAULT NULL AFTER email'
)->execute();
// Returns: ['code' => 200, 'affected_rows' => 0, 'message' => 'Object altered successfully', 'action' => 'alter']
```

### 19) Dry run mode — inspect without executing

```php
$info = db()->table('users')
    ->dryRun()
    ->where('user_status', 1)
    ->whereNull('deleted_at')
    ->orderBy('id', 'DESC')
    ->get();
// Returns query + binds array without executing against DB
```

### 20) Multi-column where helpers

```php
// whereAny — any column matches (OR logic between columns)
$users = db()->table('users')
    ->whereAny(['name', 'email', 'phone'], 'LIKE', '%john%')
    ->get();
// SQL: WHERE (name LIKE '%john%' OR email LIKE '%john%' OR phone LIKE '%john%')

// whereAll — all columns match (AND logic between columns)
$users = db()->table('users')
    ->whereAll(['is_active', 'is_verified'], '=', 1)
    ->get();
// SQL: WHERE (is_active = 1 AND is_verified = 1)

// whereNone — no column matches
$users = db()->table('users')
    ->whereNone(['email', 'phone'], 'LIKE', '%spam%')
    ->get();
// SQL: WHERE NOT (email LIKE '%spam%' OR phone LIKE '%spam%')
```

### 21) Debug queries with toDebugSql

```php
$debug = db()->table('users')
    ->select('id, name')
    ->with('roles', 'user_roles', 'user_id', 'id')
    ->where('user_status', 1)
    ->toDebugSql();
// Returns:
// [
//   'main_query' => 'SELECT id, name FROM users WHERE user_status = 1',
//   'with_roles' => ['main_query' => 'SELECT * FROM user_roles WHERE user_id IN (...)']
// ]
```

### 22) DataTables server-side pagination

```php
// In controller, receiving DataTables AJAX request
$result = db()->table('users')
    ->select('id, name, email, user_status, created_at')
    ->leftJoin('roles', 'roles.id', 'users.role_id')
    ->whereNull('users.deleted_at')
    ->setPaginateFilterColumn(['name', 'email', 'roles.name'])
    ->paginate_ajax($request->all());
// Returns DataTables-compatible format:
// ['draw' => 1, 'recordsTotal' => 150, 'recordsFiltered' => 42, 'data' => [...]]
```

### 23) Chunked processing with limit awareness

```php
// Process all users in batches of 100
db()->table('users')
    ->whereNull('deleted_at')
    ->orderBy('id', 'ASC')
    ->chunk(100, function ($rows) {
        foreach ($rows as $row) {
            processUser($row);
        }
        // Return false to stop early:
        // if (someCondition()) return false;
    });

// Chunk respects limit() — process only first 500 in batches of 100
db()->table('users')
    ->whereNull('deleted_at')
    ->limit(500)
    ->chunk(100, function ($rows) {
        // Called 5 times max (500/100)
        exportBatch($rows);
    });
```

### 24) Connection management — multi-database

```php
// Add and use a secondary database connection
db()->addConnection('analytics', [
    'driver' => 'mysql',
    'host' => '10.0.0.5',
    'username' => 'readonly',
    'password' => 'secret',
    'database' => 'analytics_db',
    'port' => 3306,
    'charset' => 'utf8mb4',
]);

db()->setConnection('analytics');
$stats = db()->table('page_views')
    ->whereDate('viewed_at', '>=', '2025-01-01')
    ->count();

// Switch back to default
db()->setConnection('default');

// Check connection info
$version = db()->getVersion();    // '8.0.35'
$driver = db()->getDriver();      // 'mysql'
$platform = db()->getPlatform();  // 'MySQL'

// Disconnect and cleanup
db()->disconnect('analytics', true);  // true = also remove config
```

### 25) Performance optimization workflow

```php
// Enable profiling for debugging
db()->setProfilingEnabled(true);

// Enable query cache for read-heavy endpoints
db()->enableQueryCache(600); // 10 min TTL

$users = db()->table('users')->whereNull('deleted_at')->get();

// Get profiling data
$profile = db()->profiler();

// Get full performance report
$report = db()->getPerformanceReport(['slow_limit' => 5, 'recent_limit' => 5]);

// Disable when done
db()->disableQueryCache();
db()->setProfilingEnabled(false);

// Analyze table for optimizer stats
db()->table('users')->analyze(); // true on success

// Check column existence before operating
if (db()->table('users')->hasColumn('phone')) {
    // safe to query phone column
}
```

### 26) Safe output with exceptions

```php
// Encode all output HTML entities, EXCEPT 'body' and 'html_content' columns
$articles = db()->table('articles')
    ->safeOutputWithException(['body', 'html_content'])
    ->where('published', 1)
    ->get();
// All string columns are HTML-encoded, but 'body' and 'html_content' remain raw

// Basic safe output (encode everything)
$users = db()->table('users')
    ->safeOutput()
    ->where('user_status', 1)
    ->get();
```

### 27) Integer raw performance for large ID sets

```php
// When you have thousands of integer IDs — skip PDO parameter binding
$userIds = [1, 2, 3, 4, 5, /* ...thousands more... */];
$orders = db()->table('orders')
    ->whereIntegerInRaw('user_id', $userIds)
    ->get();
// Generates: WHERE user_id IN (1,2,3,4,5,...) without binding each value
// Faster than whereIn() for large integer arrays
```

### 28) Soft delete and firstOrFail

```php
// Soft delete a record
db()->table('users')
    ->where('id', 42)
    ->softDelete();
// Sets deleted_at = '2026-03-08 10:30:00' (current timestamp)

// Custom soft delete column
db()->table('posts')
    ->where('id', 99)
    ->softDelete('archived_at', '2026-01-01 00:00:00');

// firstOrFail — throws exception if no result
try {
    $user = db()->table('users')
        ->where('id', 999)
        ->whereNull('deleted_at')
        ->firstOrFail();
} catch (\Exception $e) {
    // Handle "No records found matching the query"
}
```

---

## How To Use

1. Always start with `db()->table('...')` to begin a query chain.
2. Use `when()` / `unless()` for conditional filters instead of PHP if-blocks around builders.
3. Use `safeOutput()` when returning data to the frontend.
4. Use `transaction()` for multi-table writes.
5. Use `cursor()` / `lazy()` for large dataset processing.
6. Use `with()` / `withCount()` for eager loading instead of N+1 queries.
7. Use `toRawSql()` / `dump()` / `toDebugSql()` to debug query issues.
8. Use `sole()` when you expect exactly one result (stricter than `fetch()`).
9. Use `upsert()` for bulk insert-or-update operations with thousands of rows.
10. Use `forceIndex()` / `useIndex()` when the MySQL optimizer picks a wrong index.
11. Use `whereHas()` to filter parent rows by related table conditions.
12. Use `dryRun()` during development to inspect generated SQL without executing.

## What To Avoid

- Avoid string-concatenated SQL with untrusted values; use builder methods or `whereRaw` with bindings.
- Avoid `get()` on unbounded queries; use `cursor()`, `lazy()`, `chunk()`, or `paginate()`.
- Avoid bypassing soft-delete convention when table uses `deleted_at`.
- Avoid calling `reset()` unless you intentionally want to reuse a builder instance.
- Avoid raw `query()` when a builder method exists for the same operation.
- Avoid calling `toArray()` on results already in array format (it sets return type, not converts).
- Avoid `whereIntegerInRaw()` with user-supplied non-integer data (no binding = no escaping).
- Avoid `selectQuery()` with non-SELECT statements (it throws `InvalidArgumentException`).

## Benefits

- Comprehensive fluent API covering SELECT, INSERT, UPDATE, DELETE, aggregate, batch, and streaming.
- Built-in eager loading (`with`, `withOne`, `withCount`, `withSum`, etc.) without ORM.
- Relationship existence queries (`whereHas`, `whereDoesntHave`) with callback support.
- Conditional query building (`when` / `unless`) for cleaner dynamic filters.
- Safety helpers (`safeInput`, `safeOutput`, `transaction`) built into the chain.
- Performance tools (query cache, profiler, performance report, index hints, dry run) for optimization.
- Multi-database connection support with dynamic switching.
- Bulk operations (`upsert`) optimized for large datasets with auto-chunking.
- Return type flexibility (`toArray`, `toObject`, `toJson`).

## Evidence

- `systems/Core/Database/BaseDatabase.php`
- `systems/Core/Database/Database.php`
- `systems/Core/Database/Drivers/MySQLDriver.php`
- `systems/Core/Database/Drivers/MariaDBDriver.php`
