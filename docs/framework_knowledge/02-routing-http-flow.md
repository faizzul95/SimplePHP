# 02. Routing & HTTP Flow

## Structure

Routing in this framework is composed of these layers:

1. `index.php` captures request and passes it to `App\Http\Kernel`.
2. `App\Http\Kernel` builds Router + loads aliases/groups from `framework.php`.
3. `RouteServiceProvider` loads both `app/routes/web.php` and `app/routes/api.php`.
4. `Router::dispatch()` finds route, runs middleware pipeline, executes action, and handles errors.

This means route behavior is both code-driven (Router) and config-driven (`app/config/framework.php`).

## Supported Route Registration

- HTTP verbs: `get`, `post`, `put`, `patch`, `delete`, `options`
- Resource routes: `resource`, `apiResource` (actions: `index`, `store`, `show`, `update`, `destroy`)
- Multi-verb helpers: `match([...])`, `any(...)` (`any` includes `OPTIONS`)
- Convenience routes: `redirect(...)`, `view(...)`
- Groups: nested `group(['prefix' => ..., 'middleware' => [...]])`
- Fallback route registration via `fallback(...)`

## Examples

### 1) Basic route + name + middleware

```php
$router->get('/dashboard', [DashboardController::class, 'index'])
	->webAuth()
	->can('management-view')
	->name('dashboard');
```

RouteDefinition convenience helpers now include:
- `auth(...)`
- `webAuth()`
- `apiAuth()`
- `guestOnly()`
- `featureFlag(...)`
- `feature(...)` as an alias of `featureFlag(...)`
- `permission(...)`
- `permissionAny(...)`
- `can(...)` as an alias of `permission(...)`
- `canAny(...)` as an alias of `permissionAny(...)`
- `role(...)`
- `ability(...)`

Feature-gated route example:

```php
$router->post('/uploads/image-cropper', [UploadController::class, 'uploadImageCropper'])
	->permission('user-upload-profile')
	->middleware('api.upload.image')
	->middleware('xss:image')
	->featureFlag('uploads.image-cropper')
	->name('uploads.image-cropper');
```

### 2) Grouped routes with shared middleware and prefix

```php
$router->group(['prefix' => '/api/v1', 'middleware' => ['auth.api', 'xss']], function ($router) {
	$router->get('/auth/me', [AuthController::class, 'me'])->name('api.auth.me');
	$router->post('/auth/logout', [AuthController::class, 'logout'])->name('api.auth.logout');
});
```

### 3) Explicit API action route

```php
$router->get('/auth/me', [AuthController::class, 'me']);
$router->post('/auth/logout', [AuthController::class, 'logout']);
```

This shared-controller pattern maps to:
- `GET /auth/me` -> `me`
- `POST /auth/logout` -> `logout`

The framework can still use resource routes, but this project now keeps web page actions and API actions inside the same controller class instead of splitting them under a dedicated `Controllers\Api` namespace.

### 4) Global parameter constraints — `Router::pattern()`

Three patterns are pre-seeded at boot and apply automatically to every matching route parameter when no per-route `->where()` override is set:

| Parameter | Default pattern | Notes |
|-----------|----------------|-------|
| `{id}`   | `[0-9]+` | Digits-only; blocks path traversal and type confusion |
| `{uuid}` | UUID v1–v5 regex | Case-insensitive hex; rejects malformed values |
| `{slug}` | `[a-z0-9][a-z0-9-]*` | Must start with alphanum; hyphens allowed |

Register additional patterns at bootstrap (e.g. `RouteServiceProvider::map()` or `app/http/Kernel.php`):

```php
Router::pattern('locale', '[a-z]{2}');
Router::pattern(['year' => '[0-9]{4}', 'month' => '0[1-9]|1[0-2]']);

// Read all registered patterns (for testing / debug)
$patterns = Router::getPatterns();
```

Priority (highest wins): per-route `->where()` → `Router::pattern()` global → built-in fallback `[A-Za-z0-9_-]+`

Every route declaring `{id}` is digit-only by default with zero per-route boilerplate.

### 5) Per-route parameter constraints (`where`)

```php
$router->get('/users/{id}', [UserController::class, 'show'])
	->whereNumber('id');   // explicit override — still works; takes priority over global

$router->get('/posts/{slug}', [PostController::class, 'show'])
	->where('slug', '[a-z0-9-]+');
```

### 5) Middleware parameters

```php
$router->post('/login', [AuthController::class, 'authorize'])
	->middleware('throttle:auth')
	->middleware('xss:password,remember_me');

$router->post('/users/save', [UserController::class, 'save'])
	->middleware('xss')
	->canAny(['user-create', 'user-update']);
```

## Route Matching Behavior

- Static routes are indexed as `METHOD:/path` for fast lookup.
- Dynamic routes are compiled into cached regex patterns.
- Required and optional parameters are supported (`{id}`, `{id?}`).
- `HEAD` is normalized to `GET` route lookup.

## Named Routes

- Route names are registered immediately when `RouteDefinition::name(...)` is called.
- URL resolution uses named route map (`urlFor` / helper layer).
- Required placeholders must be provided, otherwise URL resolution returns `null`.
- Optional placeholders are included when provided and omitted when missing.
- Unmatched extra params are appended as query string.

Practical implication:

- `route('dashboard')` can be used during view rendering, menu rendering, middleware redirects, or login-response construction without waiting for `Router::dispatch()` to finish.

Practical usage:

```php
$router->get('/login', [AuthController::class, 'showLogin'])->name('login');
// Later: route('login')
```

## Redirect Helper Behavior

- `Router::redirect()` now normalizes targets before sending the response.
- Relative redirect targets are resolved through the app URL helper, so subfolder deployments keep redirects inside the app base path.
- Absolute redirect targets are only preserved when they point to the current host; malformed or external targets are reduced to a safe fallback.
- Unsafe schemes and scheme-relative targets are rejected by the shared response redirect sanitizer.

### `redirect()` helper — `Redirector` + `RedirectResponse`

Controllers should use the `redirect()` helper (backed by `Core\Http\Redirector`) rather than echoing `Location:` headers manually. Each call returns a `RedirectResponse`.

```php
// Internal path (sanitized, subfolder-aware)
return redirect()->to('/dashboard');

// Named route
return redirect()->route('users.show', ['id' => $id]);

// External URL (explicitly allowed; still passes the redirect sanitizer)
return redirect()->away('https://partner.example.com/callback');

// Back to referrer, with a safe fallback
return redirect()->back('/dashboard');
```

`to()` and `back()` normalize against the app base URL and reject cross-host or unsafe-scheme targets; `away()` is the only form that permits a different host, and it still runs through `Response::sanitizeRedirectTarget()`.

For the full `RedirectResponse` surface (flash inputs, `with()`, `withErrors()`, status codes), see [14-request-response-details.md](14-request-response-details.md#redirector--redirectresponse). This page stays focused on routing behavior; do not duplicate response details here.

## Error & Not Found Flow

- `OPTIONS` preflight requests auto-return `204` + `Allow` when URI exists for other methods.
- 405 handling is automatic when URI exists for other methods.
- 404 handling distinguishes JSON vs browser requests.
- Browser no-match redirect uses `framework.not_found_redirect.web` (default `login`).
- HTML error pages are read from `framework.error_views`.
- Error views may be configured either by dot notation or direct file path; direct paths are resolved before dot-style lookup.

Config example (`app/config/framework.php`):

```php
'error_views' => [
	'404' => 'app/views/errors/404.php',
	'general' => 'app/views/errors/general_error.php',
	'error_image' => 'general/images/nodata/403.png',
],
'not_found_redirect' => ['web' => 'login'],
```

## Middleware Resolution in Router

- Aliases come from `framework.middleware_aliases`.
- Groups come from `framework.middleware_groups` and are expanded recursively.
- Parameters are passed to middleware through `setParameters(...)`.
- Middleware instances are cached by middleware signature for efficiency.
- Groups are not applied automatically based on route file. A route inside `app/routes/web.php` only gets the `web` group if you explicitly attach `web` to the route or its parent group.

Practical implication:

```php
$router->group(['middleware' => ['web']], function ($router) {
	$router->post('/auth/login', [AuthController::class, 'authorize']);
	$router->post('/modal/content', fn () => null);
});
```

Without the explicit `web` middleware, browser POST routes will miss the configured CSRF middleware.

## Request Format Detection

`Request::expectsJson()` is true when:
- path starts with `api/`, or
- `Accept` contains JSON, or
- `X-Requested-With: XMLHttpRequest`.

This directly controls whether Router returns JSON errors or HTML/redirect behavior.

## How To Use (Implementation Checklist)

1. Define route in `web.php` (pages) or `api.php` (data endpoints).
2. Attach middleware alias/group intentionally.
3. Add route name if frontend/helper resolution needs it.
4. For dynamic params, `{id}`, `{uuid}`, and `{slug}` are constrained automatically by global patterns — no `->where()` needed for those. For other param names, add explicit `->where()` or register via `Router::pattern()`.
5. Test these cases: success, unauthorized, invalid method (405), unknown route (404/redirect).

## Route Cache (Production Optimisation)

The router supports pre-compiled route caching to skip parsing `web.php` and `api.php` on every request.

### How it works

1. `php myth route:cache` — builds the route index (static + dynamic + named) and writes it to `storage/cache/routes.cache.php` as a PHP `return` array.
2. On the next request, `RouteServiceProvider::map()` detects the cache file and calls `Router::loadFromCache()` instead of requiring route files — the full route index is restored in a single `include`.
3. `php myth route:clear` — deletes the cache file and restores normal route-file loading.

### Important constraints

- **Closure-based routes are skipped** — closures cannot be serialised. A warning is printed for each skipped route. Convert them to `[ControllerClass::class, 'method']` to include them.
- **Always regenerate after route changes** — re-run `php myth route:cache` after adding, removing, or modifying routes. The cache is never auto-updated.
- **Middleware groups are preserved** — the cache command uses `RouteServiceProvider::mapWeb()/mapApi()` internally, so `web`/`api` middleware groups are applied identically to a real request.
- **405 detection works normally** — `$registeredMethods` is rebuilt from the cache, so wrong-method requests still return proper `405 Method Not Allowed` responses.
- **OPcache is invalidated** — `opcache_invalidate()` is called after writing so all worker processes pick up the new file immediately.

### Practical workflow

```bash
# After deploying updated routes:
php myth route:clear          # (optional — cache:cache already clears stale file)
php myth route:cache          # compile fresh cache from route files
php myth route:list           # verify routes look correct
```

### Cache file internals

`storage/cache/routes.cache.php` returns an array with keys:

| Key | Content |
|-----|---------|
| `static` | Indexed `METHOD:/path` → route row (method, uri, action, middleware, name, wheres) |
| `dynamic` | Indexed by HTTP method → array of route rows including pre-compiled `regex` |
| `named` | Flat name → URI map |
| `patterns` | Global `Router::pattern()` constraints |
| `skipped` | Count of closure routes that could not be cached |
| `generated_at` | Unix timestamp of cache creation |

## What To Avoid

- Avoid adding API JSON endpoints in `web.php`.
- Avoid unbounded route params when numeric/slug constraints are known. Register a global pattern via `Router::pattern()` rather than repeating `->where()` on every route.
- Avoid calling `Router::pattern()` after `Router::dispatch()` has started — global patterns are baked into compiled regex at index-build time.
- Avoid assuming browser and AJAX unmatched routes behave the same.
- Avoid middleware aliases not registered in `framework.middleware_aliases`.

## Benefits

- Clear separation between page routes and API routes.
- Better performance via route indexing and middleware caching.
- Safer errors via JSON/HTML mode awareness.
- Config-driven not-found/error views without core code edits.

## Evidence

- `systems/Core/Routing/Router.php` — `Router::pattern()`, `Router::getPatterns()`, `compileRouteRegex()`, dispatch, index
- `systems/Core/Routing/RouteDefinition.php` — `->where()`, `->whereNumber()`, `->whereAlpha()`, `->whereAlphaNumeric()`, fluent auth/permission/role/ability/featureFlag helpers
- `systems/Core/Routing/RouteServiceProvider.php`
- `systems/Core/Http/Request.php`
- `app/http/Kernel.php`
- `app/routes/web.php`
- `app/routes/api.php`
- `app/config/framework.php`
