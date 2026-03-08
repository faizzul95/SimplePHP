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
- CSP directives
- Permissions-Policy directives
- `trusted_proxies`

## `app/config/auth.php`

- Session key map
- users/token table names
- token column map
- user column map
- `socialite_enabled`

## `app/config/api.php`

- CORS settings
- auth-required switch
- token/rate-limit table names
- logging switch/path
- IP and URL whitelist

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
	'web' => ['headers', 'throttle:web'],
	'api' => ['headers', 'throttle:api', 'xss', 'api.log'],
],
```

### Security tuning (`security.php`)

```php
'trusted_proxies' => ['127.0.0.1'],
'csrf' => [
	'csrf_protection' => true,
	'csrf_exclude_uris' => ['api/*'],
],
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

## What To Avoid

- Avoid hardcoding behavior in controllers/middleware that already has config key support.
- Avoid changing token table name in one config file only.
- Avoid unsafe CSP relaxations without explicit need.

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
