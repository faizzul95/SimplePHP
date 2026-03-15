# 15. Auth Component Reference

## Session + Token Unified Auth (`Components\Auth`)

Supported methods:
- Session
- Token (personal access token)
- JWT (HS256)
- API Key
- OAuth (social login-backed session)
- Basic Auth
- Digest Auth

### Identity Resolution

- `check(array|string|null $methods = null): bool` — True if authenticated via any provided method(s). Defaults to configured order.
- `guest(array|string|null $methods = null): bool` — Opposite of `check()`.
- `via(array|string|null $methods = null): ?string` — Returns resolved method (`session`, `token`, `jwt`, `api_key`, `oauth`, `basic`, or `digest`) or `null`.
- `id(array|string|null $methods = null): ?int` — Authenticated user ID using configured/provided methods.
- `user(array|string|null $methods = null): ?array` — Authenticated user record using configured/provided methods.
- `checkAny(array|string $methods): bool` — Alias of `check($methods)`.
- `sessionUser(): ?array` — Session-authenticated user record.
- `tokenUser(): ?array` — Token-authenticated user record.
- `jwtUser(): ?array` — JWT-authenticated user record.
- `apiKeyUser(): ?array` — API-key-authenticated user record.
- `basicUser(): ?array` — Basic-authenticated user record.
- `digestUser(): ?array` — Digest-authenticated user record.
- `oauthUser(): ?array` — OAuth session-authenticated user record.
- `checkSession(): bool` — True if session auth is active.
- `checkToken(): bool` — True if bearer token auth is active.
- `checkJwt(): bool` — True if JWT auth is active.
- `checkApiKey(): bool` — True if API key auth is active.
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

JWT/Digest hardening:
- JWT validation enforces configured algorithm and verifies signature/time-window claims.
- Digest validation enforces realm/opaque/qop, binds digest URI to current request URI, validates nonce TTL/skew, and rejects replayed nonce counters.

### Social Login

- `socialite(string $provider, array $socialUser, ?callable $onCreateCallback = null): array` — OAuth login/register. Creates user if not found.
- Enabled via `auth.socialite_enabled` config.

### Config Access

- `getConfig(?string $key = null): mixed` — Get auth config value or all config.

### Configurable Mappings

Auth uses config-driven table/column/session key maps:
- `auth.methods` secure default resolution order (`['session', 'token']`) with route-level opt-in for additional methods
- `auth.api_methods` default methods for `auth.api` middleware (`['token']` by default)
- `auth.jwt` JWT validation config (`enabled`, `secret`, `algo`, `leeway`, `user_id_claim`)
- `auth.api_key` API key extraction/table-column config
- `auth.basic` Basic auth realm + identifier columns
- `auth.digest` Digest auth realm/qop/nonce policy + column mappings
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
$config['auth']['api_methods'] = ['jwt', 'api_key', 'token'];

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
- Keep defaults least-privilege (`methods` => `['session', 'token']`, `api_methods` => `['token']`).
- Enable only methods required per route/environment.

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
// $config['auth']['api_methods'] = ['token', 'jwt', 'api_key', 'basic', 'digest'];
```

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
