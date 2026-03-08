# 06. Middleware & Security

## Middleware Alias Map (from `framework.php`)

| Alias | Class | Description |
|-------|-------|-------------|
| `headers` | `SetSecurityHeaders` | CSP, Permissions-Policy, security headers |
| `guest` | `EnsureGuest` | Block authenticated users (for login/register) |
| `auth` | `RequireAuth` | Unified auth (session/token/both) |
| `auth.web` | `RequireSessionAuth` | Session-only auth |
| `auth.api` | `RequireApiToken` | Token-only auth |
| `permission` | `RequirePermission` | RBAC permission check |
| `throttle` | `RateLimit` | Standard rate limiting (configurable) |
| `aggressive-throttle` | `ThrottleRequests` | Aggressive IP-based blocking |
| `xss` | `XssProtection` | XSS pattern detection |
| `api.log` | `ApiRequestLogger` | API request/response logging |

## Middleware Groups

- `web` => `['headers', 'throttle:web']`
- `api` => `['headers', 'throttle:api', 'xss', 'api.log']`

## Middleware Details

### `RequireAuth` (alias: `auth`)

Unified guard-based middleware supporting multiple authentication modes.

**Guard parameters:**
- `auth` — No params: accepts session OR token (either passes).
- `auth:session` — Session auth only. Alias: `auth:web`.
- `auth:token` — Token auth only. Alias: `auth:api`.

**Guard map:** `web` → `session`, `api` → `token`.

**On failure:** JSON `401` for `expectsJson()` requests, otherwise redirect to `REDIRECT_LOGIN`.

```php
$router->get('/dashboard', [DashboardController::class, 'index'])
	->middleware('auth');            // Either session or token

$router->get('/api/v1/me', [AuthController::class, 'me'])
	->middleware('auth:token');      // Token only
```

### `RateLimit` (alias: `throttle`)

Standard rate limiter with configurable profiles and scoping.

**Parameter formats:**
1. `throttle:profile_name` — Use named profile from `framework.rate_limiters`.
2. `throttle:max,decayMinutes[,scope]` — Numeric syntax.
3. `throttle:profile_name,max,decayMinutes,scope` — Profile + overrides.

**Scope options:** `ip-route` (default), `ip`, `route`, `user`, `user-route`, `auth`, `auth-route`.

**File-based state** stored in `storage/cache/rate_limit/`.

**Response headers:** `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `Retry-After` (on 429).

**On limit exceeded:** JSON `429` for `expectsJson()`, plain text `429` otherwise.

```php
->middleware('throttle:api')           // Named profile
->middleware('throttle:60,1')          // 60 requests per 1 minute
->middleware('throttle:10,5,user')     // 10 per 5 min, scoped to user
```

### `ThrottleRequests` (alias: `aggressive-throttle`)

Aggressive IP-based rate limiter using `RateLimitingThrottleTrait`. Provides **temporary and permanent IP blocking** — stricter than `RateLimit`. No configurable parameters; behavior driven by trait defaults.

Use for high-security endpoints (login brute-force protection, etc.).

```php
$router->post('/login', [AuthController::class, 'login'])
	->middleware('aggressive-throttle');
```

### `XssProtection` (alias: `xss`)

Scans incoming POST/PUT/PATCH/DELETE data for XSS patterns.

**Features:**
- Only inspects state-changing methods (not GET).
- Scans `$_GET`, `$_POST`, JSON body, and file names.
- Supports field exclusion: `xss:field1,field2` to skip specific fields.
- Returns 400 with "Potentially unsafe content detected" on detection.

```php
->middleware('xss')                    // Scan all fields
->middleware('xss:content,body')       // Exclude content and body from scan
```

### `ApiRequestLogger` (alias: `api.log`)

Logs every API request and response for debugging/auditing.

**Configuration** in `app/config/api.php`:
```php
'logging' => [
	'enabled'  => true,
	'log_path' => 'logs/api.log',
]
```

**Features:**
- Logs request method, URI, IP, user agent, parameters.
- Logs response status, duration (ms), response summary.
- Auto-masks sensitive fields: `password`, `token`, `secret`, `api_key`, `access_token`.
- Each request gets a unique 16-char request ID for correlation.
- Non-blocking (logging failures don't break requests).

### `SetSecurityHeaders` (alias: `headers`)

Applies security headers via `SecurityHeadersTrait`, configured by `app/config/security.php`:
- Content-Security-Policy (CSP) directives.
- Permissions-Policy directives.
- Additional security headers (X-Frame-Options, etc.).

### `RequirePermission` (alias: `permission`)

Checks RBAC permission. Parameter is the permission code:
```php
->middleware('permission:user-create')
```

### `EnsureGuest` (alias: `guest`)

Blocks authenticated users. Used on login/register pages to redirect already-logged-in users.

## Security Config (`app/config/security.php`)

- CSRF protection settings + include/exclude URIs.
- CSP directives (configuration-driven).
- Permissions-Policy (configuration-driven).
- Trusted proxy IP list (controls forwarded-IP trust in `Request::ip()`).

## Examples

### Route with layered middleware

```php
$router->post('/api/v1/users/save', [UserController::class, 'save'])
	->middleware('auth')
	->middleware('throttle:api')
	->middleware('xss:name,email');
```

### Route group with API preset

```php
$router->group(['middleware' => ['api']], function ($router) {
	// All routes get: headers + throttle:api + xss + api.log
	$router->get('/api/v1/dashboard', [DashboardController::class, 'stats']);
});
```

### Auth guard variants

```php
// Accept either session or token
$router->get('/profile', [UserController::class, 'profile'])->middleware('auth');

// Session only
$router->get('/web/profile', [UserController::class, 'profile'])->middleware('auth:session');

// Token only
$router->get('/api/v1/profile', [UserController::class, 'profile'])->middleware('auth:token');
```

## How To Use

1. Register aliases and groups in `app/config/framework.php`.
2. Apply group first (`web`/`api`), then add route-specific middleware.
3. Use `throttle:<profile>` for predictable request limits.
4. Use `aggressive-throttle` for brute-force-sensitive endpoints.
5. For user-content fields, pass field exclusions to `xss` only when those fields accept HTML.
6. Enable `api.log` for debugging; disable in production for performance.

## What To Avoid

- Avoid attaching `xss` to read-only GET routes (it skips them anyway).
- Avoid hardcoded security headers in controllers — use `SetSecurityHeaders`.
- Avoid trusting forwarded IP headers without `trusted_proxies` config.
- Avoid using `aggressive-throttle` on all routes — reserve for login/sensitive endpoints.

## Benefits

- 10 built-in middleware classes covering auth, rate limiting, XSS, logging, security headers.
- Consistent request protection across routes via groups.
- Centralized security policy in config files.
- API request tracing via `api.log` with sensitive field masking.

## Evidence

- `app/config/framework.php` (aliases, groups, rate_limiters)
- `app/config/security.php` (CSP, Permissions-Policy, CSRF, trusted_proxies)
- `app/config/api.php` (logging config)
- `app/http/middleware/` (10 middleware classes)
- `systems/Core/Routing/Router.php` (middleware pipeline)
- `systems/Middleware/Traits/` (RateLimitingThrottleTrait, XssProtectionTrait, SecurityHeadersTrait)
