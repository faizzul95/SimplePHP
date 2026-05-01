# 03. Auth, Tokens, and API

## Auth Component (`Components\Auth`)

### Authentication Modes

- Session auth (`checkSession`) using configurable session keys.
- Bearer token auth (`checkToken`/`tokenUser`) using hashed token storage.
- Unified check (`check`) accepts either mode.
- `via()` reports `session`, `token`, or `null`.
- Bootstrap can skip PHP session startup for CLI and stateless API/mobile requests; token, oauth2, jwt, api_key, basic, and digest flows do not require an active PHP session.
- Runtime helpers can inspect `BOOTSTRAP_RUNTIME`, `BOOTSTRAP_SESSION_ENABLED`, and `BOOTSTRAP_STATEFUL_REQUEST` when behavior needs to differ between browser, API/mobile, and CLI entry points.

### Session Methods

- `attempt(credentials)` verifies password hash and returns user (without password).
- `login(userId, sessionData)` regenerates session ID and stores configured session fields.
- `loginUsingId(...)` alias for `login`.
- `logout(destroySession=false)` clears configured auth session keys.

### Token Methods

- `createToken(userId, name, expiresAt?, abilities)` stores SHA-256 token hash and returns `token_id|secret` when insert ids are available, with legacy plain-token fallback for older rows/drivers.
- `revokeToken(plainToken)`, `revokeCurrentToken()`, `revokeAllTokens(userId)`.
- `tokens()`, `currentToken()`, and `rotateToken()` expose personal access token lifecycle for the currently authenticated principal.
- `apiCredentialMethods()` resolves the enabled API credential types available for issuance (`token`, `oauth2`).
- `issueApiCredential(userId, method, name, expiresAt?, abilities)` issues only enabled credential types and returns method metadata plus the plain credential.
- `hasAbility(ability)` supports wildcard `*`.
- Token table auto-created by `ensureTokenTable()` when needed.

### Current-user auth endpoints

- `GET /api/v1/auth/devices` returns the current session user's active browser sessions from the session-concurrency registry.
- `DELETE /api/v1/auth/devices/{sessionId}` revokes one browser session; revoking the current session logs it out.
- `POST /api/v1/auth/logout-other-devices` requires the current session password and keeps only the active browser session.
- `GET /api/v1/auth/tokens` lists personal access tokens for the currently authenticated user.
- `GET /api/v1/auth/tokens/current` returns metadata for the active personal access token when token auth is in use.
- `POST /api/v1/auth/tokens/rotate` rotates the active personal access token and returns the replacement bearer credential.

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

- Plain token secrets are never stored directly; SHA-256 hash is stored, and newer personal access tokens are exposed as `token_id|secret` so lookup/revocation can target a single row first.
- Expired tokens are rejected (`expires_at`).
- `last_used_at` updated on successful auth.

## Examples

### Session auth check in middleware flow

```php
if (!auth()->checkSession()) {
	// redirect or JSON 401 depending on request type
}
```

### Issue an API credential (preferred)

Prefer `issueApiCredential()` over calling `createToken()` directly. It honors the enabled-methods gate, records method metadata on the response, and will route to OAuth2 issuance when the caller selects it.

```php
$credential = auth()->issueApiCredential(
    userId: $userId,
    method: 'token',          // or 'oauth2' when oauth2 is enabled
    name: 'mobile-app',
    expiresAt: time() + 86400,
    abilities: ['users.read']
);
// ['method' => 'token', 'credential' => '123|...', 'token_type' => 'Bearer', 'expires_at' => ...]
```

Legacy path — raw token creation (still supported, but skips the method gate and returns only the credential string):

```php
$token = auth()->createToken($userId, 'api-access', time() + 86400, ['users.read']);
```

See [15-auth-component-reference.md](15-auth-component-reference.md#api-credential-resolution) for the full credential-issuance reference including `apiCredentialMethods()` and `preferredApiMethod()`.

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
5. Use shared controller classes for both web and API routes; prefer explicit API methods like `loginApi`, `me`, and `logout` instead of a dedicated `Controllers\Api` namespace.
6. Keep mobile/API clients on stateless credentials by default; only enable bootstrap session for API through `framework.bootstrap.session.api` when a route intentionally needs cookie-backed state.

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
- `bootstrap.php`
- `app/config/auth.php`
- `app/config/api.php`
- `app/config/framework.php`
