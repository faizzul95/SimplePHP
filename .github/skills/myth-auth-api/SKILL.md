---
name: myth-auth-api
description: >
  Auth and API development guide for MythPHP. Use when: implementing login/logout,
  session auth, bearer token auth, JWT, OAuth2, API keys, Basic/Digest auth, issuing
  API credentials, RBAC permissions, token abilities, session concurrency, device
  management, social login, building or securing API endpoints, registering API routes,
  handling CORS, rate limiting, API request logging, or designing API response shapes.
argument-hint: 'Topic, e.g. "token issuance", "session auth", "JWT", "API route", "RBAC"'
---

# MythPHP Auth & API Guide

## When to Use This Skill

Load this skill when working on authentication, authorization, or API endpoints.
The auth system supports 8 methods — do not assume Laravel Passport/Sanctum APIs.

---

## Auth Component Overview

Reference: [03-auth-tokens-api.md](../../../docs/framework_knowledge/03-auth-tokens-api.md) · [15-auth-component-reference.md](../../../docs/framework_knowledge/15-auth-component-reference.md)

**Entry point:** `auth()` — returns `App\Support\Auth\AuthManager` (extends `Components\Auth`).

### Supported Auth Methods

| Method | How to check | Use case |
|--------|-------------|----------|
| `session` | `auth()->checkSession()` | Browser apps |
| `token` | `auth()->checkToken()` | Mobile / personal access tokens |
| `jwt` | `auth()->checkJwt()` | Stateless JWT bearer |
| `api_key` | `auth()->checkApiKey()` | Server-to-server |
| `oauth2` | `auth()->checkOAuth2()` | Third-party OAuth2 bearer |
| `basic` | `auth()->checkBasic()` | HTTP Basic Auth |
| `digest` | `auth()->checkDigest()` | HTTP Digest Auth |
| `oauth` | `auth()->checkOAuth()` | Social login-backed session |

---

## 1. Session Authentication

```php
// Attempt login (verifies password_verify)
$user = auth()->attempt(['email' => $email, 'password' => $password]);
if (!$user) {
    $this->errorResponse('Invalid credentials', 401);
}

// Start session (regenerates session ID, stores user data)
auth()->login($user['id'], [
    'id'    => $user['id'],
    'name'  => $user['name'],
    'email' => $user['email'],
    'role'  => $user['role'],
]);

// Check session
if (!auth()->checkSession()) {
    redirect()->to('/login');
}

// Get session user
$user   = auth()->sessionUser();
$userId = auth()->id();

// Logout
auth()->logout();
auth()->logout(destroySession: true);   // Full session destroy
```

---

## 2. Token (Personal Access Token) — Issuance

**Always use `issueApiCredential()` — not raw `createToken()`** — for new code.
It enforces the enabled-methods gate and records method metadata on the response.

```php
// Issue a token (preferred path)
$credential = auth()->issueApiCredential(
    userId: $userId,
    method: 'token',
    name: 'mobile-app',
    expiresAt: time() + (30 * 86400),    // 30 days
    abilities: ['users.read', 'orders.write']
);
// Returns: ['method' => 'token', 'credential' => '123|secret', 'token_type' => 'Bearer', 'expires_at' => ...]

// Return to client
$this->successResponse('Token issued', $credential);
```

### Token lifecycle

```php
auth()->revokeCurrentToken();            // Revoke active bearer token
auth()->revokeToken($plainToken);        // Revoke by plain token string
auth()->revokeAllTokens($userId);        // Revoke all user tokens
auth()->rotateToken();                   // Rotate active token (returns new credential)

// Token abilities check
auth()->hasAbility('orders.write');      // true/false — supports wildcard '*'
```

### List / inspect tokens

```php
$tokens  = auth()->tokens();             // All tokens for authenticated user
$current = auth()->currentToken();       // Active token metadata
```

---

## 3. Session Concurrency & Device Management

Reference: [15-auth-component-reference.md](../../../docs/framework_knowledge/15-auth-component-reference.md)

```php
// GET  /api/v1/auth/devices              — list active browser sessions
// DELETE /api/v1/auth/devices/{sessionId} — revoke one session
// POST /api/v1/auth/logout-other-devices  — keep only current session (requires password)
```

Configure via `auth.max_devices` to limit concurrent sessions.

---

## 4. RBAC — Permissions & Roles

```php
// Route-level (preferred)
$router->get('/admin', [AdminController::class, 'index'])
    ->webAuth()
    ->permission('admin.access');         // Single permission required
    // OR
    ->permissionAny(['admin.view', 'manager.view']);  // Any of these

// Controller-level (for action-level checks)
$this->authorizeOrFail('admin.access');   // Terminates with 403 on failure

// Conditional checks
if ($this->can('reports.export')) {
    // show export button
}
if ($this->cannot('users.delete')) {
    $this->errorResponse('Forbidden', 403);
}

// Blade directives
@can('reports.export')
    <button>Export</button>
@endcan
```

---

## 5. API Route Patterns

Reference: [02-routing-http-flow.md](../../../docs/framework_knowledge/02-routing-http-flow.md) · [16-api-component-reference.md](../../../docs/framework_knowledge/16-api-component-reference.md)

### API route groups (`app/routes/api.php`)

```php
// Public API (no auth — rate limited only)
$router->group(['middleware' => ['api.public.submit']], function ($router) {
    $router->post('/api/v1/auth/login', [AuthController::class, 'loginApi'])
        ->middleware('aggressive-throttle')
        ->name('api.auth.login');
});

// Authenticated API (Bearer token required)
$router->group(['prefix' => '/api/v1', 'middleware' => ['api.external.auth']], function ($router) {
    $router->get('/auth/me', [AuthController::class, 'me'])->name('api.auth.me');
    $router->get('/auth/tokens', [AuthController::class, 'tokens'])->name('api.auth.tokens');
    $router->post('/auth/tokens/rotate', [AuthController::class, 'rotateToken'])->name('api.auth.tokens.rotate');
    $router->post('/auth/logout', [AuthController::class, 'logout'])->name('api.auth.logout');
});

// App API (session OR token auth)
$router->group(['prefix' => '/api/v1', 'middleware' => ['api.app']], function ($router) {
    $router->get('/users', [UserController::class, 'list'])->name('api.users.list');
});
```

### Shared controller — web + API actions on same class

```php
class AuthController extends \Core\Http\Controller
{
    // Browser login page
    public function login(): void
    {
        $this->view('auth.login');
    }

    // API login endpoint
    public function loginApi(LoginRequest $request): void
    {
        $user = auth()->attempt($request->validated());
        if (!$user) {
            jsonResponse(['code' => 401, 'message' => 'Invalid credentials']);
        }
        $credential = auth()->issueApiCredential($user['id'], 'token', 'api', null, ['*']);
        jsonResponse(['code' => 200, 'message' => 'Authenticated', 'data' => $credential]);
    }

    // GET /api/v1/auth/me
    public function me(): void
    {
        jsonResponse(['code' => 200, 'data' => auth()->user()]);
    }

    // POST /api/v1/auth/logout
    public function logout(): void
    {
        if (auth()->checkToken()) {
            auth()->revokeCurrentToken();
        } else {
            auth()->logout();
        }
        jsonResponse(['code' => 200, 'message' => 'Logged out']);
    }
}
```

---

## 6. API Middleware Groups Reference

| Group | Middleware stack | Use for |
|-------|-----------------|---------|
| `api` | `headers, trusted.hosts, trusted.proxies, payload.limits, content.type, request.fingerprint, request.safety, throttle:api, xss, api.log` | All API routes (applied by RouteServiceProvider) |
| `api.public.submit` | `api` + `throttle:auth` | Public form submissions |
| `api.external.auth` | `api` + `auth.api` | External bearer-token endpoints |
| `api.app` | `api` + `auth` | Internal app API (session or token) |
| `api.upload.image` | `api.app` + `permission:user-upload-profile` + `upload.guard:image-cropper` | Image upload routes |
| `api.upload.action` | `api.app` + `permission:user-upload-profile` + `upload.guard:delete` | Upload management routes |

---

## 7. API Response Shapes

All JSON responses use `jsonResponse([...])` directly:

```php
// Success with data
jsonResponse(['code' => 200, 'message' => 'OK', 'data' => $data]);

// Created
jsonResponse(['code' => 201, 'message' => 'Created', 'data' => ['id' => $id]]);

// Error
jsonResponse(['code' => 422, 'message' => 'Validation failed']);

// Unauthorized
jsonResponse(['code' => 401, 'message' => 'Invalid credentials']);

// Forbidden
jsonResponse(['code' => 403, 'message' => 'Unauthorized']);

// DataTable — pass paginate_ajax result directly
jsonResponse($result);
```

---

## 8. Social Login

```php
// auth.socialite_enabled must be true in config/auth.php
auth()->socialite('google', $googleUserObject, function ($provider, $socialUser) {
    // Called when no linked account exists — create user and return user_id
    $id = db()->table('users')->insert([
        'name'  => $socialUser->getName(),
        'email' => $socialUser->getEmail(),
    ]);
    return $id;
});
```

---

## 9. Auth Checklist

- [ ] Login routes use `->guestOnly()` to block already-authenticated users.
- [ ] Login routes use `->middleware('aggressive-throttle')` for brute-force protection.
- [ ] `issueApiCredential()` used instead of raw `createToken()`.
- [ ] `hasAbility()` checked before privileged token actions.
- [ ] `revokeAllTokens()` called on password reset or account suspension.
- [ ] `authorizeOrFail('permission.slug')` or explicit ownership check (`$record['user_id'] === $this->authId()`) on every record-access endpoint.
- [ ] `sensitiveFields()` declared in FormRequests for `password`, `token`, `secret`.
- [ ] `auth.max_devices` configured if session concurrency limits are required.
- [ ] `php myth auth:security:test` passes before deploying auth changes.
