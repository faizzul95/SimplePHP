# 03. Auth, Tokens, and API

## Auth Component (`Components\Auth`)

### Authentication Modes

- Session auth (`checkSession`) using configurable session keys.
- Bearer token auth (`checkToken`/`tokenUser`) using hashed token storage.
- Unified check (`check`) accepts either mode.
- `via()` reports `session`, `token`, or `null`.

### Session Methods

- `attempt(credentials)` verifies password hash and returns user (without password).
- `login(userId, sessionData)` regenerates session ID and stores configured session fields.
- `loginUsingId(...)` alias for `login`.
- `logout(destroySession=false)` clears configured auth session keys.

### Token Methods

- `createToken(userId, name, expiresAt?, abilities)` stores SHA-256 token hash.
- `revokeToken(plainToken)`, `revokeCurrentToken()`, `revokeAllTokens(userId)`.
- `hasAbility(ability)` supports wildcard `*`.
- Token table auto-created by `ensureTokenTable()` when needed.

### Social Login Hook

- `socialite(provider, socialUser, onCreateCallback?)` exists.
- Controlled by `auth.socialite_enabled` config.
- Supports provider/provider_id linking and account creation logic in DB.

## API Component (`Components\Api`)

### Core Features

- Route registration for GET/POST/PUT/DELETE.
- Regex route params via `{param}` syntax.
- CORS header handling and OPTIONS preflight exit.
- Optional token auth (`auth.required`) with bearer token lookup.
- Ability checks (`hasAbility`).
- IP and URL whitelisting.
- Rate limiting via DB table (`api_rate_limits` by config).
- JSON input helper (`getJsonInput`) and JSON response/error output.

### Token Security

- Plain tokens are never stored directly; SHA-256 hash is stored.
- Expired tokens are rejected (`expires_at`).
- `last_used_at` updated on successful auth.

## Examples

### Session auth check in middleware flow

```php
if (!auth()->checkSession()) {
	// redirect or JSON 401 depending on request type
}
```

### Token creation for API access

```php
$token = auth()->createToken($userId, 'api-access', time() + 86400, ['users.read']);
```

### Standalone API route registration

```php
$api->get('/v1/users/{id}', function ($currentUser, $id) {
	return ['code' => 200, 'id' => $id, 'by' => $currentUser['id'] ?? null];
});
```

## How To Use

1. Use `auth.web` for browser-only pages.
2. Use `auth.api` for bearer-token endpoints.
3. Use `auth` when both session and token should be accepted.
4. Store token abilities and check with `hasAbility` when endpoint-level authorization is needed.

## What To Avoid

- Avoid storing plain tokens in DB or logs.
- Avoid using `auth.required=false` globally unless endpoint is intentionally public.
- Avoid duplicating token-table configuration between files inconsistently.

## Benefits

- One auth component for both web and API.
- Secure token lifecycle with hashing + expiration.
- Config-driven identity mapping keeps schema changes manageable.

## Evidence

- `systems/Components/Auth.php`
- `systems/Components/Api.php`
- `app/config/auth.php`
- `app/config/api.php`
