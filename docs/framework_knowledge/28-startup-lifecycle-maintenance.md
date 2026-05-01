# 28. Startup Lifecycle Maintenance

## Purpose

This note documents the intended startup phases so bootstrap changes stay predictable across web, API/mobile, and CLI runtimes.

## Startup Phases

### 1) Front Controller

- `index.php` wraps startup in a single `try/catch`.
- `bootstrap.php` is required first.
- After bootstrap succeeds, `Components\Maintenance` checks `storage/framework/down` before request capture.
- If maintenance is active and the request path matches the configured bypass secret, a temporary bypass cookie is issued and the user is redirected back to the app.
- If maintenance metadata includes `redirect`, non-bypass requests are redirected before the kernel runs.
- If maintenance metadata includes `render`, that view file is used as the maintenance response template.
- Otherwise `Core\Http\Request::capture()` and `App\Http\Kernel::handle()` run.

### 2) Bootstrap Core

`bootstrap.php` is intentionally split into internal phases:

- `bootstrapLoadComposerAutoload()`
- `bootstrapLoadCoreHooks()`
- `bootstrapRegisterConsoleAlias()`
- `loadConfig()`
- `configureEnvironment()`
- `initializeRuntime()`
- `initializeSession()`
- `bootstrapInitializeHelpers()`
- `bootstrapInitializeSystems()`

This keeps order-sensitive logic explicit.

## Runtime Model

Bootstrap exposes these constants:

- `BOOTSTRAP_RUNTIME` => `web`, `api`, or `cli`
- `BOOTSTRAP_SESSION_ENABLED` => whether PHP session was started
- `BOOTSTRAP_STATEFUL_REQUEST` => whether bootstrap considers the request stateful
- `BASE_URL`, `APP_DIR`, `APP_ENV`, `TEMPLATE_DIR`

Rules:

- `web` => stateful by default
- `api` => stateless by default unless `framework.bootstrap.session.api` is enabled
- `cli` => stateless by default unless `framework.bootstrap.session.cli` is enabled

## Config Loading Rules

- Config files are loaded from `app/config/*.php`
- Loading is deterministic via natural sort order
- Config files may either mutate global `$config` or return arrays
- Returned arrays are merged into `$config[file_name]`

## Environment Rules

- `ENVIRONMENT` is derived from config and set once
- Security presets are applied before runtime initialization completes
- Error reporting is configured from environment only
- Invalid environment is a bootstrap failure

## Session Rules

- Session startup is decided before helper/system bootstrap
- Existing session cookie keeps a request stateful
- Stateless API/mobile credentials do not require PHP session
- CLI does not require session

## System Bootstrap Rules

`systems/app.php` is responsible for application-runtime initialization:

- `initializeApplicationTimezone()`
- `initializeApplicationQueryCache()`
- `initializeApplicationMiddleware()`
- `initializeDatabaseRegistry()`

Database behavior:

- Query cache and middleware are initialized during startup
- Database connection registry is prepared once
- Actual DB manager creation is lazy
- Actual DB connection happens only when `db()` is called
- Scope/macro loading happens once per resolved connection

## Failure Handling Rules

- `bootstrapFail()` is the canonical bootstrap failure path
- `appBootFail()` delegates to `bootstrapFail()` when available
- Boot failures should fail closed with a 500/503 response for HTTP and a non-zero exit for CLI
- Do not `die()` directly in new bootstrap/runtime code

## Maintenance Guidance

- `php myth down --secret=...` writes the active bypass secret into `storage/framework/down`.
- `php myth down` now persists Laravel-style maintenance metadata: `message`, `retry`, `refresh`, `secret`, `status`, `redirect`, and `render`.
- `framework.maintenance.secret` / `MYTH_MAINTENANCE_SECRET` provide the default secret for scheduled maintenance windows.
- HTTP requests are blocked at the front controller while maintenance is active unless a valid bypass cookie is already present.
- The default bypass cookie name is `laravel_maintenance` to match Laravel-style behavior, but it remains configurable via `framework.maintenance.bypass_cookie.name`.

### Payload Contract

The maintenance payload stored in `storage/framework/down` supports:

- `message` — response text shown in the maintenance page.
- `retry` — `Retry-After` header value in seconds.
- `refresh` — `Refresh` header value in seconds.
- `secret` — bypass path segment that grants the maintenance cookie.
- `status` — HTTP status code for the maintenance response.
- `redirect` — redirect target for non-bypass maintenance requests.
- `render` — alternate view path relative to the project root.

When changing startup logic:

1. Keep phase order explicit.
2. Avoid hidden side effects during file include.
3. Prefer lazy connection/resource creation over eager startup work.
4. Keep CLI and API/mobile stateless unless state is required.
5. Update this document and `01-runtime-architecture.md` in the same change.

## Evidence

- `index.php`
- `bootstrap.php`
- `systems/app.php`
- `systems/hooks.php`
- `app/config/framework.php`