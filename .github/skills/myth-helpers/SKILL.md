---
name: myth-helpers
description: >
  Global helpers, Collection/LazyCollection API, feature flags, event dispatcher,
  redirect helper, request helper, and utility facades guide for MythPHP. Use when:
  using collect(), cache(), dispatch(), feature(), featureFlag(), feature_value(),
  auth(), redirect(), request(), logger(), config(), csrf(), view(), view_raw(),
  working with Collection methods (filter, map, pluck, groupBy, sortBy, chunk, reduce),
  LazyCollection for large datasets, or wiring up event listeners and dispatching events.
argument-hint: 'Helper, e.g. "collect()", "feature flags", "redirect", "event dispatcher", "cache facade"'
---

# MythPHP Helpers & Utilities Guide

## When to Use This Skill

Load this skill for anything involving global helper functions, the Collection/LazyCollection
API, feature flags, event dispatching, or utility facades. All functions are available
globally after bootstrap — no imports needed.

---

## 1. Global Helper Reference

Reference: [24-global-helpers-hooks.md](../../../docs/framework_knowledge/24-global-helpers-hooks.md)

All defined in `systems/hooks.php`. Available everywhere after bootstrap.

### Configuration & Bootstrap

```php
config('database.host')             // Dot-notation config read (cached)
config('cache.default', 'file')     // With default value
getProjectBaseUrl()                 // Base URL (proxy-aware, subfolder-safe)
```

### Service Singletons

```php
auth()          // \Components\Auth / AuthManager — auth state, sessions, tokens
request()       // \Components\Request — current HTTP request
logger()        // \Components\Logger — writes to logs/logger.log
debug()         // \Components\Debug — debug utilities
blade_engine()  // \Core\View\BladeEngine — Blade template engine
redirect()      // \Core\Http\Redirector — build redirect responses
menu_manager()  // \Components\MenuManager — route-aware menus (fresh instance each call)
```

### View Rendering

```php
view('directory.users', ['title' => 'Users']);   // Render + exit
$html = view_raw('emails.welcome', ['user' => $user]);  // Render to string
```

### Validation

```php
$v = validator(['email' => $email], ['email' => 'required|email']);
if (!$v->passed()) {
    // $v->errors() — array of field => messages
}
```

### CSRF

```php
csrf_field()    // <input type="hidden" name="_token" value="...">
csrf_value()    // Just the token string (for JS/AJAX)
csrf()          // CSRF component instance
```

### Cache Facade

```php
cache()                         // Returns CacheManager instance
cache('key', 'default')         // Get cached value
cache(['key' => 'value'], 300)  // Batch put with TTL

// Full API via instance
cache()->remember('users.all', 300, fn() => db()->table('users')->get());
cache()->put('key', $value, 600);
cache()->get('key', 'default');
cache()->forget('key');
cache()->store('redis')->get('key');   // Specific store
```

### Queue Dispatch

```php
dispatch(new SendWelcomeEmail($userId));
dispatch((new SendWelcomeEmail($userId))->delay(300)->onQueue('emails'));
```

### Schema Helper

```php
schema()   // Returns \Core\Database\Schema\Schema FQCN for DDL operations
```

---

## 2. Feature Flags

Reference: [24-global-helpers-hooks.md](../../../docs/framework_knowledge/24-global-helpers-hooks.md)

```php
// Check if feature is enabled (boolean)
if (feature('payments.stripe')) {
    // Stripe integration
}

// Same — alias for readability in boolean contexts
if (featureFlag('uploads.image-cropper')) {
    // show upload button
}

// Read a feature variant/value
$limit = feature_value('uploads.max_size', 4);   // Returns 4 if not set

// Route-level feature gate
$router->get('/beta', [BetaController::class, 'index'])
    ->webAuth()
    ->featureFlag('beta.new-ui');

// Blade directive
@if(featureFlag('reports.export'))
    <button>Export</button>
@endif
```

---

## 3. Collection API (`collect()`)

Reference: [21-collection-core-collection.md](../../../docs/framework_knowledge/21-collection-core-collection.md)

Entry point: `collect(array $items)` — returns `Core\Collection`.

```php
$users = collect(db()->table('users')->whereNull('deleted_at')->get());

// Transform
$active  = $users->filter(fn($u) => $u['user_status'] === 1);
$names   = $active->pluck('name');
$sorted  = $active->sortBy('name');
$groups  = $active->groupBy('user_gender');
$mapped  = $active->map(fn($u) => ['id' => $u['id'], 'label' => $u['name']]);

// Aggregate
$count   = $active->count();
$first   = $active->first();
$total   = collect($orders)->sum('amount');
$avg     = collect($scores)->avg('value');

// Query-like filtering
$admins  = $users->where('role', 'admin');
$ids     = $users->whereIn('user_status', [0, 2])->pluck('id');

// Reduce / pipe
$total = collect($items)->reduce(fn($carry, $item) => $carry + $item['price'], 0);

// Partition (split into two collections)
[$active, $inactive] = $users->partition(fn($u) => $u['user_status'] === 1);

// Unique / deduplicate
$unique = $users->unique('email');

// Convert
$json   = $users->toJson();
$array  = $users->toArray();
```

### Key Collection methods

| Method | Description |
|--------|-------------|
| `filter($cb)` | Keep items matching callback |
| `reject($cb)` | Remove items matching callback |
| `map($cb)` | Transform each item |
| `flatMap($cb)` | Map + flatten one level |
| `pluck($key)` | Extract single column |
| `groupBy($key)` | Group into nested collection by key |
| `sortBy($key)` | Sort ascending by key |
| `sortByDesc($key)` | Sort descending by key |
| `chunk($size)` | Split into chunks of size |
| `collapse()` | Flatten one level |
| `flatten()` | Flatten all levels |
| `unique($key?)` | Remove duplicates |
| `first($cb?)` | First item or first match |
| `last($cb?)` | Last item or last match |
| `sum($key?)` | Sum a column |
| `avg($key?)` | Average a column |
| `min($key?)` | Min value |
| `max($key?)` | Max value |
| `count()` | Item count |
| `isEmpty()` | True if empty |
| `contains($key, $val?)` | Check existence |
| `each($cb)` | Iterate with side effects |
| `partition($cb)` | Split into two collections |
| `reduce($cb, $initial)` | Reduce to single value |
| `pipe($cb)` | Pass collection to callback |
| `tap($cb)` | Inspect without mutating |
| `when($cond, $cb)` | Conditional transform |
| `only($keys)` | Keep only given keys |
| `except($keys)` | Remove given keys |
| `dot($key)` | Dot-notation access |
| `toArray()` | Convert to array |
| `toJson()` | JSON encode |

---

## 4. LazyCollection (`lazyCollect()`)

Reference: [22-lazy-collection-core-lazycollection.md](../../../docs/framework_knowledge/22-lazy-collection-core-lazycollection.md)

Use `LazyCollection` for large datasets that should not be fully loaded into memory.

```php
// Process a large table without loading all rows
$lazy = lazyCollect(function () {
    return db()->table('orders')->cursor();   // generator
});

$lazy->filter(fn($o) => $o['status'] === 'pending')
     ->each(fn($o) => processOrder($o));

// Chunk processing
$lazy->chunk(500)->each(function ($chunk) {
    // process 500 rows at a time
});
```

---

## 5. Event Dispatcher

Reference: [24-global-helpers-hooks.md](../../../docs/framework_knowledge/24-global-helpers-hooks.md) · `app/providers/EventServiceProvider.php`

```php
// Register listener (in EventServiceProvider or AppServiceProvider)
event()->listen('user.created', function (array $payload) {
    logger()->info('New user: ' . $payload['email']);
});

// Dispatch an event anywhere
event()->dispatch('user.created', ['id' => $userId, 'email' => $email]);

// Alternatively via the app event dispatcher
app_event('user.created', ['id' => $userId]);
```

Register persistent listeners in `app/providers/EventServiceProvider.php`:

```php
class EventServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        event()->listen('user.created', [UserCreatedListener::class, 'handle']);
        event()->listen('order.placed', fn($data) => dispatch(new SendOrderEmail($data)));
    }
}
```

---

## 6. Redirect Helper

Reference: [14-request-response-details.md](../../../docs/framework_knowledge/14-request-response-details.md)

```php
redirect()->to('/dashboard');               // Internal redirect
redirect()->route('dashboard');             // Named route redirect
redirect()->back('/login');                 // Referrer-aware with fallback
redirect()->away('https://trusted.com');    // External (must be in security.redirects.allowed_hosts)
```

Flash data before redirecting:

```php
session()->flash('success', 'User saved');
redirect()->to('/users');
```

---

## 7. Request Helper

```php
request()->input('name')           // GET + POST merged input
request()->query('page', 1)        // GET only
request()->all()                   // All input
request()->method()                // 'GET', 'POST', etc.
request()->ip()                    // Client IP (proxy-aware)
request()->bearerToken()           // Bearer token from Authorization header
request()->header('X-Custom', '')  // Any header (case-insensitive)
request()->expectsJson()           // True for AJAX/API requests
request()->isJson()                // Content-Type is JSON
```

---

## 8. Logger

```php
logger()->info('User logged in', ['user_id' => $userId]);
logger()->warning('Rate limit approaching', ['ip' => $ip]);
logger()->error('Payment failed', ['order_id' => $orderId, 'error' => $e->getMessage()]);
logger()->debug('Query debug', ['sql' => $sql]);  // Only emits in debug mode
```

Writes to `logs/logger.log` by default. Use `logger()->channel('audit')` for separate audit log files when configured.

---

## 9. Helpers Checklist

- [ ] Use `config()` not direct `$_ENV` or `getenv()` for configuration reads.
- [ ] Use `cache()->remember()` for expensive computations — never manually get+set in race-prone code.
- [ ] Feature flags checked via `feature()` / `featureFlag()` — never hardcoded env checks for staged rollouts.
- [ ] Events dispatched for cross-cutting side effects (audit logs, emails, cache invalidation) — never inline the logic in controllers.
- [ ] `redirect()->away()` only used with explicitly allowed hosts configured in `security.redirects.allowed_hosts`.
- [ ] `logger()->error()` called on all caught exceptions with enough context to debug.
- [ ] `collect()` used for array manipulation instead of manual `array_*` chains.
- [ ] `lazyCollect()` / `cursor()` used for datasets > 1K rows to avoid memory exhaustion.
