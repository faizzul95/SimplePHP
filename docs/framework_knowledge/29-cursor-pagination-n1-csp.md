# 29. Cursor Pagination, CSV Export, N+1 Detection & CSP Nonce

## 1. Cursor Pagination (`cursorPaginate`)

### Why Keyset Pagination

Standard `LIMIT n OFFSET y` forces MySQL to scan and discard the first `y` rows.
At page 50,000 (depth ~1M) with 20 rows/page, MySQL scans ~1,000,000 rows and returns 20.
Time degrades linearly: O(n) with OFFSET depth.

Keyset pagination uses `WHERE id > :last_id ORDER BY id LIMIT n`:
MySQL seeks directly to `last_id` via the primary key B-tree — O(1) at any depth.

### Method Signature

```php
// systems/Core/Database/Concerns/HasStreaming.php
public function cursorPaginate(
    int $perPage = 15,
    string $column = 'id',
    ?string $cursorToken = null
): array
```

- `$perPage` — rows per page (capped at `MAX_PAGINATE_LIMIT = 500`)
- `$column` — indexed column to paginate on (must be orderable, ideally the PK)
- `$cursorToken` — base64url-encoded cursor from previous response; `null` for first page

### Return Shape

```php
[
    'data'        => array,     // page rows (plain array)
    'per_page'    => int,       // configured page size
    'has_more'    => bool,      // true when another page follows
    'next_cursor' => ?string,   // pass as $cursorToken for next page; null on last page
    'prev_cursor' => ?string,   // pass for previous page; null on first page
]
```

### Usage

```php
// Controller
$page   = db()->table('users')
    ->where('status', 1)
    ->cursorPaginate(20, 'id', request()->input('cursor'));

// API response
return response()->json([
    'data'     => $page['data'],
    'meta'     => [
        'per_page'    => $page['per_page'],
        'has_more'    => $page['has_more'],
        'next_cursor' => $page['next_cursor'],
        'prev_cursor' => $page['prev_cursor'],
    ],
]);
```

```javascript
// Frontend
const res  = await callApi('get', `/api/v1/users?cursor=${nextCursor}`);
const next = res.meta.next_cursor;  // store for next page button
```

### Cursor Token Format

Cursors are base64url-encoded JSON objects (no padding):

- Forward: `base64url({"after": <last_id>})`  → `WHERE id > last_id ORDER BY id ASC`
- Backward: `base64url({"before": <first_id>})` → `WHERE id < first_id ORDER BY id DESC` (results reversed in PHP)

### Limitations

- Only works correctly on **unique, orderable columns** (primary keys, UUIDs, timestamps with tie-break).
- Cannot jump to an arbitrary page number — only forward/backward navigation.
- Does not support non-indexed columns without adding an index first.

---

## 2. CSV Streaming Export (`exportCsv`)

### Method Signature

```php
// systems/Core/Database/Concerns/HasStreaming.php
public function exportCsv(
    string   $filename,
    array    $columns  = [],    // column keys to include; empty = all from first row
    int      $chunkSize = 500   // rows per DB round-trip
): void
```

### Behavior

- Sends `Content-Type: text/csv; charset=UTF-8` + `Content-Disposition: attachment` headers before output.
- Writes a UTF-8 BOM (`\xEF\xBB\xBF`) so Excel auto-detects encoding.
- Sanitizes `$filename`: `/[^A-Za-z0-9_\-.]/ → '_'`; appends `.csv` when missing.
- Automatically uses `chunkById()` (keyset, O(1) per chunk) when the query shape is eligible.
- Falls back to `chunk()` (OFFSET-based) for non-keyset-eligible queries.
- Calls `gc_collect_cycles()` per chunk when `$chunkSize >= 500`.

### Usage

```php
// Stream 1M rows directly to browser — peak memory ≈ 1 chunk × row size
db()->table('orders')
    ->where('status', 'completed')
    ->whereBetween('created_at', [$from, $to])
    ->exportCsv('completed-orders.csv', ['id', 'customer', 'total', 'created_at']);

// All columns (derived from first row):
db()->table('users')->exportCsv('users.csv');

// Custom chunk size for very wide rows:
db()->table('products')->exportCsv('products.csv', [], 200);
```

### Queue Export Pattern

For very large exports (>500K rows) triggered from a user action, offload to a job:

```php
// In controller: dispatch job, return job ID
dispatch(new ExportOrdersJob($filters, auth()->id()));
return ['code' => 202, 'message' => 'Export queued'];

// In ExportOrdersJob::handle():
db()->table('orders')->where($filters)->exportCsv(...);
// Or write to storage: use chunk() + fputcsv() into a Storage::put() stream
```

---

## 3. N+1 Query Detector (`PerformanceMonitor`)

### What Is N+1

Loading a list of `n` users and then running a separate query per user = `1 + n` queries.
At n=1000 with 6 related tables: `1 + 1000×6 = 6,001 queries` per request.

### How Detection Works

`PerformanceMonitor::trackQueryFingerprint()` normalizes each SELECT SQL (collapse whitespace,
lowercase, md5) and counts repetitions per request.
When the same pattern fires ≥ `$n1WarnThreshold` times, a warning is emitted **once** to the log.

### Activation

| Condition | N+1 detection active? |
|-----------|----------------------|
| `APP_DEBUG=true` in `.env` | ✅ Auto-enabled via `Database::__construct()` |
| `$db->setProfilingEnabled(true)` | ✅ Enabled as part of full profiling |
| Production (`APP_DEBUG=false`, profiling off) | ❌ Off (zero overhead) |

### API

```php
use Core\Database\PerformanceMonitor;

// Tune sensitivity (default: 30)
PerformanceMonitor::setN1WarnThreshold(10);

// Enable/disable explicitly
PerformanceMonitor::setN1DetectionEnabled(true);

// Retrieve suspect patterns (e.g., for a debug bar)
$suspects = PerformanceMonitor::getN1Suspects();
// Returns: [['sql' => 'SELECT * FROM orders WHERE user_id = ?', 'count' => 45], ...]

// Reset per-request state (call at start of each request in long-running workers)
PerformanceMonitor::reset();
```

### Log Format

```
[N+1 DETECTED] Query pattern executed 30 times in one request. SQL: SELECT * FROM orders WHERE user_id = ?...
```

### Fix: Use Eager Loading

```php
// ❌ N+1 — 1 + n queries
$users = db()->table('users')->get();
foreach ($users as $user) {
    $user['orders'] = db()->table('orders')->where('user_id', $user['id'])->get();
}

// ✅ Eager loading — 2 queries total
$users = db()->table('users')
    ->with(['orders' => ['table' => 'orders', 'foreign_key' => 'user_id', 'local_key' => 'id']])
    ->get();
```

---

## 4. CSP Nonce (`Core\Security\CspNonce`)

### What Is a CSP Nonce

A Content Security Policy nonce is a per-request random value included in the `Content-Security-Policy` header and on every `<script>` and `<style>` tag the server renders. Browsers execute only scripts/styles whose nonce matches the header value, even if `'unsafe-inline'` is removed from the policy.

### Architecture

```
CspNonce::get()                      ← single source of truth
    ↑                ↑
SecurityHeadersTrait::getNonce()    BladeEngine shared view data ($csp_nonce)
    ↑                ↑
CSP header emission           Blade @nonce directive
```

`Core\Security\CspNonce` holds one base64-encoded `random_bytes(16)` value per request.
Both the security-headers middleware and the Blade engine read from the same class so header
and template values always match.

### Configuration

```php
// app/config/security.php
'csp' => [
    'enabled'       => true,
    'nonce_enabled' => true,   // ← enable for production; false = backward-compat default

    // 'unsafe-inline' is automatically removed from script-src and style-src when nonce_enabled
    'script-src' => ["'self'", "'unsafe-inline'", 'https://cdn.jsdelivr.net'],
    'style-src'  => ["'self'", "'unsafe-inline'", 'https://fonts.googleapis.com'],
],
```

### Template Usage

```html
{{-- @nonce outputs the full nonce="value" attribute --}}
<script @nonce src="/app.js"></script>
<style @nonce>body { margin: 0; }</style>

{{-- Or use the shared variable directly --}}
<script nonce="{{ $csp_nonce }}">
    const config = @json($appConfig);
</script>
```

### Long-Running Workers

In Swoole/RoadRunner/ReactPHP workers, reset the nonce between requests:

```php
// In your request lifecycle hook
\Core\Security\CspNonce::reset();
```

Also call `SecurityHeadersTrait::resetNonce()` (delegates to the same class).

### CSP Header Example (nonce_enabled=true)

```
Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-abc123==' https://cdn.jsdelivr.net; style-src 'self' 'nonce-abc123=='; img-src 'self' data:; ...
```

Note: `'unsafe-inline'` is automatically stripped from `script-src` and `style-src` when `nonce_enabled` is true.
