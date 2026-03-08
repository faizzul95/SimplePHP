# 15. Auth Component Reference

## Session + Token Unified Auth (`Components\Auth`)

### Identity Resolution

- `check(): bool` — True if authenticated via session OR token.
- `guest(): bool` — Opposite of `check()`.
- `via(): ?string` — Returns `'session'`, `'token'`, or `null`.
- `id(): ?int` — Authenticated user ID (session or token).
- `user(): ?array` — Authenticated user record (session or token).
- `sessionUser(): ?array` — Session-authenticated user record.
- `tokenUser(): ?array` — Token-authenticated user record.
- `checkSession(): bool` — True if session auth is active.
- `checkToken(): bool` — True if bearer token auth is active.
- `bearerToken(): ?string` — Extract bearer token from Authorization header.

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

### Social Login

- `socialite(string $provider, array $socialUser, ?callable $onCreateCallback = null): array` — OAuth login/register. Creates user if not found.
- Enabled via `auth.socialite_enabled` config.

### Config Access

- `getConfig(?string $key = null): mixed` — Get auth config value or all config.

### Configurable Mappings

Auth uses config-driven table/column/session key maps:
- `auth.users_table`, `auth.token_table`
- `auth.token_columns` (id, user_id, name, token, abilities, expires_at)
- `auth.user_columns` (id, email, password, name)
- `auth.session_keys` (user_id, user_name, user_email, logged_in)

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
$user = auth()->socialite('google', $googleUserData, function ($provider, $socialUser) {
	// Custom user creation callback
	return [
		'name' => $socialUser['name'],
		'email' => $socialUser['email'],
		'role_id' => 2, // default role
	];
});
auth()->login((int) $user['id']);
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

- `systems/Components/Auth.php` (614 lines)
- `app/config/auth.php`
- `app/config/api.php` (token table fallback source)
