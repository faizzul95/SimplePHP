# 09. Framework Config Reference

## `app/config/framework.php`

### Keys in Active Use

- `bootstrap.session.enabled|cli|api`
- `maintenance.secret|view|bypass_cookie.name|ttl|same_site`
- `route_files.web|api|console`
- `view_path`
- `view_cache_path`
- `view_compact_compiled_cache`
- `view_minify_output`
- `error_views.404|general|error_image`
- `not_found_redirect.web`
- `scope_macro.base_path|folders|files`
- `middleware_aliases`
- `middleware_groups`
- `rate_limiters`

### Bootstrap Controls

- `bootstrap.session.enabled` — master switch for bootstrap-managed PHP session startup.
- `bootstrap.session.cli` — allow session startup in CLI/phpdbg runtimes. Default `false`.
- `bootstrap.session.api` — allow session startup for stateless API/mobile requests. Default `false`.
- `APP_URL` — preferred base URL source for CLI, queue workers, cron, non-browser scripts, and deployments behind reverse proxies.

Runtime behavior:

- Browser/page requests remain stateful by default.
- API/mobile requests with bearer/basic/digest/api-key style credentials stay stateless by default.
- Existing session cookies still keep the request stateful.
- CLI does not start PHP session unless explicitly enabled.
- Bootstrap exposes `BOOTSTRAP_RUNTIME`, `BOOTSTRAP_SESSION_ENABLED`, and `BOOTSTRAP_STATEFUL_REQUEST` to downstream code.

### Maintenance Controls

- `maintenance.secret` — default maintenance bypass secret, also used by scheduled maintenance flows.
- `maintenance.view` — fallback maintenance template when the payload does not provide `render`.
- `maintenance.bypass_cookie.name` — default `laravel_maintenance` to mimic Laravel-style bypass cookies.
- `maintenance.bypass_cookie.ttl` — browser bypass lifetime in seconds.
- `maintenance.bypass_cookie.same_site` — `Lax`, `Strict`, or `None` for the bypass cookie.

## `app/config/security.php`

- CSRF settings (`csrf_protection`, token/cookie names, include/exclude URIs, cookie policy)
- env-backed CSRF runtime keys (`CSRF_PROTECTION`, `CSRF_TOKEN_NAME`, `CSRF_COOKIE_NAME`, `CSRF_EXPIRE`, `CSRF_REGENERATE`, `CSRF_SECURE_COOKIE`)
- CSP directives
- Permissions-Policy directives
- `trusted_proxies`

## `app/config/auth.php`

- Session key map
- users/token table names
- token column map
- user column map
- `socialite_enabled`
- env-backed auth/security toggles (`AUTH_*`)
- session fingerprint controls (`session_security.enabled|bind_user_agent|user_agent_mode|bind_ip|fingerprint_key|debug_log_enabled`)
- session concurrency controls (`session_concurrency.*`)
- login policy controls (`systems_login_policy.*`)
- configurable login audit schema (`systems_login_policy.attempts_columns|history_columns`)
- `api_methods` fallback list used by `auth()->apiMethods()` when `api.auth.methods` is not set

## `app/config/features.php`

- route and operational feature flags used by `feature()` and `feature_value()`
- route-level rollout / kill-switch flags consumed by `feature:*` middleware
- current routed flags include `rbac.role`, `rbac.permission`, and `email-template`
- `rbac.role` and `email-template` now gate both the HTML pages in `web.php` and their backing API groups, preventing partial UI exposure when a feature is disabled

## `app/config/api.php`

- CORS settings
- auth-required switch
- token/rate-limit table names
- logging switch/path
- IP and URL whitelist
- env-backed API toggles (`API_*`)
- `auth.methods` preferred source for `auth.api` middleware method resolution

## `app/config/database.php`

- env-backed DB connections (`DB_*`, `DB_STAGING_*`, `DB_PRODUCTION_*`)
- profiling/cache toggles (`DB_PROFILING_ENABLED`, `DB_CACHE_ENABLED`)

## `.env` / `.env.example`

- `.env` is loaded in bootstrap before config files.
- Use `.env` for secrets and deployment-specific values.
- Keep `.env.example` as the committed template without secrets.
- Security/auth/API runtime toggles are intentionally env-first, including CSRF core behavior, login-policy hardening, and API rate limiting.

## `app/config/cache.php`

- default store (`file`/`array`)
- stores config
- prefix

## `app/config/filesystems.php`

- `default` disk used by the new `storage()` helper / managed storage service
- `drivers.*.adapter` maps a filesystem driver name to an adapter class
- `disks.*.driver` selects the registered adapter for a disk; built-in support currently includes `local`
- `disks.*.root` defines the on-disk storage root
- `disks.*.url` provides public URL generation for local public-style disks
- `gdrive` now supports stream-based file operations through the Google API client when credentials are configured; chunked uploads keep large backup files off the PHP heap
- `s3` remains scaffolded for later implementation

Planned extension path:
- add an adapter class implementing `Core\Filesystem\FilesystemAdapterInterface`
- register it through `app/config/filesystems.php` under `drivers`
- point a disk's `driver` to that adapter name

That means future S3 or Google Drive support should not require changing callers that already use `storage()`.

Upload bridge:
- `Components\Files` can now persist through a managed disk with `setStorageDisk($disk, $prefix)`.
- The upload response shape stays compatible and now includes `disk` and `url`, which is the next seam needed for later cloud-backed uploads.

Backup bridge:
- `Components\Backup` can now publish the finished local archive to a managed disk with `setBackupDisk($disk, $prefix)`.
- `integration.backup.publish.disk` and `integration.backup.publish.prefix` provide the default publish target used by console-driven backups.
- Recommended near-term usage: keep user uploads on local/public disks, keep backup archive creation local, then publish backup copies to a remote-managed disk once the real `gdrive` adapter is implemented.

## `app/config/integration.php`

- Google auth and reCAPTCHA credentials remain here.
- `backup.publish.disk` can be left empty for local-only backups, or pointed at a managed disk later such as `gdrive`.
- `backup.publish.prefix` controls the folder/key prefix used when a backup archive is published to managed storage.

## `.env` / `.env.example`

- `BACKUP_STORAGE_DISK` and `BACKUP_STORAGE_PREFIX` configure the default managed-disk publish target for console and scheduled backups.
- `FILESYSTEM_GDRIVE_ROOT_ID` points at the Drive folder used as the disk root.
- `FILESYSTEM_GDRIVE_CREDENTIALS_PATH` or `FILESYSTEM_GDRIVE_CREDENTIALS_JSON` provide service-account credentials for real Drive operations.
- `FILESYSTEM_GDRIVE_SUBJECT` is optional for domain-wide delegation.
- `FILESYSTEM_GDRIVE_CHUNK_SIZE` controls resumable upload chunking for large backup archives.

Console verification:
- `php myth storage:check gdrive --bytes=10485760` performs a streamed write/read/delete probe against the selected disk.
- Use `--keep` when you want to retain the probe object for manual inspection after the check.

## `app/config/queue.php`

- default driver (`database`/`sync`)
- database connection table names + retry_after
- worker defaults (`sleep`, `tries`, `timeout`)

## `app/config/menu.php`

Drives the sidebar/menu tree and the renderer pipeline consumed by `Components\MenuManager`.

Keys:
- `menu_renderers.profiles` — named renderer profiles keyed by usage context (e.g. `sidebar`, `topbar`).
- `menu_renderers.templates` — template-path map used when a profile does not override rendering.
- `menu.main` — ordered tree of top-level items. Each item accepts `desc`, `route`, `icon`, `permission` (RBAC slug), optional `role_ids` bypass list, `state` (string or closure), `active`, `badge` (single/multi/closure), and nested `subpage` maps.

`menu_manager()->resolveAuthenticatedLandingUrl()` walks this tree to pick a user's post-login landing page, so every permissioned item must match a real RBAC slug.

## Config-key usage audit

See [CONFIG_AUDIT.md](../CONFIG_AUDIT.md) for the per-key USED / UNUSED / RESERVED classification. This document describes **what keys exist**; the audit tracks **which keys are actually read by the codebase**.

## Examples

### Router behavior tuning (`framework.php`)

```php
'view_minify_output' => true,
'view_compact_compiled_cache' => true,
'not_found_redirect' => ['web' => 'login'],
'middleware_groups' => [
	'web' => ['headers', 'payload.limits', 'request.safety', 'throttle:web'],
	'api' => ['headers', 'payload.limits', 'content.type', 'request.safety', 'throttle:api', 'xss', 'api.log'],
],
```

- `redirects.allowed_hosts` for explicit cross-origin redirect allow-listing used by `redirect()->away()` and maintenance-mode redirect targets
Auth/API method resolution reference:

```php
// Preferred for auth.api
$config['api']['auth']['methods'] = ['token', 'jwt', 'api_key'];

// Fallback when api.auth.methods is absent
$config['auth']['api_methods'] = ['token'];
```

### Security tuning (`security.php`)

```php
'trusted_proxies' => ['127.0.0.1'],
'redirects' => [
	'allowed_hosts' => ['accounts.example.com'],
],
'csrf' => [
	'csrf_exclude_uris' => ['api/*'],
],
```

Environment overrides used by `security.php`:

```dotenv
CSRF_PROTECTION=true
CSRF_TOKEN_NAME=csrf_token
CSRF_COOKIE_NAME=csrf_cookie
CSRF_EXPIRE=7200
CSRF_REGENERATE=true
CSRF_SECURE_COOKIE=false
REDIRECT_ALLOWED_HOSTS=accounts.example.com
```

### Queue tuning (`queue.php`)

```php
'default' => 'database',
'worker' => ['sleep' => 3, 'tries' => 3, 'timeout' => 60],
```

## How To Use

1. Treat config files as single source of truth for framework behavior.
2. Change config before changing core code.
3. Keep environment-specific values aligned (auth/api token table names, cache driver, queue driver).
4. Put secrets and environment-specific values in `.env`, not in committed config files.
5. Enable `view_minify_output` only for HTML responses rendered by Blade; it safely compresses inter-tag whitespace while preserving `script`, `style`, `pre`, and `textarea` blocks.
6. Enable `view_compact_compiled_cache` when you want smaller compiled Blade cache files on disk without changing rendered HTML output.

## What To Avoid

- Avoid hardcoding behavior in controllers/middleware that already has config key support.
- Avoid changing token table name in one config file only.
- Avoid unsafe CSP relaxations without explicit need.
- Avoid committing real credentials to git-tracked config files.

## Benefits

- Predictable behavior across environments.
- Lower maintenance cost via centralized settings.
- Easier onboarding and troubleshooting.

## Evidence

- `app/config/framework.php`
- `app/config/security.php`
- `app/config/auth.php`
- `app/config/api.php`
- `app/config/cache.php`
- `app/config/queue.php`
