---
name: myth-security
description: >
  Security review, audit, and hardening for the MythPHP codebase. Use when:
  reviewing code for OWASP Top 10 vulnerabilities; auditing auth, tokens, sessions,
  CSRF, XSS, SQL injection, file upload safety, CSP headers, rate limiting, IDOR,
  input validation, middleware configuration, security headers,
  running security:audit or auth:security:test commands; fixing insecure code patterns;
  implementing security checks in controllers, FormRequests, or routes.
argument-hint: 'Area to audit, e.g. "upload", "auth tokens", "XSS", "SQL injection"'
---

# MythPHP Security Review & Hardening

## When to Use This Skill

Load this skill when reviewing, auditing, or fixing security issues anywhere in the
MythPHP codebase. All checks below are verified against the actual framework
implementation — do not assume Laravel-equivalent security APIs.

---

## Security Audit Commands

Run these CLI commands before and after security changes:

```bash
php myth security:audit          # OWASP-aligned baseline checks
php myth security:audit --strict # Fail on warnings too (for CI)
php myth security:audit --ci     # Machine-readable exit code

php myth auth:security:test          # Auth hardening tests
php myth auth:security:test --strict # Strict mode for CI
```

See [19-console-built-in-commands.md](../../../docs/framework_knowledge/19-console-built-in-commands.md) for full flag list.

---

## OWASP Top 10 — Framework Coverage

### A01 Broken Access Control

**RBAC Middleware:**

```php
// Route-level — preferred
$router->get('/admin/users', [UserController::class, 'index'])
    ->webAuth()
    ->permission('users.manage');      // RequirePermission middleware

// Controller-level fallback — for action-level checks only
$this->authorizeOrFail('users.manage');

// Ownership check (IDOR prevention) — plain integer ID
public function show(int|string $id): void
{
    $record = $this->findOrFail('posts', $id, '*', false, 'Post not found');

    // Verify caller owns this record
    if ((int) $record['user_id'] !== $this->authId()) {
        jsonResponse(['code' => 403, 'message' => 'Forbidden']);
    }

    jsonResponse(['code' => 200, 'data' => $record]);
}
```

- Never skip ownership verification on record-access endpoints.
- Route middleware handles bulk access; controller handles per-record ownership.
- Reference: [11-controller-base-pattern.md](../../../docs/framework_knowledge/11-controller-base-pattern.md)

### A02 Cryptographic Failures

**Token storage:**
- Tokens are stored as SHA-256 hashes — never plain text.
- `createToken()` returns `token_id|secret` format; `token_id` alone cannot be used to authenticate.
- Rotate tokens via `auth()->rotateToken()`.

**Password:**
- `auth()->attempt()` uses `password_verify()` — bcrypt by default.

**Anti-patterns:**
```php
// WRONG — stores plain token
$token = bin2hex(random_bytes(32));
db()->table('tokens')->insert(['token' => $token]);

// CORRECT — use the framework token API
$credential = auth()->issueApiCredential($userId, 'token', 'app-name', time() + 86400, ['read']);
```

Reference: [03-auth-tokens-api.md](../../../docs/framework_knowledge/03-auth-tokens-api.md) · [15-auth-component-reference.md](../../../docs/framework_knowledge/15-auth-component-reference.md)

### A03 Injection

**SQL Injection — two-layer defense:**

1. **Parameterized query builder** — never use raw `whereRaw()` with unsanitized user input.
2. **Mass-assignment guard** — schema-level column filter always active; declare `$fillable` or `$guarded` on models.

```php
// SAFE — parameterized
db()->table('users')->where('email', $email)->first();

// DANGER — raw with user input
db()->table('users')->whereRaw("email = '$email'");  // DO NOT DO THIS

// Mass-assignment — use setFillable() at the call site (no model class)
db()->table('users')
    ->setFillable(['name', 'email', 'status'])
    ->insert($request->toDTO());

db()->table('users')
    ->setGuarded(['id', 'password', 'remember_token', 'role'])
    ->where('id', $id)
    ->update($request->toDTO());
```

**Detection helper (for validation/request hardening):**

```php
$issues = security()->containsSqlInjection($input);
$issues = security()->containsInjection($input);  // SQL + NoSQL combined
```

**XSS:**

```php
// Middleware (applied to all API routes by default)
->middleware('xss')                    // Scan all POST fields
->middleware('xss:content,body')       // Exclude rich-text fields

// FormRequest sanitizers
public function sanitize(): array {
    return ['name' => 'strip_tags|trim', 'bio' => 'html_encode'];
}

// Detection helper
$found = security()->containsXss($input);
$found = security()->containsMalicious($input);
```

Reference: [27-security-component.md](../../../docs/framework_knowledge/27-security-component.md) · [12-database-query-builder.md](../../../docs/framework_knowledge/12-database-query-builder.md) · [04-validation-formrequest.md](../../../docs/framework_knowledge/04-validation-formrequest.md)

### A04 Insecure Design

**FormRequest checklist for every endpoint:**
- [ ] `authorize()` checks the actor, not just that they are authenticated.
- [ ] `sanitize()` trims and strips unsafe characters from text fields.
- [ ] `rules()` includes `required`, type rules, `max:`, and `min:` on every field.
- [ ] `sensitiveFields()` marks passwords, tokens, PINs for redaction in logs.
- [ ] `casts()` and `defaults()` normalize blank/null values before persistence.

Reference: [04-validation-formrequest.md](../../../docs/framework_knowledge/04-validation-formrequest.md)

### A05 Security Misconfiguration

**Security headers** — ensure `headers` middleware is in the `web` and `api` groups (it is by default):

```php
// framework.php — verify these groups include 'headers'
'web' => ['session.stateful', 'headers', 'trusted.hosts', ...],
'api' => ['headers', 'trusted.hosts', ...],
```

**CSP** — configured via `security.php`. Review allowed `script-src`, `style-src`, `frame-ancestors`.

**Trusted hosts** — configure `trusted.hosts` middleware; never skip it on production.

**Trusted proxies** — configure `trusted.proxies` for accurate IP resolution behind load balancers.

Reference: [06-middleware-security.md](../../../docs/framework_knowledge/06-middleware-security.md) · [09-framework-config-reference.md](../../../docs/framework_knowledge/09-framework-config-reference.md)

### A06 Vulnerable & Outdated Components

```bash
composer audit            # Check for known CVEs in dependencies
php myth security:audit   # OWASP-aligned app-level checks
```

### A07 Identification & Authentication Failures

**Brute-force protection:**

```php
// Login routes MUST use aggressive-throttle
$router->post('/login', [AuthController::class, 'login'])
    ->middleware('aggressive-throttle')
    ->guestOnly();
```

**Session fixation** — `auth()->login()` calls `session_regenerate_id(destroy: true)` automatically.

**Session concurrency** — configure `auth.max_devices` to limit concurrent sessions; `POST /api/v1/auth/logout-other-devices` terminates other sessions.

**Credential lockout** — `systems_login_policy` table enforces attempt limits, lockout duration, and password rotation.

Reference: [06-middleware-security.md](../../../docs/framework_knowledge/06-middleware-security.md) · [15-auth-component-reference.md](../../../docs/framework_knowledge/15-auth-component-reference.md)

### A08 Software & Data Integrity

**Job deserialization guard:**
- `Job::fromPayload()` uses `allowed_classes` option in `unserialize()` and a class-mismatch check — never pass arbitrary user input to the queue.

**Config integrity:**
```bash
php myth config:cache     # Compile + cache config
php myth key:generate     # Rotate app key
```

### A09 Security Logging & Monitoring

**API request logging:**

```php
// Enabled by default in api group via 'api.log' middleware
// Logs requests + responses with sensitive field masking
```

**Slow query / performance logging:**
```bash
php myth perf:report              # View performance report
php myth perf:report --json       # JSON export
```

Reference: [07-cache-queue-console.md](../../../docs/framework_knowledge/07-cache-queue-console.md)

### A10 SSRF

- Outbound requests (Guzzle) — validate and allowlist target domains; never pass user-supplied URLs directly to HTTP clients.
- `security()->normalizeHostHeader()` — normalize/validate inbound Host headers before trusting them.

---

## File Upload Security Checklist

The upload pipeline enforces multiple independent layers — do not bypass any of them:

- [ ] Use `files()->upload()` or the `custom_upload_helper` — never `move_uploaded_file()` directly.
- [ ] MIME detection uses `finfo::file()` on actual bytes — `$_FILES['type']` is **never trusted**.
- [ ] Extension is derived from detected MIME type, not the original filename (blocks `file.php.jpg`).
- [ ] Stored filenames are `bin2hex(random_bytes(16))` — originals never appear in storage.
- [ ] `chmod(0644)` is applied to all stored files.
- [ ] SVG is **not** in the default allow-list — add only if SVG sanitization is in place.
- [ ] Image uploads are re-encoded through GD to strip EXIF/embedded PHP.
- [ ] Document uploads (CSV/JSON/XML) use `inspectDocument()` streaming scanner when enabled.

```php
// Correct upload pattern
files()->setUploadDir('public/upload/avatars');
files()->setMaxFileSize(5);   // MB
files()->setAllowedMimeTypes('image/jpeg,image/png,image/webp');
$result = files()->upload($_FILES['avatar']);
```

Reference: [17-file-upload-system.md](../../../docs/framework_knowledge/17-file-upload-system.md) · [27-security-component.md](../../../docs/framework_knowledge/27-security-component.md)

---

## CSRF Protection

- Browser `POST|PUT|PATCH|DELETE` routes must include the `csrf` middleware (included in the `web` group).
- Use `@csrf` directive in Blade forms or `csrf_field()` helper.
- API endpoints using Bearer tokens are exempt (CSRF is session-based).
- Exclude specific URIs in `security.php` under `csrf.except`.

---

## Rate Limiting Patterns

```php
// Standard — named profile from framework.php
->middleware('throttle:api')

// Numeric — 60 requests / 1 minute, IP+route scope
->middleware('throttle:60,1')

// Aggressive — IP blocking for auth endpoints
->middleware('aggressive-throttle')

// User-scoped — 100 requests / 5 minutes per user
->middleware('throttle:100,5,user')
```

Reference: [06-middleware-security.md](../../../docs/framework_knowledge/06-middleware-security.md)

---

## Security Component Quick Reference

```php
security()->canReadPath($path)
security()->canWritePath($path)
security()->assertWritablePath($path)
security()->normalizeRelativeProjectPath($path)   // blocks traversal + null bytes
security()->sanitizeStorageSegment($value)
security()->normalizeHostHeader($host)
security()->sanitizeUserAgent($ua)
security()->isBlockedUploadExtension($ext)
security()->isBlockedUploadMimeType($mime)
security()->containsSqlInjection($input)
security()->containsNoSqlInjection($input)
security()->containsInjection($input)
security()->containsMalicious($input)
security()->containsXss($input)
security()->inspectDocument($path, $mime, $options)
```

Reference: [27-security-component.md](../../../docs/framework_knowledge/27-security-component.md)
