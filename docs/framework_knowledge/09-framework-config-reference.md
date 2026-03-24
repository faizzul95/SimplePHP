# 09. Framework Config Reference

## `app/config/framework.php`

### Keys in Active Use

- `route_files.web|api|console`
- `view_path`
- `view_cache_path`
- `error_views.404|general|error_image`
- `not_found_redirect.web`
- `scope_macro.base_path|folders|files`
- `middleware_aliases`
- `middleware_groups`
- `rate_limiters`

## `app/config/security.php`

- Request toggles (`throttle_request`, `xss_request`, `permission_request`)
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

## `app/config/queue.php`

- default driver (`database`/`sync`)
- database connection table names + retry_after
- worker defaults (`sleep`, `tries`, `timeout`)

## Examples

### Router behavior tuning (`framework.php`)

```php
'not_found_redirect' => ['web' => 'login'],
'middleware_groups' => [
	'web' => ['headers', 'request.safety', 'throttle:web'],
	'api' => ['headers', 'request.safety', 'throttle:api', 'xss', 'api.log'],
],
```

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
