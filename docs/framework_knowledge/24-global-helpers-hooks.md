# 24. Global Helpers & Hooks

## Overview

`systems/hooks.php` defines global helper functions available everywhere after bootstrap. Most provide singleton-style access to framework services without manual class instantiation, while `menu_manager()` intentionally returns a fresh menu manager so route-aware menu URLs are always resolved from the current request state.

Source: `systems/hooks.php` (~524 lines).

## Complete Helper Reference

### Configuration & Bootstrap

| Function | Signature | Return | Description |
|----------|-----------|--------|-------------|
| `getProjectBaseUrl` | `getProjectBaseUrl()` | `string` | Get project base URL from `$_SERVER`. Handles proxy, HTTPS, and subfolder detection. |
| `config` | `config(string $key, mixed $default = null)` | `mixed` | Read config values. Dot-notation supported: `config('database.host')`. Caches file reads. |
| `loadHelperFiles` | `loadHelperFiles()` | `void` | Load all app helper files from configured helper directories |
| `loadScopeMacroDBFunctions` | `loadScopeMacroDBFunctions($params, $filename = [], $foldername = [], $base_path = null, $silent = false)` | `void` | Load scope/macro definition files for query builder extension |
| `loadMiddlewaresFiles` | `loadMiddlewaresFiles(array $middlewares, $args = null)` | `void` | Load and execute middleware classes |

### Runtime Service Singletons

| Function | Signature | Return | Description |
|----------|-----------|--------|-------------|
| `debug` | `debug()` | `\Components\Debug` | Get Debug component instance (singleton) |
| `logger` | `logger()` | `\Components\Logger` | Get Logger component instance (singleton) |
| `request` | `request()` | `\Components\Request` | Get Request component instance (singleton) |
| `blade_engine` | `blade_engine()` | `\Core\View\BladeEngine` | Get Blade template engine instance (singleton) |
| `auth` | `auth()` | `\Components\Auth` | Get Auth component instance (singleton). Use `auth()->issueApiCredential(...)` to mint API tokens through the enabled-methods gate — see [03-auth-tokens-api.md](03-auth-tokens-api.md). |
| `redirect` | `redirect()` | `\Core\Http\Redirector` | Get Redirector; chain `->to()`, `->route()`, `->away()`, or `->back()` to build a `RedirectResponse`. |
| `menu_manager` | `menu_manager()` | `\Components\MenuManager` | Create a route-aware MenuManager for menu filtering, rendering, and authenticated landing resolution. Returns a fresh instance by design. |

### View Rendering

| Function | Signature | Return | Description |
|----------|-----------|--------|-------------|
| `view` | `view(string $view, array $data = [])` | `void` | Render view and **exit** (echo + die). For page responses. |
| `view_raw` | `view_raw(string $view, array $data = [])` | `string` | Render view and **return** as string. For emails, partials, etc. |

### Validation

| Function | Signature | Return | Description |
|----------|-----------|--------|-------------|
| `validator` | `validator(array $data = [], array $rules = [], array $customMessage = [])` | `\Components\Validation` | Create Validation instance. If `$data` and `$rules` provided, validates immediately. |

### CSRF Protection

| Function | Signature | Return | Description |
|----------|-----------|--------|-------------|
| `csrf` | `csrf()` | `\Components\CSRF` | Get CSRF component instance (singleton) |
| `csrf_field` | `csrf_field()` | `string` | Generate hidden input HTML: `<input type="hidden" name="_token" value="...">` |
| `csrf_value` | `csrf_value()` | `string` | Get current CSRF token value |

### Utility Facades

| Function | Signature | Return | Description |
|----------|-----------|--------|-------------|
| `collect` | `collect(array\|\Core\Collection $items = []): \Core\Collection` | `Collection` | Create a new Collection instance from array |
| `cache` | `cache(string\|array\|null $key = null, mixed $default = null): mixed` | `mixed` | Get CacheManager instance (no args), get cached value (string key), or batch set (array key) |
| `dispatch` | `dispatch(\Core\Queue\Job $job): ?string` | `?string` | Dispatch a job to the queue. Returns job ID or null. |

### Feature Flags

| Function | Signature | Return | Description |
|----------|-----------|--------|-------------|
| `feature` | `feature(?string $key = null, bool $default = false, array $context = [])` | `mixed` | Resolve the feature manager when called without a key, or return whether the given feature flag is enabled. |
| `featureFlag` | `featureFlag(?string $key = null, bool $default = false, array $context = [])` | `mixed` | Compatibility alias of `feature(...)`. Use when code reads more clearly as a boolean feature check in controllers/views. |
| `feature_value` | `feature_value(string $key, mixed $default = null, array $context = [])` | `mixed` | Read a feature flag variant/value when the flag definition exposes a `value`. |

---

## Helper Auto-loading

App helper files are loaded from configured paths at bootstrap via `loadHelperFiles()`. Default location: `app/helpers/`. Each file in the directory is auto-included.

Bootstrap/runtime constants available after `bootstrap.php`:

- `BASE_URL` — resolved from `APP_URL` first, then current request context.
- `APP_DIR` — application base path segment derived from `BASE_URL`.
- `APP_ENV` — current framework environment.
- `BOOTSTRAP_RUNTIME` — `web`, `api`, or `cli`.
- `BOOTSTRAP_SESSION_ENABLED` — whether bootstrap started PHP session for the current runtime.
- `BOOTSTRAP_STATEFUL_REQUEST` — whether the current request should be treated as stateful at bootstrap level.

## Examples

### 1) Config access with dot-notation

```php
// Read config values
$dbHost = config('database.host');          // from app/config/database.php
$appName = config('config.app_name');       // from app/config/config.php
$maxUpload = config('security.max_upload'); // from app/config/security.php

// Default value when key doesn't exist
$timeout = config('api.timeout', 30);
```

### 2) Auth service — check user, guard, permissions

```php
// Get current authenticated user
$user = auth()->user();

// Check if logged in
if (auth()->check()) {
    $name = auth()->user()['name'];
}

// Check permission
if (auth()->can('manage_users')) {
    // show admin panel
}

// Route definitions also support Laravel-like permission aliases
$router->get('/admin/users', [UserController::class, 'index'])
    ->webAuth()
    ->can('user-view');

// Feature flag checks are available in runtime code too
if (featureFlag('uploads.image-cropper')) {
    // render cropper affordance
}

// Logout
auth()->logout();
```

### 3) Request service — accessing HTTP request data

```php
// Get input data
$name = request()->input('name');
$all = request()->all();

// Check method
if (request()->isMethod('POST')) { ... }

// Get client IP
$ip = request()->ip();

// Check if AJAX/API request
if (request()->isAjax()) { ... }
if (request()->isApi()) { ... }

// Get bearer token
$token = request()->bearerToken();
```

### 4) View rendering

```php
// Render page view (outputs and exits)
view('dashboard/admin', [
    'title' => 'Dashboard',
    'users' => $users,
    'stats' => $stats,
]);

// Render view as string (for emails, partials)
$emailHtml = view_raw('emails/welcome', [
    'name' => $user['name'],
    'activationLink' => $link,
]);
```

### 5) Validation — standalone usage

```php
$v = validator($request->all(), [
    'name' => 'required|string|max:100',
    'email' => 'required|email',
    'age' => 'required|integer|min:18',
], [
    'name.required' => 'Please enter your name',
    'email.email' => 'Invalid email format',
]);

if ($v->fails()) {
    $errors = $v->errors();
    // ['name' => ['Please enter your name'], ...]
}
```

### 6) CSRF protection in forms

```php
// In a Blade view
<form method="POST" action="/profile/update">
    <?= csrf_field() ?>
    <!-- outputs: <input type="hidden" name="_token" value="abc123..."> -->
    
    <input type="text" name="name" value="<?= $user['name'] ?>">
    <button type="submit">Update</button>
</form>

// Manual token access
$token = csrf_value();
```

### 7) Collection creation and pipeline

```php
$users = db()->table('users')->whereNull('deleted_at')->get();

$adminEmails = collect($users)
    ->where('role', 'admin')
    ->whereNotNull('email')
    ->pluck('email')
    ->all();
// ['admin1@example.com', 'admin2@example.com']
```

### 8) Cache — get, set, remember pattern

```php
// Simple get/set
cache()->put('api.rate_count', 0, 60);  // 60 seconds TTL
$count = cache()->get('api.rate_count', 0);

// Remember pattern — fetch from cache or compute + store
$stats = cache()->remember('dashboard.stats', 300, function () {
    return [
        'users' => db()->table('users')->count(),
        'orders' => db()->table('orders')->whereDate('created_at', date('Y-m-d'))->count(),
    ];
});

// Short syntax — get value directly
$value = cache('some_key', 'default');

// Batch set
cache(['key1' => 'value1', 'key2' => 'value2']);
```

### 9) Queue dispatch

```php
// Dispatch a job to process later
dispatch(
    (new \App\Jobs\SendWelcomeEmail($userId))
        ->onQueue('emails')
        ->delay(10)
);

// Dispatch without delay (sync driver executes immediately)
dispatch(new \App\Jobs\ProcessReport($reportId));
```

### 10) Debug and logger

```php
// Debug component — detailed output
debug()->dump($variable);   // Print variable info
debug()->dd($variable);     // Dump and die

// Logger — write to log files
logger()->info('User logged in', ['user_id' => $userId]);
logger()->error('Payment failed', ['order_id' => $orderId, 'error' => $e->getMessage()]);
logger()->warning('Rate limit approaching', ['ip' => request()->ip()]);
```

### 11) Blade engine — direct access

```php
$engine = blade_engine();
$rendered = $engine->render('emails.welcome', ['name' => 'John']);
```

## How To Use

1. Use helpers for consistent access to shared framework services.
2. Use `config()` with dot-notation instead of hardcoded paths or manual file reads.
3. Use `view()` for page responses (it exits), `view_raw()` for string output (emails, partials).
4. Use `validator()` for standalone validation outside FormRequest.
5. Use `cache()->remember()` for expensive queries.
6. Use `dispatch()` to move heavy operations to background queue.
7. Use `menu_manager()` when a redirect or view depends on the current menu tree, permissions, states, or renderer profile.
8. Keep helper usage thin in controllers; move heavy domain logic into services.

## What To Avoid

- Avoid redefining helpers that already exist in `systems/hooks.php` (causes fatal redeclaration errors).
- Avoid calling `view()` when you need the output as a string (use `view_raw()` instead).
- Avoid side-effects in config callbacks.
- Avoid using `debug()->dd()` in production code.

## Benefits

- Zero boilerplate service access anywhere in the codebase.
- Consistent singleton patterns — no duplicate instantiation.
- Familiar Laravel-like API for rapid development.
- Clean separation between page rendering (`view`) and string rendering (`view_raw`).

## Evidence

- `systems/hooks.php`
- `bootstrap.php`
- `app/config/framework.php`
