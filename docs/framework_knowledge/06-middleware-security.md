# 06. Middleware & Security

## Middleware Alias Map (from `framework.php`)

| Alias | Class | Description |
|-------|-------|-------------|
| `headers` | `SetSecurityHeaders` | CSP, Permissions-Policy, security headers |
| `trusted.hosts` | `ValidateTrustedHosts` | Host allow-list validation before controller logic |
| `trusted.proxies` | `ValidateTrustedProxies` | Forwarded-header trust validation for approved proxy remotes |
| `payload.limits` | `ValidatePayloadLimits` | Body-size, header-count, JSON-field, and multipart-part limits |
| `origin.policy` | `EnforceOriginPolicy` | Origin/Referer validation for state-changing browser routes |
| `upload.guard` | `ValidateUploadGuard` | Upload route policy checks with upload-compatible error payloads |
| `guest` | `EnsureGuest` | Block authenticated users (for login/register) |
| `auth` | `RequireAuth` | Unified auth (session/token/both) |
| `auth.web` | `RequireSessionAuth` | Session-only auth |
| `auth.api` | `RequireApiToken` | API auth using configured methods (`api.auth.methods` with `auth.api_methods` fallback) |
| `permission` | `RequirePermission` | RBAC permission check |
| `feature` | `RequireFeature` | Route-level feature flag gate for staged rollout / kill switch behavior |
| `throttle` | `RateLimit` | Standard rate limiting (configurable) |
| `aggressive-throttle` | `ThrottleRequests` | Aggressive IP-based blocking |
| `xss` | `XssProtection` | XSS pattern detection |
| `api.log` | `ApiRequestLogger` | API request/response logging |
| `cache.headers` | `SetResponseCache` | Route-level Cache-Control/ETag policy |
| `request.safety` | `ValidateRequestSafety` | Request hardening (method/URI/user-agent checks) |

## Middleware Groups

- `web` => `['session.stateful', 'headers', 'trusted.hosts', 'trusted.proxies', 'payload.limits', 'request.fingerprint', 'request.safety', 'origin.policy', 'menu.access', 'csrf', 'throttle:web']`
- `api` => `['headers', 'trusted.hosts', 'trusted.proxies', 'payload.limits', 'content.type', 'request.fingerprint', 'request.safety', 'throttle:api', 'xss', 'api.log']`
- `api.public.submit` => `throttle:auth` on top of the base `api` group
- `api.external.auth` => `auth.api` on top of the base `api` group
- `api.app` => `auth` on top of the base `api` group
- `api.upload.image` => `api.app + permission:user-upload-profile + content.type:multipart + upload.guard:image-cropper`
- `api.upload.action` => `api.app + permission:user-upload-profile + upload.guard:delete`

Important:
- Middleware groups are only applied when you attach them to a route or route group. Defining the `web` group in `framework.php` does not make it automatic for `web.php` routes.
- For browser `POST|PUT|PATCH|DELETE` routes such as login, logout, and modal/form loaders, attach `web` explicitly so CSRF runs.
- Route-provider defaults already wrap `web.php` with `web` and `api.php` with `api`, so `web.php` should prefer fluent intent helpers like `webAuth()` and `guestOnly()`, while nested groups like `api.app` only declare extra route-class middleware beyond the base API stack.

## Middleware Details

### `RequireAuth` (alias: `auth`)

Unified guard-based middleware supporting multiple authentication modes.

**Guard parameters:**
- `auth` — No params: uses configured `auth.methods` order (session-first by default).
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

**Scope behavior details:**
- `auth` => user-global bucket for authenticated users (`user:{id}`), IP-global bucket for guests (`ip:{ip}`).
- `auth-route` => user+route bucket for authenticated users (`user-route:{id}:{method}:{path}`), IP+route bucket for guests (`ip-route:{ip}:{method}:{path}`).

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
'rate_limit' => [
	'enabled' => true,
	'max_requests' => 60,
	'window_seconds' => 60,
],
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

Auth validation runs across all supported guards (`session`, `token`, `jwt`, `api_key`, `oauth2`, `basic`, `digest`, `oauth`) before permission checks.

### `RequireFeature` (alias: `feature`)

Checks a configured feature flag before allowing the request through.

Behavior:
- Returns `403` when the feature is disabled.
- Uses the same feature manager backing `feature()`, `featureFlag()`, and `feature_value()` helpers.
- Intended for operational kill switches and staged rollout on route groups.

```php
$router->group(['prefix' => 'roles', 'middleware' => ['permission:rbac-roles-view', 'feature:rbac.role']], function ($router) {
	// ...
});
```

Current routed usage:
- `rbac.role`
- `rbac.permission`
- `email-template`
- `uploads.image-cropper`

The RBAC roles page and email-template page in `web.php` now carry the same feature middleware as their API/data routes so disabling a module blocks both the HTML entrypoint and the backing AJAX endpoints. The upload cropper API route is also feature-gated directly through the fluent `->featureFlag(...)` route helper.

### `RequireAnyPermission` (alias: `permission.any`)

Checks OR-style permission access (any listed permission passes):

```php
->middleware('permission.any:user-create,user-update')
```

### `RequireRole` (alias: `role`)

Checks role membership (any listed role passes):

```php
->middleware('role:Super Admin,Administrator')
```

### `EnsureGuest` (alias: `guest`)

Blocks authenticated users. Used on login/register pages to redirect already-logged-in users.

The check covers all supported guards (not just session), so token/JWT/API-key authenticated users are treated as authenticated guests too.

Redirect behavior:
- `EnsureGuest` does not hardcode a role or permission map.
- It resolves the first authenticated landing URL from the configured menu/sidebar structure through `resolveAuthenticatedLandingUrl()` and the current permission set.
- If no accessible landing URL is available, it falls back to a `403` response instead of redirecting to an unauthorized page.

## Security Config (`app/config/security.php`)

- CSRF protection settings + include/exclude URIs.
- Core CSRF switches are env-backed: `CSRF_PROTECTION`, `CSRF_TOKEN_NAME`, `CSRF_COOKIE_NAME`, `CSRF_EXPIRE`, `CSRF_REGENERATE`, and `CSRF_SECURE_COOKIE`.
- Recommended runtime default: `CSRF_REGENERATE=false`. This framework returns many responses by emitting output directly, so per-request token rotation can leave modal/AJAX-loaded forms holding stale tokens unless the frontend refreshes tokens after every write.
- Framework default for `CSRF_SECURE_COOKIE` is secure-by-default. Override it only for plain HTTP local development.
- CSRF Origin/Referer verification for state-changing browser requests.
- Request hardening policy (`request_hardening`) to constrain URI, user-agent, body size, header count, payload size, and content-type.
- Trusted host allow-list via `security.trusted.hosts`.
- Trusted proxy allow-list via `security.trusted.proxies` with fail-closed rejection for spoofed forwarded headers and global wildcard trust.
- Origin/Referer validation via `origin.policy`, using the existing `security.csrf` origin settings.
- Upload route policy via `upload.guard`, which keeps upload security failures in the existing `code/message/files/isUpload` response shape.
- CSP directives (configuration-driven).
- Permissions-Policy (configuration-driven).
- Trusted proxy IP list (controls forwarded-IP trust in `Request::ip()`).
- **CSP Nonce** — when `csp.nonce_enabled = true` in `app/config/security.php`, `SetSecurityHeaders` removes `'unsafe-inline'` from `script-src`/`style-src` and injects a per-request `'nonce-{value}'` derived from `Core\Security\CspNonce::get()`. Blade templates access the same value via `$csp_nonce` or the `@nonce` directive. See [29-cursor-pagination-n1-csp.md](29-cursor-pagination-n1-csp.md#4-csp-nonce-coresecuritycspnonce) for full documentation.
## API Whitelist Notes

- `api.url_whitelist` accepts either full API paths such as `/api/v1/auth/login` or normalized internal paths such as `v1/auth/login`.
- Default whitelist path is derived from `API_PREFIX`, `API_VERSIONING_ENABLED`, and `API_VERSION`.

## Security Audit Command

Use the built-in security checker to validate OWASP-oriented baseline settings:

```bash
php myth security:audit
php myth security:audit --ci
```

- `security:audit` reports pass/warn/fail checks.
- `--ci` (or `--strict`) makes warnings fail the command with non-zero exit code.
- Typical checks include CSRF origin protection, request hardening middleware registration, CSP safety, CORS credentials/origin compatibility, and trusted proxy wildcard misuse.

Runtime hardening notes:
- `aggressive-throttle` now resolves forwarded IPs only when `REMOTE_ADDR` matches the trusted-proxy list, preventing `X-Forwarded-For` spoofing from weakening block decisions.
- HTML and streamed responses sanitize header names and values before send, matching the download/redirect response behavior and closing response-splitting gaps on custom headers.

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
	// All routes get: headers + payload.limits + throttle:api + xss + api.log
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

- 23 built-in middleware classes (including method-specific auth middleware) covering auth, RBAC, rate limiting, XSS, logging, and security headers.
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
