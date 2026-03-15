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
	->middleware('auth.web')
	->name('dashboard');
```

### 2) Grouped routes with shared middleware and prefix

```php
$router->group(['prefix' => '/api/v1', 'middleware' => ['auth.api', 'xss']], function ($router) {
	$router->get('/auth/me', [AuthApiController::class, 'me'])->name('api.auth.me');
	$router->post('/auth/logout', [AuthApiController::class, 'logout'])->name('api.auth.logout');
});
```

### 3) Resource route

```php
$router->resource('/users', UserApiController::class);
```

This expands to:
- `GET /users` -> `index`
- `POST /users` -> `store`
- `GET /users/{id}` -> `show`
- `PUT|PATCH /users/{id}` -> `update`
- `DELETE /users/{id}` -> `destroy`

### 4) Parameter constraints (`where`) on route params

```php
$router->get('/users/{id}', [UserController::class, 'show'])
	->whereNumber('id');

$router->get('/posts/{slug}', [PostController::class, 'show'])
	->where('slug', '[a-z0-9-]+');
```

### 5) Middleware parameters

```php
$router->post('/login', [AuthController::class, 'authorize'])
	->middleware('throttle:auth')
	->middleware('xss:password,remember_me');
```

## Route Matching Behavior

- Static routes are indexed as `METHOD:/path` for fast lookup.
- Dynamic routes are compiled into cached regex patterns.
- Required and optional parameters are supported (`{id}`, `{id?}`).
- `HEAD` is normalized to `GET` route lookup.

## Named Routes

- Route names are indexed at dispatch.
- URL resolution uses named route map (`urlFor` / helper layer).
- Required placeholders must be provided, otherwise URL resolution returns `null`.
- Optional placeholders are included when provided and omitted when missing.
- Unmatched extra params are appended as query string.

Practical usage:

```php
$router->get('/login', [AuthController::class, 'showLogin'])->name('login');
// Later: route('login')
```

## Error & Not Found Flow

- `OPTIONS` preflight requests auto-return `204` + `Allow` when URI exists for other methods.
- 405 handling is automatic when URI exists for other methods.
- 404 handling distinguishes JSON vs browser requests.
- Browser no-match redirect uses `framework.not_found_redirect.web` (default `login`).
- HTML error pages are read from `framework.error_views`.

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
4. For dynamic params, add constraints (`whereNumber`, etc.) when applicable.
5. Test these cases: success, unauthorized, invalid method (405), unknown route (404/redirect).

## What To Avoid

- Avoid adding API JSON endpoints in `web.php`.
- Avoid unbounded route params when numeric/slug constraints are known.
- Avoid assuming browser and AJAX unmatched routes behave the same.
- Avoid middleware aliases not registered in `framework.middleware_aliases`.

## Benefits

- Clear separation between page routes and API routes.
- Better performance via route indexing and middleware caching.
- Safer errors via JSON/HTML mode awareness.
- Config-driven not-found/error views without core code edits.

## Evidence

- `systems/Core/Routing/Router.php`
- `systems/Core/Routing/RouteDefinition.php`
- `systems/Core/Routing/RouteServiceProvider.php`
- `systems/Core/Http/Request.php`
- `app/http/Kernel.php`
- `app/routes/web.php`
- `app/routes/api.php`
- `app/config/framework.php`
