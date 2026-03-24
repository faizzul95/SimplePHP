# 15. Auth Component Reference

## Session + Token Unified Auth (`Components\Auth`)

Supported methods:
- Session
- Token (personal access token)
- JWT (HS256)
- API Key
- OAuth (social login-backed session)
- OAuth2 (bearer token with scopes)
- Basic Auth
- Digest Auth

### Identity Resolution

- `check(array|string|null $methods = null): bool` — True if authenticated via any provided method(s). Defaults to configured order.
- `guest(array|string|null $methods = null): bool` — Opposite of `check()`.
- `via(array|string|null $methods = null): ?string` — Returns resolved method (`session`, `token`, `jwt`, `api_key`, `oauth`, `oauth2`, `basic`, or `digest`) or `null`.
- `id(array|string|null $methods = null): ?int` — Authenticated user ID using configured/provided methods.
- `user(array|string|null $methods = null): ?array` — Authenticated user record using configured/provided methods.
- `checkAny(array|string $methods): bool` — Alias of `check($methods)`.
- `sessionUser(): ?array` — Session-authenticated user record.
- `tokenUser(): ?array` — Token-authenticated user record.
- `jwtUser(): ?array` — JWT-authenticated user record.
- `apiKeyUser(): ?array` — API-key-authenticated user record.
- `oauth2User(): ?array` — OAuth2-authenticated user record.
- `basicUser(): ?array` — Basic-authenticated user record.
- `digestUser(): ?array` — Digest-authenticated user record.
- `oauthUser(): ?array` — OAuth session-authenticated user record.
- `checkSession(): bool` — True if session auth is active.
- `checkToken(): bool` — True if bearer token auth is active.
- `checkJwt(): bool` — True if JWT auth is active.
- `checkApiKey(): bool` — True if API key auth is active.
- `checkOAuth2(): bool` — True if OAuth2 auth is active.
- `checkBasic(): bool` — True if Basic auth is active.
- `checkDigest(): bool` — True if Digest auth is active.
- `checkOAuth(): bool` — True if OAuth-backed session auth is active.
- `bearerToken(): ?string` — Extract bearer token from Authorization header.
- `basicChallengeHeader(): string` — Build `WWW-Authenticate` header for Basic auth.
- `digestChallengeHeader(): string` — Build `WWW-Authenticate` header for Digest auth.

### Session Auth Methods

- `attempt(array $credentials): array|false` — Verify credentials (password_verify). Returns user array or false.
- `login(int $userId, array $sessionData = []): bool` — Start session for user.
- `loginUsingId(int $userId, array $sessionData = []): bool` — Alias for `login()`.
- `logout(bool $destroySession = false): void` — Clear session + revoke current token.

Browser landing behavior:
- The browser login flow returns a `redirectUrl` based on `resolveAuthenticatedLandingUrl()` when available.
- That helper resolves the first authenticated landing URL from the configured menu/sidebar structure rather than hardcoding a permission list inside middleware/controllers.
- `EnsureGuest` uses the same helper, so already-authenticated users hitting guest-only pages are redirected using the same landing strategy.

### Personal Access Tokens

- `createToken(int $userId, string $name = 'Default Token', ?int $expiresAt = null, array $abilities = ['*']): ?string` — Create token, returns plain-text token.
- `revokeToken(string $plainToken): bool` — Revoke specific token.
- `revokeCurrentToken(): bool` — Revoke the token used in current request.
- `revokeAllTokens(int $userId): bool` — Revoke all tokens for user.
- `hasAbility(string $ability): bool` — Check if current token has ability.

### API Keys

- `createApiKey(int $userId, string $name = 'Default API Key', ?int $expiresAt = null, array $abilities = ['*']): ?string` — Create API key, returns plain-text API key.
- `revokeApiKey(string $plainApiKey): bool` — Deactivate specific API key.
- `revokeCurrentApiKey(): bool` — Deactivate API key from current request.

Security note:
- Keep `auth.api_key.allow_query_param` disabled unless absolutely required (URL/query keys are easier to leak).

### OAuth2 Tokens

- `createOAuth2Token(int $userId, string $name = 'Default OAuth2 Token', ?int $expiresAt = null, array $scopes = ['*']): ?string` — Create OAuth2 token, returns plain token.
- `revokeOAuth2Token(string $plainToken): bool` — Revoke specific OAuth2 token.
- `revokeCurrentOAuth2Token(): bool` — Revoke OAuth2 token from current request.

JWT/Digest hardening:
- JWT validation enforces configured algorithm and verifies signature/time-window claims.
- Digest validation enforces realm/opaque/qop, binds digest URI to current request URI, validates nonce TTL/skew, and rejects replayed nonce counters.

Session hijack hardening:
- Session auth validates an auth fingerprint per session (`auth.session_security`).
- Fingerprint binds to user-agent by default, and optionally to client IP.

Session concurrency hardening:
- Control how many active browser/device sessions a user can keep (`auth.session_concurrency`).
- Supports single-device mode or max-device limits.
- Optional oldest-session invalidation when login limit is exceeded.

### Social Login

- `socialite(string $provider, array $socialUser, ?callable $onCreateCallback = null): array` — OAuth login/register. Creates user if not found.
- Enabled via `auth.socialite_enabled` config.

### Config Access

- `getConfig(?string $key = null): mixed` — Get auth config value or all config.
- `debugAuthState(array|string|null $methods = null): array` — Auth diagnostics payload for troubleshooting unauthorized responses.

### Configurable Mappings

Auth uses config-driven table/column/session key maps:
- `auth.methods` default resolution order (session-first by default: `['session']`)
- `auth.api_methods` fallback method list for `auth.api`; preferred source is `api.auth.methods`, and runtime fallback is `['token']` when both are empty/missing
- `auth.jwt` JWT validation config (`enabled`, `secret`, `algo`, `leeway`, `user_id_claim`)
- `auth.api_key` API key extraction/table-column config
- `auth.basic` Basic auth realm + identifier columns
- `auth.digest` Digest auth realm/qop/nonce policy + column mappings
- `auth.oauth2` OAuth2 access-token table + scopes/revocation mappings
- `auth.session_security` session fingerprint controls (`bind_user_agent`, `user_agent_mode`, `bind_ip`, `debug_log_enabled`)
- `auth.session_concurrency` active-device/session controls
- `auth.systems_login_policy.attempts_table|history_table`
- `auth.systems_login_policy.attempts_columns|history_columns` (fully configurable login audit column mappings)
- `auth.users_table`, `auth.token_table`
- `auth.token_columns` (id, user_id, name, token, abilities, expires_at)
- `auth.user_columns` (id, email, password, name)
- `auth.session_keys` (user_id, user_name, user_email, logged_in)

## Easy Config (Recommended)

Use these minimal presets in `app/config/auth.php`.

### 1) Web + Token only (safe default)

```php
$config['auth']['methods'] = ['session', 'token'];
$config['auth']['api_methods'] = ['token'];
```

### 2) API fallback (JWT -> API key -> token)

```php
$config['auth']['methods'] = ['session', 'token'];
$config['auth']['api_methods'] = ['jwt', 'api_key', 'oauth2', 'token'];

$config['auth']['jwt']['enabled'] = true;
$config['auth']['jwt']['secret'] = getenv('APP_KEY') ?: 'change-me';

$config['auth']['api_key']['enabled'] = true;
$config['auth']['api_key']['allow_query_param'] = false;
```

### 3) Internal services (Basic + Digest enabled)

```php
$config['auth']['api_methods'] = ['basic', 'digest', 'token'];

$config['auth']['basic']['enabled'] = true;
$config['auth']['basic']['realm'] = 'MythPHP Internal';

$config['auth']['digest']['enabled'] = true;
$config['auth']['digest']['nonce_secret'] = getenv('APP_KEY') ?: 'change-me';
```

Security checklist:
- Keep `auth.api_key.allow_query_param = false`.
- Keep defaults least-privilege (`methods` => `['session']` and API methods set per route/environment).
- Enable only methods required per route/environment.

### 4) Single-device login (force 1 browser/device)

```php
$config['auth']['session_concurrency'] = [
	'enabled' => true,
	'max_devices' => 1,
	'invalidate_oldest' => true,
	'deny_new_login_when_limit_reached' => false,
	'enforce_on_check' => true,
];
```

Behavior:
- New login succeeds.
- Previous device session is invalidated and will be logged out on next request.
- Requires persistent cache store (for example file store). In-memory array cache cannot track sessions across requests.

### 5) Max 3 devices, reject 4th login

```php
$config['auth']['session_concurrency'] = [
	'enabled' => true,
	'max_devices' => 3,
	'deny_new_login_when_limit_reached' => true,
	'invalidate_oldest' => false,
	'enforce_on_check' => true,
];
```

Behavior:
- If 3 active sessions already exist, next login attempt fails (`auth()->login(...)` returns `false`).

Implementation note:
- Concurrency tracking uses `cache()` keys per user. Keep cache configured and writable for strict enforcement.

### 6) systems_login_policy (credential lockout + audit)

```php
$config['auth']['systems_login_policy'] = [
	'enabled' => true,
	'max_attempts' => 5,
	'decay_seconds' => 600,
	'lockout_seconds' => 900,
	'ban_enabled' => false,
	'ban_after_failures' => 5,
	'ban_user_status' => 2,
	'track_by_identifier' => true,
	'track_by_ip' => true,
	'enforce_user_status' => true,
	'user_status_column' => 'user_status',
	'allowed_user_status' => [1],
	'password_rotation' => [
		'enabled' => false,
		'max_age_days' => 90,
		'password_changed_at_column' => 'password_changed_at',
		'force_reset_column' => 'force_password_change',
		'require_password_changed_at' => false,
	],
	'record_attempts' => true,
	'record_history' => true,
	'attempts_columns' => [
		'user_id' => 'user_id',
		'identifier' => 'identifier',
		'ip_address' => 'ip_address',
		'time' => 'time',
		'user_agent' => 'user_agent',
		'created_at' => 'created_at',
		'updated_at' => 'updated_at',
	],
	'history_columns' => [
		'user_id' => 'user_id',
		'ip_address' => 'ip_address',
		'login_type' => 'login_type',
		'operating_system' => 'operating_system',
		'browsers' => 'browsers',
		'time' => 'time',
		'user_agent' => 'user_agent',
		'created_at' => 'created_at',
		'updated_at' => 'updated_at',
	],
];
```

Behavior:
- Failed credential attempts are written to `system_login_attempt`.
- Exceeding `max_attempts` causes temporary lockout based on recent attempt rows for the configured identifier and/or IP strategy.
- When `ban_enabled` is on, repeated failures for a known user can automatically set a restricted user status (for example `2` = suspended/banned).
- Successful credential verification clears matching login-attempt rows so stale lockouts do not persist after a valid login.
- Session auth re-checks current user status on each request, so banning or suspending a user invalidates existing sessions.
- Password rotation can require a password change immediately (`force_reset_column`) or after `max_age_days` when the configured columns exist.
- Logs are written to `system_login_attempt` and `system_login_history` (if enabled and schema exists).
- Both tables and their column names are configurable from auth config.

Seeded RBAC defaults:
- Role `1` (Super Administrator) receives wildcard `*` access.
- Role `2` (Administrator) is intentionally narrower and does not receive wildcard, user-delete, upload-image, RBAC abilities delete, RBAC roles create/delete, or RBAC email delete access. It does receive dashboard access, user view/create/update, RBAC abilities view/create/update, RBAC roles view/update, and RBAC email view/create/update.
- The default user seeder creates both `superadmin@admin.com` and `admin@admin.com` to reflect those two role levels.

### 7) Troubleshooting Unauthorized (401)

Enable middleware auth diagnostics:

```php
$config['auth']['session_security']['debug_log_enabled'] = true;
```

Inspect `logs/error.log` for `[AuthDebug]` entries.

If your session breaks during browser/device emulation, keep `bind_user_agent` enabled and switch fingerprint mode:

```php
$config['auth']['session_security']['bind_user_agent'] = true;
$config['auth']['session_security']['user_agent_mode'] = 'family'; // strict|normalized|family
```

Schema check:

```php
$audit = auth()->schemaAudit();
if (!$audit['ok']) {
	// inspect $audit['missing_tables'] and $audit['missing_columns']
}
```

## Examples

### 1) Credential attempt + login

```php
$user = auth()->attempt(['email' => $email, 'password' => $password]);
if ($user !== false) {
	auth()->login((int) $user['id'], ['userEmail' => $user['email']]);
}
```

### 2) Token-based API auth check

```php
if (auth()->checkToken()) {
	$user = auth()->tokenUser();
	$canEdit = auth()->hasAbility('edit-users');
}

// Multi-method checks (aliases: web=session, api=token)
if (auth()->check(['web', 'api'])) {
	$authMethod = auth()->via(['session', 'token']);
}

// Mixed strategy API auth
if (auth()->check(['jwt', 'api_key', 'basic', 'digest'])) {
	$user = auth()->user(['jwt', 'api_key', 'basic', 'digest']);
}

// auth.api middleware defaults can be expanded in app/config/auth.php
// $config['auth']['api_methods'] = ['token', 'jwt', 'api_key', 'oauth2', 'basic', 'digest'];
```

### 2d) Dedicated Middleware Aliases

Available aliases in `app/config/framework.php`:
- `auth.web`, `auth.api`
- `auth.token`, `auth.jwt`, `auth.api_key`, `auth.oauth`, `auth.oauth2`, `auth.basic`, `auth.digest`
- `permission`, `permission.any`, `role`, `ability`

Examples:

```php
$router->get('/api/v1/private', [UserApiController::class, 'index'])->middleware('auth.token');
$router->get('/api/v1/reports', [ReportController::class, 'index'])->middleware('auth.jwt');
$router->get('/api/v1/client', [IntegrationController::class, 'index'])->middleware('auth.oauth2');

$router->get('/admin', [AdminController::class, 'index'])->middleware('role:Super Admin,Administrator');
$router->get('/jobs', [JobController::class, 'dispatch'])->middleware('ability:jobs.dispatch,jobs.admin');
$router->get('/settings', [SettingsController::class, 'index'])->middleware('permission.any:settings-view,rbac-roles-view');
```

## RBAC / ACL Methods

- `roles(?int $userId = null): array` — List user roles (supports multi-role via `user_profile`).
- `hasRole(string|int $role, ?int $userId = null): bool`
- `hasAnyRole(array|string $roles, ?int $userId = null): bool`
- `hasAllRoles(array $roles, ?int $userId = null): bool`
- `permissions(?int $userId = null, bool $includeRequestAbilities = true): array`
- `hasPermission(string $permission, ?int $userId = null): bool`
- `hasAnyPermission(array|string $permissions, ?int $userId = null): bool`
- `hasAllPermissions(array $permissions, ?int $userId = null): bool`
- `can(string $permission, ?int $userId = null): bool`
- `cannot(string $permission, ?int $userId = null): bool`
- `assignRole(int $userId, int|string $role, bool $isMain = false): bool`
- `syncRoles(int $userId, array $roles): bool`
- `revokeRole(int $userId, int|string $role): bool`
- `grantPermissionsToRole(int|string $role, array|string $permissions): bool`
- `revokePermissionsFromRole(int|string $role, array|string $permissions): bool`

### 2b) Route middleware by auth type

```php
// Session/OAuth-backed page
$router->get('/dashboard', [DashboardController::class, 'index'])->middleware('auth:session');
$router->get('/oauth/profile', [OAuthController::class, 'profile'])->middleware('auth:oauth');

// API routes by credential type
$router->get('/api/v1/users', [UserApiController::class, 'index'])->middleware('auth:token');
$router->get('/api/v2/reports', [ReportController::class, 'index'])->middleware('auth:jwt');
$router->get('/api/v2/integrations', [IntegrationController::class, 'index'])->middleware('auth:api_key');
$router->get('/api/v2/internal-basic', [InternalController::class, 'basic'])->middleware('auth:basic');
$router->get('/api/v2/internal-digest', [InternalController::class, 'digest'])->middleware('auth:digest');

// Chain fallback methods (first valid wins)
$router->get('/api/v2/fallback', [FallbackController::class, 'index'])
    ->middleware('auth:jwt,api_key,token');
```

### 2c) Controller examples for all auth types

```php
<?php

namespace App\Http\Controllers\Api;

use Core\Http\Controller;
use Core\Http\Request;

class AuthTypeDemoController extends Controller
{
	public function sessionOnly(Request $request): array
	{
		return ['code' => 200, 'auth_type' => auth()->via(['session']), 'user' => auth()->user(['session'])];
	}

	public function tokenOnly(Request $request): array
	{
		return [
			'code' => 200,
			'auth_type' => auth()->via(['token']),
			'user' => auth()->user(['token']),
			'can_users_read' => auth()->hasAbility('users.read'),
		];
	}

	public function jwtOnly(Request $request): array
	{
		return ['code' => 200, 'auth_type' => auth()->via(['jwt']), 'user' => auth()->user(['jwt'])];
	}

	public function apiKeyOnly(Request $request): array
	{
		return ['code' => 200, 'auth_type' => auth()->via(['api_key']), 'user' => auth()->user(['api_key'])];
	}

	public function oauthOnly(Request $request): array
	{
		return ['code' => 200, 'auth_type' => auth()->via(['oauth']), 'user' => auth()->user(['oauth'])];
	}

	public function basicOnly(Request $request): array
	{
		return ['code' => 200, 'auth_type' => auth()->via(['basic']), 'user' => auth()->user(['basic'])];
	}

	public function digestOnly(Request $request): array
	{
		return ['code' => 200, 'auth_type' => auth()->via(['digest']), 'user' => auth()->user(['digest'])];
	}

	public function fallback(Request $request): array
	{
		return [
			'code' => 200,
			'auth_type' => auth()->via(['jwt', 'api_key', 'token']),
			'user' => auth()->user(['jwt', 'api_key', 'token']),
		];
	}
}
```

Routes:

```php
$router->group(['prefix' => '/api/v2'], function ($router) {
	$router->get('/session-only', [AuthTypeDemoController::class, 'sessionOnly'])->middleware('auth:session');
	$router->get('/token-only', [AuthTypeDemoController::class, 'tokenOnly'])->middleware('auth:token');
	$router->get('/jwt-only', [AuthTypeDemoController::class, 'jwtOnly'])->middleware('auth:jwt');
	$router->get('/api-key-only', [AuthTypeDemoController::class, 'apiKeyOnly'])->middleware('auth:api_key');
	$router->get('/oauth-only', [AuthTypeDemoController::class, 'oauthOnly'])->middleware('auth:oauth');
	$router->get('/basic-only', [AuthTypeDemoController::class, 'basicOnly'])->middleware('auth:basic');
	$router->get('/digest-only', [AuthTypeDemoController::class, 'digestOnly'])->middleware('auth:digest');
	$router->get('/fallback', [AuthTypeDemoController::class, 'fallback'])->middleware('auth:jwt,api_key,token');
});
```

### 3) Create personal access token

```php
$plainToken = auth()->createToken(
	userId: $user['id'],
	name: 'Mobile App',
	expiresAt: time() + (30 * 24 * 60 * 60), // 30 days
	abilities: ['read', 'write']
);
// Return $plainToken to client — it cannot be retrieved again
```

### 4) Social login

```php
$result = auth()->socialite('google', $googleUserData, function (int $userId, array $socialUser): void {
	// Optional post-create hook.
	// Example: assign default role/permission records by $userId.
});

if (($result['code'] ?? 500) === 200) {
	// socialite() already logs in the user via session.
	return ['code' => 200, 'user_id' => $result['user_id']];
}

return ['code' => $result['code'] ?? 500, 'message' => $result['message'] ?? 'OAuth failed'];
```

### 5) Revoke all tokens on password change

```php
auth()->revokeAllTokens($userId);
```

## How To Use

1. Use `attempt()` only for verification; call `login()` after success.
2. Use `via()` to determine auth mode when logic differs by context.
3. Keep token abilities minimal and endpoint-focused.
4. Use `revokeAllTokens()` on security-sensitive account actions (password change, etc.).
5. Use `bearerToken()` or `checkToken()` for API-specific auth flows.

## What To Avoid

- Avoid mixing session and token assumptions in business logic; inspect `via()` when needed.
- Avoid oversized session payloads in `login()` data.
- Avoid storing plain tokens in database — framework hashes them automatically.

## Benefits

- Single auth API for both browser and API contexts.
- Built-in token lifecycle with hashing, abilities, and expiration.
- Social login with auto-create callback.
- Schema-flexible through config mappings.

## Evidence

- `systems/Components/Auth.php`
- `app/config/auth.php`
- `app/config/api.php` (token table fallback source)
