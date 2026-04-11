# 16. API Component Reference

## Overview

`Components\Api` is a standalone PDO-based API utility with internal route registry, configurable multi-auth authentication (session/token/JWT/API key/OAuth/Basic/Digest), CORS, rate limiting, and JSON response handling. It operates independently from the main Router.

Source: `systems/Components/Api.php` (~540 lines).  
Config: `app/config/api.php`.

## Current Behavioral Notes

- Configured table and column identifiers are validated strictly. Invalid values now fail fast instead of silently falling back to a different table or column name.
- `handleRequest()` catches `\Throwable`, so PHP runtime errors in callbacks or downstream auth resolution are routed through the API error response path.
- Rate-limit tracking performs the count-and-record step inside a database transaction when possible, which reduces race conditions under concurrent traffic.

## Complete API Reference

### Route Registration

| Method | Signature | Description |
|--------|-----------|-------------|
| `get` | `get(string $uri, callable $callback): void` | Register GET route |
| `post` | `post(string $uri, callable $callback): void` | Register POST route |
| `put` | `put(string $uri, callable $callback): void` | Register PUT route |
| `patch` | `patch(string $uri, callable $callback): void` | Register PATCH route |
| `delete` | `delete(string $uri, callable $callback): void` | Register DELETE route |
| `options` | `options(string $uri, callable $callback): void` | Register OPTIONS route |

Routes support `{param}` patterns (converted to named regex groups internally).

### Request Handling

| Method | Signature | Return | Description |
|--------|-----------|--------|-------------|
| `handleRequest` | `handleRequest(): void` | `void` | Execute full request pipeline: CORS → rate limit → route match → auth check → execute callback → JSON response |
| `getJsonInput` | `getJsonInput(): array` | `array` | Read and decode `php://input` as JSON |

### Authentication & Authorization

| Method | Signature | Return | Description |
|--------|-----------|--------|-------------|
| `generateToken` | `generateToken(int $userId, string $name, ?int $expiresAt = null, array $abilities = []): string` | `string` | Create 80-char hex token. Hashes with SHA-256 before storage. Returns plain token. |
| `getCurrentUser` | `getCurrentUser(): ?array` | `?array` | Get authenticated user data (resolved from configured auth methods) |
| `hasAbility` | `hasAbility(string $ability): bool` | `bool` | Check if current token grants ability. Supports wildcard `*`. |
| `revokeToken` | `revokeToken(string $plainToken): bool` | `bool` | Revoke a specific token by its plain-text value |
| `revokeAllUserTokens` | `revokeAllUserTokens(int $userId): int` | `int` | Revoke all tokens for a user. Returns count of deleted tokens. |
| `getUserTokens` | `getUserTokens(int $userId): array` | `array` | Get all active (non-expired) tokens for a user, ordered by `created_at DESC` |

### Built-in Security Pipeline

The `handleRequest()` method executes this sequence automatically:

1. **CORS handling** — Sets `Access-Control-Allow-*` headers. Auto-responds to `OPTIONS` preflight.
2. **Rate limiting** — Enforces per-IP request limits using configurable DB table. Skips whitelisted IPs/URLs. The read-and-insert step is wrapped in a transaction when supported by the PDO driver.
3. **Route matching** — Finds matching route (exact or regex with `{param}` extraction).
4. **Authentication** — Resolves configured auth methods (`auth.methods`) in order. Skips for whitelisted URLs.
5. **Callback execution** — Runs route callback, captures return value.
6. **JSON response** — Outputs JSON with appropriate status code.

## Config (`app/config/api.php`)

```php
$config['api'] = [
    'cors' => [
        'allow_origin' => ['*'],                    // Restrict in production
        'allow_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
        'allow_headers' => ['Content-Type', 'Authorization', 'X-Requested-With'],
        'allow_credentials' => false,               // true only with explicit origins
        'allow_wildcard_with_auth' => false,        // keep false for authenticated APIs
    ],
    'auth' => [
        'required' => true,
        'methods' => ['token'],                    // Supported: session, token, jwt, api_key, oauth, basic, digest
    ],
    'token_table' => 'users_access_tokens',         // Token storage table
    'rate_limit_table' => 'api_rate_limits',        // Rate limit tracking table
    'log_errors' => true,                           // Log API errors
    'ip_whitelist' => ['127.0.0.1', '::1'],         // Skip rate limiting for these IPs
    'url_whitelist' => ['/v1/auth/login'],           // Skip auth for these endpoints
    'logging' => [
        'enabled' => false,                         // Per-request API logging
        'log_path' => 'logs/api.log',
    ],
];
```

### Config Safety

- `token_table` and `rate_limit_table` must resolve to valid SQL identifiers made from letters, digits, and underscores.
- Invalid configured identifiers are treated as configuration errors rather than being silently replaced with unrelated defaults.

### Storage Tables

Both tables are managed via database migrations (run `php myth migrate`):

- **Token table** (`users_access_tokens`): Migration `20260308_005_create_users_access_tokens_table.php` — stores hashed tokens with user ID, name, abilities JSON, expiry, and FK to users.
- **Rate limit table** (`api_rate_limits`): Migration `20260308_006_create_api_rate_limits_table.php` — tracks request counts per IP per time window with composite index.

## Examples

### 1) Complete API setup with login endpoint

```php
// app/routes/api.php
$api = new \Components\Api(db()->getPdo(), config('api'));

// Public endpoint (whitelisted in config)
$api->post('/v1/auth/login', function () use ($api) {
    $input = $api->getJsonInput();
    
    $user = db()->table('users')
        ->where('email', $input['email'])
        ->whereNull('deleted_at')
        ->fetch();
    
    if (!$user || !password_verify($input['password'], $user['password'])) {
        return ['code' => 401, 'message' => 'Invalid credentials'];
    }
    
    $token = $api->generateToken(
        $user['id'],
        'login-token',
        time() + (86400 * 30),             // expires in 30 days
        ['users.read', 'users.write']       // abilities
    );
    
    return ['code' => 200, 'token' => $token, 'user' => $user];
});

$api->handleRequest();
```

### 1b) Configure API auth methods (all supported types)

```php
// app/config/api.php
$config['api']['auth'] = [
    'required' => true,
    'methods' => ['jwt', 'api_key', 'token', 'basic', 'digest', 'session', 'oauth'],
];
```

Header examples:

```http
Authorization: Bearer <personal_access_token>   # token
Authorization: Bearer <jwt_token>               # jwt
X-API-KEY: <api_key>                            # api_key
Authorization: ApiKey <api_key>                 # api_key alternative
Authorization: Basic <base64(username:password)># basic
Authorization: Digest username="...", ...      # digest
```

### 2) Protected CRUD endpoints with ability checks

```php
// GET /v1/users — list users
$api->get('/v1/users', function () use ($api) {
    if (!$api->hasAbility('users.read')) {
        return ['code' => 403, 'message' => 'Forbidden'];
    }
    
    $users = db()->table('users')
        ->select('id, name, email, created_at')
        ->whereNull('deleted_at')
        ->get();
    
    return ['code' => 200, 'data' => $users];
});

// GET /v1/users/{id} — single user with route param
$api->get('/v1/users/{id}', function ($params) use ($api) {
    if (!$api->hasAbility('users.read')) {
        return ['code' => 403, 'message' => 'Forbidden'];
    }
    
    $user = db()->table('users')
        ->where('id', $params['id'])
        ->whereNull('deleted_at')
        ->fetch();
    
    if (!$user) {
        return ['code' => 404, 'message' => 'User not found'];
    }
    
    return ['code' => 200, 'data' => $user];
});

// POST /v1/users — create user
$api->post('/v1/users', function () use ($api) {
    if (!$api->hasAbility('users.write')) {
        return ['code' => 403, 'message' => 'Forbidden'];
    }
    
    $input = $api->getJsonInput();
    $result = db()->table('users')->insert([
        'name' => $input['name'],
        'email' => $input['email'],
        'password' => password_hash($input['password'], PASSWORD_DEFAULT),
        'created_at' => date('Y-m-d H:i:s'),
    ]);
    
    return ['code' => $result['code'], 'id' => $result['id'] ?? null];
});

// DELETE /v1/users/{id}
$api->delete('/v1/users/{id}', function ($params) use ($api) {
    if (!$api->hasAbility('users.delete')) {
        return ['code' => 403, 'message' => 'Forbidden'];
    }
    
    db()->table('users')->where('id', $params['id'])->softDelete();
    return ['code' => 200, 'message' => 'User deleted'];
});
```

### 3) Token management — generate, list, revoke

```php
// Generate token with wildcard abilities (full access)
$adminToken = $api->generateToken($userId, 'admin-token', null, ['*']);

// Generate token with expiry and limited abilities
$readOnlyToken = $api->generateToken(
    $userId,
    'readonly-token',
    time() + 3600,                   // 1 hour
    ['users.read', 'orders.read']
);

// List all user tokens
$tokens = $api->getUserTokens($userId);
// [['id' => 1, 'name' => 'admin-token', 'created_at' => '...'], ...]

// Revoke specific token
$api->revokeToken($plainToken);

// Revoke all user tokens (e.g., on password change)
$count = $api->revokeAllUserTokens($userId);
// Returns number of revoked tokens
```

### 4) Accessing the current authenticated user

```php
$api->get('/v1/profile', function () use ($api) {
    $user = $api->getCurrentUser();
    
    if (!$user) {
        return ['code' => 401, 'message' => 'Not authenticated'];
    }
    
    return ['code' => 200, 'data' => [
        'id' => $user['id'],
        'name' => $user['name'],
        'email' => $user['email'],
        'abilities' => $user['abilities'],
    ]];
});
```

### 5) API versioning pattern

```php
// Version 1
$api->get('/v1/products', function () use ($api) {
    return ['code' => 200, 'data' => db()->table('products')->get()];
});

// Version 2 — different response structure
$api->get('/v2/products', function () use ($api) {
    $products = db()->table('products')
        ->withCount('reviews', 'product_reviews', 'product_id', 'id')
        ->get();
    return ['code' => 200, 'data' => $products, 'version' => 'v2'];
});
```

## How To Use

1. Configure CORS, auth, rate limiting in `app/config/api.php`.
2. Set `api.auth.methods` in preferred order (first successful method wins).
3. Whitelist public endpoints (like login) in `url_whitelist`.
4. Register routes with `$api->get/post/put/patch/delete/options()`.
5. Call `$api->handleRequest()` to execute the pipeline.
6. Use `$api->hasAbility()` inside callbacks for fine-grained access control.
7. Use `{param}` in URI patterns for route parameters.

## What To Avoid

- Avoid enabling `allow_origin => ['*']` in production without restricting to known domains.
- Avoid storing plain tokens — the component already hashes them with SHA-256.
- Avoid skipping ability checks in sensitive endpoints.
- Avoid treating this as a replacement for the main Router middleware pipeline (different architecture).
- Avoid returning sensitive data (passwords, tokens) in API responses.

## Benefits

- Complete standalone API flow: routing, auth, CORS, rate limiting in one class.
- Token-based auth with ability system (fine-grained permissions).
- Auto-initializing database tables (zero setup for token/rate-limit storage).
- IP and URL whitelisting for flexible security configuration.
- Clean JSON response handling with proper HTTP status codes.

## Evidence

- `systems/Components/Api.php`
- `app/config/api.php`
- `app/routes/api.php`
