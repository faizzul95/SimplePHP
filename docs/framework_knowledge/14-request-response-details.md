# 14. Request & Response Details

## Request (`Core\Http\Request`)

### Capture

- `Request::capture(): self` — Factory method. Builds from `$_GET`, `$_POST`, `$_SERVER`.
- Auto-merges JSON body into `$request` when `Content-Type` contains `application/json`.

### Complete Method List

**Input access:**
- `method(): string` — HTTP method (uppercased).
- `path(): string` — Request path (resolved from `__route` query, `PATH_INFO`, or `REQUEST_URI`).
- `input(?string $key = null, $default = null)` — Merged GET + POST data; returns all if no key.
- `query(?string $key = null, $default = null)` — GET parameters only; returns all if no key.
- `all(): array` — All input (merged GET + POST).
- `has(string $key): bool` — Check if input key exists.
- `merge(array $data): static` — Merge data into POST.
- `only(array $keys): array` — Subset of input.
- `except(array $keys): array` — Input excluding keys.

**Headers:**
- `header(string $key, $default = null)` — Get header (case-insensitive).
- `userAgent(): string` — `User-Agent` header or `'Unknown'`.
- `bearerToken(): ?string` — Extract token from `Authorization: Bearer <token>`.

**URL helpers:**
- `url(): string` — Full URL without query string (`scheme://host/path`).
- `fullUrl(): string` — Full URL with query string (`scheme://host/REQUEST_URI`).

**Client context:**
- `ip(): string` — Client IP. Respects forwarded headers **only** when `REMOTE_ADDR` is in `security.trusted_proxies`. Checks: `CF-Connecting-IP`, `X-Forwarded-For`, `X-Client-IP`, etc.
- `platform(): string` — Detects: `Windows`, `macOS`, `Linux`, `Android`, `iOS`, `Unknown`.
- `browser(): string` — Detects: `Microsoft Edge`, `Google Chrome`, `Mozilla Firefox`, `Safari`, `Opera`, `Internet Explorer`, `Unknown`.

**Route params:**
- `setRouteParams(array $params): void` — Called by Router after matching.
- `route(?string $key = null, $default = null)` — Get route parameter; returns all if no key.

**Format checks:**
- `isJson(): bool` — `Content-Type` contains `application/json` or `+json`.
- `wantsJson(): bool` — `Accept` header contains `application/json` or `+json`.
- `isApi(): bool` — Path starts with `api/`.
- `expectsJson(): bool` — True when `isApi()`, `wantsJson()`, or `X-Requested-With: XMLHttpRequest`. Drives router error format (JSON vs HTML).

## Response (`Core\Http\Response`)

- `Response::json(array $data, int $status = 200): void` — Sends JSON response + `exit`.
- `Response::redirect(string $url, int $status = 302): void` — Sends redirect + `exit`.
- `Response::sanitizeRedirectTarget(string $url, bool $allowExternal = false): string` — Normalizes redirect destinations and rejects dangerous protocols / malformed targets.
- `Response::sendRedirectHeaders(string $url, int $status = 302, array $headers = [], bool $allowExternal = false): void` — Sends validated redirect headers.

## Redirector (`Core\Http\Redirector` / `RedirectResponse`)

- `redirect()` with no argument returns a redirector instance.
- `redirect('/path')` remains supported and immediately sends the redirect for backward compatibility.
- `redirect()->to('/dashboard')` — Internal redirect response.
- `redirect()->route('dashboard')` — Named-route redirect response.
- `redirect()->back('/fallback')` — Referrer-aware redirect with fallback.
- `redirect()->away('https://example.com')` — Explicit external redirect.
- `redirect()->away('https://example.com')` preserves the external target because the redirect response now carries an explicit external-redirect flag through the final send step.
- `redirect()->with('key', $value)` — Flash session data for the next request.
- `redirect()->withErrors([...])` — Flash validation errors for the next request.
- `redirect()->withInput([...])` — Flash old input for the next request. Sensitive password-style fields are excluded by default.
- Controller helpers: `redirectTo(...)`, `redirectRoute(...)`.

Both methods call `exit` after sending output.

## Important Behavior

- `expectsJson()` drives router error format: JSON `['code', 'message']` vs HTML error page.
- Browser `422` validation failures redirect back with flashed `_errors` and `_old_input` session data instead of rendering the generic error page.
- Trusted proxy IPs in `security.trusted_proxies` control which `REMOTE_ADDR` values allow forwarded-header trust.
- Response redirect sanitizes URL and rejects unsafe schemes such as `javascript:`, `data:`, `vbscript:`, `file:`, `phar:`, and `php:`.
- Scheme-relative redirects (`//host/path`) are rejected.
- External absolute redirects are blocked unless explicitly allowed through the redirector `away()` path.
- Request `path()` resolution order: `$_GET['__route']` → `PATH_INFO` → `REQUEST_URI`.

## Examples

### Read input + route params

```php
$userId = request()->route('id');
$limit  = (int) request()->input('limit', 20);
$search = request()->query('search', '');
$all    = request()->all();
```

### Check request context

```php
if (request()->isApi()) {
	// API route
}

if (request()->expectsJson()) {
	return ['code' => 200, 'data' => $payload];
}

$ip = request()->ip();
$browser = request()->browser();
$platform = request()->platform();
```

### JSON response from Kernel

```php
// In controller — returning array auto-converts to JSON via Kernel
return ['code' => 200, 'message' => 'OK', 'data' => $payload];
```

### Direct Response usage

```php
\Core\Http\Response::json(['code' => 422, 'message' => 'Validation failed', 'errors' => $errors], 422);

\Core\Http\Response::redirect(url('login'));

redirect()->route('dashboard')->send();

redirect()
	->back('/login')
	->withErrors(['email' => 'Email is required'])
	->withInput(['email' => 'user@example.com'])
	->send();
```

### Bearer token extraction

```php
$token = request()->bearerToken(); // null if no Bearer header
```

## How To Use

1. Use `request()` helper instead of direct `$_GET`/`$_POST`/`$_SERVER` access.
2. Use `input()` for merged GET+POST. Use `query()` for GET-only.
3. Let Kernel convert returned arrays into JSON responses automatically.
4. Use `expectsJson()` for dual web/API-friendly endpoints.
5. Use `url()` / `fullUrl()` instead of hardcoding host/scheme.
6. Use `old('field')` in Blade views when you want redirected validation failures to repopulate form values.

## What To Avoid

- Avoid manually parsing `php://input` — `capture()` already merges JSON body.
- Avoid accessing `$_SERVER` directly for IP/headers — use `ip()`, `header()`.
- Avoid building redirect URLs without `url()` helper.
- Avoid sending user-controlled absolute redirect targets unless you explicitly intend external navigation.
- Avoid expecting `withInput()` to repopulate forms submitted purely through JavaScript/XHR unless the frontend explicitly consumes the response and updates the form.

## Benefits

- Unified request handling for web and API.
- Automatic JSON body parsing.
- Trusted proxy support for reverse-proxy/CDN deployments.
- Header injection prevention in redirects.
- Safer same-origin redirect enforcement by default.

## Evidence

- `systems/Core/Http/Request.php` (352 lines)
- `systems/Core/Http/Response.php`
- `systems/Core/Http/Redirector.php`
- `systems/Core/Http/RedirectResponse.php`
- `app/config/security.php` (trusted_proxies)
