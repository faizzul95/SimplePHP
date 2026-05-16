# Framework Security & Performance Comparison Report

**Generated:** May 2026 — **Last reviewed:** May 2026 v4 (deep source re-audit: XSS GET scanning, HttpClient post-connect IP validation + pinning, database streaming/adaptive batching, score reconciliation)  
**PHP Baseline:** PHP 8.3 (runtime tested) / PHP 8.4 (target)  
**Scope:** MythPHP vs Native PHP, Laravel 12, Yii2, CodeIgniter 3, CodeIgniter 4, CakePHP 5  
**Evaluation Areas:** Security (OWASP Top 10 attack vectors) + Large-Dataset Database Performance + Cache & HTTP Performance  
**Methodology:** All MythPHP claims verified by direct source inspection of `systems/Core/`, `systems/Middleware/`, `systems/Components/`, `app/http/middleware/`, and `app/support/`. Scores for competing frameworks are based on their documented behaviour and public source code. Performance numbers are theoretical estimates derived from algorithm analysis — benchmark on target hardware using `php myth db:benchmark`.

---

## Table of Contents

1. [Frameworks Under Review](#1-frameworks-under-review)
2. [Security Analysis](#2-security-analysis)
3. [Database Performance Analysis](#3-database-performance-analysis)
4. [Cache & HTTP Performance](#4-cache--http-performance)
5. [Worker / Octane Mode](#5-worker--octane-mode)
6. [Aggregate Scorecard](#6-aggregate-scorecard)
7. [MythPHP Strengths & Known Gaps](#7-mythphp-strengths--known-gaps)
8. [Shared Hosting Compatibility Matrix](#8-shared-hosting-compatibility-matrix)

---

## 1. Frameworks Under Review

| # | Framework     | Version Basis | Notes |
|---|--------------|--------------|-------|
| A | **MythPHP**  | Custom (May 2026) | PHP 8.3 runtime; PHP 8.4 syntax target; all features verified against source |
| B | **Native PHP**| PHP 8.4 | Procedural, no framework — baseline floor |
| C | **Laravel**  | 12.x | Industry-standard benchmark |
| D | **Yii2**     | 2.0.52+ | Enterprise-grade ActiveRecord framework |
| E | **CI3**      | 3.1.13 (EOL) | Legacy procedural baseline |
| F | **CI4**      | 4.6.x | CI modern PSR-7 rewrite |
| G | **CakePHP**  | 5.1.x | Convention-over-configuration ORM framework |

---

## 2. Security Analysis

### 2.1 Password Hashing

MythPHP uses `Core\Security\Hasher` as the **single point of control** for all password operations. Raw `password_hash()` / `password_verify()` calls are prohibited outside of this class (enforced by code review and verified clean in this audit).

**Verified implementation (`systems/Core/Security/Hasher.php`):**

| Parameter | Value | OWASP 2024 Minimum |
|-----------|-------|-------------------|
| Algorithm | Argon2id | Argon2id recommended |
| Memory cost | 64 MB (65 536 KiB) | 19 MB |
| Time cost | 4 iterations | 2 |
| Parallelism | 2 threads | 1 |

Additional protections:
- Transparent bcrypt → Argon2id upgrade: `verify()` accepts legacy bcrypt; `needsRehash()` flags them for rehash on next successful login
- Timing-safe dummy verify (`DUMMY_HASH` constant) — prevents account enumeration when user not found
- `hashToken()` uses `hash('sha256', ...)` for non-password tokens
- `equals()` wraps `hash_equals()` for constant-time string comparison

**Call-site audit — all clean:**

| File | Usage | Status |
|------|-------|--------|
| `systems/Core/Security/Hasher.php` | `password_hash` / `password_verify` | ✅ Only legitimate location |
| `app/http/controllers/AuthController.php` | `Hasher::make()` in `resetPassword()` | ✅ |
| `systems/Components/Auth.php` | `Hasher::verify()` in session + social login; `Hasher::make()` for social-auth OTP | ✅ |
| `systems/Components/Validation.php` | `Hasher::verify()` in `validateCurrentpassword()` | ✅ |
| `app/support/Auth/LoginPolicy.php` | `password_hash()` in non-default algorithm branch only | ✅ Intentional |
| `app/support/Auth/AccessCredentialService.php` | `Hasher::verify()`, `needsRehash()`, `dummyVerify()`, `make()` on rehash | ✅ |

| Framework     | Algorithm | OWASP 2024 | Timing-Safe | Auto-Upgrade | Rating |
|--------------|-----------|-----------|-------------|-------------|--------|
| **MythPHP**  | **Argon2id** 64 MB / t=4 | ✅ | ✅ | ✅ `needsRehash` | ⭐⭐⭐⭐⭐ **(5.0)** |
| Native PHP   | Developer choice | ❌ usually bcrypt | ❌ | ❌ | ⭐⭐ (2.0) |
| Laravel      | Bcrypt default, Argon2 opt-in | ⚠️ | ✅ | ✅ | ⭐⭐⭐⭐ (4.0) |
| Yii2         | Bcrypt via `Security::generatePasswordHash()` | ⚠️ | ✅ | ✅ | ⭐⭐⭐½ (3.5) |
| CI3          | No built-in | ❌ | ❌ | ❌ | ⭐ (1.0) |
| CI4          | No built-in | ❌ | ❌ | ❌ | ⭐ (1.0) |
| CakePHP      | Bcrypt via `DefaultPasswordHasher` | ⚠️ | ✅ | ✅ | ⭐⭐⭐½ (3.5) |

---

### 2.2 SQL Injection

**Verified MythPHP mechanisms:**
- All user-facing queries use **PDO prepared statements** — no string concatenation of user input
- `_forbidRawQuery()` blocks stacked queries, SQL comment injection (`--`, `/*`), hex-encoded payloads, and `UNION`-style injections in column/table arguments
- `sanitizeColumn()` applies a **two-layer column guard**: (1) schema guard strips any key not in the real table; (2) application-layer `$fillable` / `$guarded` — when `$fillable` is declared on a subclass only listed columns survive, identical to Eloquent's `$fillable`; `$guarded` columns are always stripped even if present in `$fillable`
- `orderByAllowed(string $column, string $direction, array $allowedColumns)` — explicit allowlist for dynamic ORDER BY; user strings never reach the query directly
- `whereIn([])` generates safe `WHERE 1=0` instead of invalid SQL
- `whereColumn()` backtick-escapes and validates both column names before `whereRaw()`
- `FormRequest::validated()` returns only declared fields — no extra keys reach the DB layer
- `Backup.php` validates table names against `/^[a-zA-Z0-9_$]+$/` regex before using in dynamic queries

**`$fillable` / `$guarded` usage (Model subclass pattern):**
```php
namespace App\Models;

use Core\Database\Model;

class User extends Model
{
    protected array $fillable = ['name', 'email', 'bio', 'avatar'];
    protected array $guarded  = ['is_admin', 'role_id', 'deleted_at'];
    protected array $hidden   = ['password'];
    protected bool  $timestamps  = true;
    protected bool  $softDeletes = true;
}

// Query API:
User::all();
User::find(1);
User::where('active', 1)->orderBy('name')->paginate(20);
User::create($request->only(['name', 'email']));
$user = User::find(1);  $user->name = 'Bob';  $user->save();
```
Models that accept direct user input must declare `$fillable`. The schema-only guard (`sanitizeColumn()`) remains active as a second layer for raw `db()->table()` calls.

| Framework     | Prepared Stmts | ORDER BY Safe | Mass-Assign Guard | Raw SQL Block | Rating |
|--------------|---------------|--------------|------------------|--------------|--------|
| **MythPHP**  | ✅ | ✅ Allowlist | ✅ `$fillable` + `$guarded` + `sanitizeColumn()` | ✅ `_forbidRawQuery()` | ⭐⭐⭐⭐½ **(4.5)** |
| Native PHP   | Manual | ❌ | ❌ | ❌ | ⭐⭐ (2.0) |
| Laravel      | ✅ Eloquent | ✅ | ✅ `$fillable` | ✅ | ⭐⭐⭐⭐⭐ (5.0) |
| Yii2         | ✅ ActiveRecord | ✅ | ✅ `safeAttributes()` | ✅ | ⭐⭐⭐⭐⭐ (5.0) |
| CI3          | ✅ QB | ⚠️ | ❌ | ❌ | ⭐⭐⭐ (3.0) |
| CI4          | ✅ QB + PDO | ✅ | ⚠️ | ⚠️ `RawSql` | ⭐⭐⭐⭐ (4.0) |
| CakePHP      | ✅ ORM | ✅ | ✅ `$accessible` | ✅ | ⭐⭐⭐⭐½ (4.5) |

---

### 2.3 XSS Protection

**Verified MythPHP mechanisms:**
- `XssProtection` middleware scans `$_POST`, `$_GET`, JSON body, and uploaded **file names** on all request methods except `HEAD` / `OPTIONS`
- Blade `{{ $var }}` auto-escapes via `htmlspecialchars(ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`
- `@nonce` directive outputs `nonce="{{ $csp_nonce }}"` HTML-escaped
- `@sri('url', 'sha384-...')` compile-time Blade directive generates `integrity="..." crossorigin="anonymous"` — blocks CDN code tampering
- CSP nonce via `CspNonce::get()` (16 bytes `random_bytes`, base64) — injected as `$csp_nonce` into all Blade views; CSP header `script-src 'nonce-{value}'` blocks unsigned inline scripts
- `XssProtectionTrait::fileNameHasXss()` blocks HTML tags, event-handler patterns (`on\w+=`), path traversal, and dangerous extensions (`.html`, `.svg`, `.php`, `.jsp`) in uploaded file names

| Framework     | Template Escape | POST XSS Scan | GET XSS Scan | CSP Nonce | SRI | Rating |
|--------------|----------------|--------------|-------------|----------|-----|--------|
| **MythPHP**  | ✅ Blade `{{ }}` | ✅ | ✅ **All methods scanned** | ✅ `CspNonce` | ✅ `@sri` | ⭐⭐⭐⭐½ **(4.5)** |
| Native PHP   | ❌ Manual | ❌ | ❌ | ❌ | ❌ | ⭐½ (1.5) |
| Laravel      | ✅ Blade auto-escape | ❌ | ❌ | ✅ Jetstream | ⚠️ 3rd-party | ⭐⭐⭐⭐⭐ (5.0) |
| Yii2         | ✅ `Html::encode()` + HtmlPurifier | ✅ | ✅ | ❌ | ❌ | ⭐⭐⭐⭐½ (4.5) |
| CI3          | ❌ `xss_clean()` optional | ⚠️ | ⚠️ | ❌ | ❌ | ⭐⭐½ (2.5) |
| CI4          | ✅ `esc()`; no auto-escape | ❌ | ❌ | ❌ | ❌ | ⭐⭐⭐½ (3.5) |
| CakePHP      | ✅ `h()` auto-escapes | ❌ | ❌ | ❌ | ❌ | ⭐⭐⭐⭐ (4.0) |

`XssProtection::handle()` now scans all HTTP methods except `HEAD` / `OPTIONS`. `request()->detectXss()` merges `$_GET` + `$_POST` + input stream, so the earlier reflected-XSS-via-GET gap is closed in the current code.

---

### 2.4 CSRF Protection

**Verified mechanisms (`systems/Components/CSRF.php`, `app/http/middleware/VerifyCsrfToken.php`):**
- Token: `random_bytes(32)` stored in server-side session only — never cookie-only
- `SameSite=Lax`; `HttpOnly=true`; `Secure=true` (configurable per environment)
- Origin/Referer double-submit check (`csrf_origin_check=true`) — rejects cross-origin requests not matching trusted-host list
- `@csrf` Blade directive — HTML-escaped hidden input
- `X-CSRF-TOKEN` header support for AJAX
- `csrf_exclude_uris: ['api/*']` — API routes use Bearer tokens; web routes protected by default
- `hash_equals()` for constant-time token comparison

| Framework     | Server-Side Token | SameSite | Origin Check | AJAX | Rating |
|--------------|------------------|---------|-------------|------|--------|
| **MythPHP**  | ✅ Session | ✅ Lax | ✅ | ✅ | ⭐⭐⭐⭐½ **(4.5)** |
| Native PHP   | Manual | ❌ | ❌ | ❌ | ⭐ (1.0) |
| Laravel      | ✅ Session + encrypted cookie | ✅ | ✅ | ✅ | ⭐⭐⭐⭐⭐ (5.0) |
| Yii2         | ✅ Session | ✅ | ⚠️ | ✅ | ⭐⭐⭐⭐½ (4.5) |
| CI3          | Cookie-based | ❌ | ❌ | ⚠️ | ⭐⭐⭐ (3.0) |
| CI4          | Session | ✅ | ❌ | ✅ | ⭐⭐⭐⭐ (4.0) |
| CakePHP      | Session | ✅ | ⚠️ | ✅ | ⭐⭐⭐⭐½ (4.5) |

---

### 2.5 Security Headers

**Verified implementation (`systems/Middleware/Traits/SecurityHeadersTrait.php`):**

| Header | Status | Notes |
|--------|--------|-------|
| `Strict-Transport-Security` | ✅ | `max-age`, `includeSubDomains`, `preload` configurable |
| `Content-Security-Policy` | ✅ | Nonce-based; all directives configurable |
| `X-Frame-Options` | ✅ | `DENY` / `SAMEORIGIN` |
| `X-Content-Type-Options` | ✅ | Always `nosniff` |
| `Referrer-Policy` | ✅ | Configurable |
| `Permissions-Policy` | ✅ | Accepts both `string` and `array` config formats |
| `Cross-Origin-Opener-Policy` | ✅ | `same-origin` default |
| `Cross-Origin-Resource-Policy` | ✅ | Configurable |
| `X-DNS-Prefetch-Control` | ✅ | `off` default |

| Framework     | HSTS | CSP Nonce | Permissions-Policy | COOP / CORP | Rating |
|--------------|------|----------|--------------------|------------|--------|
| **MythPHP**  | ✅ | ✅ Nonce | ✅ Array + String | ✅ | ⭐⭐⭐⭐½ **(4.5)** |
| Native PHP   | ❌ | ❌ | ❌ | ❌ | ⭐ (1.0) |
| Laravel      | ❌ needs package | ⚠️ | ❌ | ❌ | ⭐⭐⭐ (3.0) |
| Yii2         | ❌ Manual | ❌ | ❌ | ❌ | ⭐⭐⭐ (3.0) |
| CI3          | ❌ | ❌ | ❌ | ❌ | ⭐½ (1.5) |
| CI4          | ❌ Manual | ❌ | ❌ | ❌ | ⭐⭐ (2.0) |
| CakePHP      | ⚠️ Limited | ❌ | ❌ | ❌ | ⭐⭐⭐½ (3.5) |

---

### 2.6 File Upload Security

**Verified implementation (`systems/Core/Security/FileUploadGuard.php`):**
- MIME detected via `finfo` (magic bytes) — not the user-supplied `Content-Type`
- Extension derived from MIME allowlist map, never from original filename
- Double-extension bypass blocked (e.g., `shell.php.jpg` rejected)
- Null bytes stripped from filename
- Stored filename: `bin2hex(random_bytes(16)) . '.' . $safeExtension` — non-guessable
- Storage **outside web root** (`storage/uploads/`)
- `.htaccess deny-all` written on first use
- `serve()`: `realpath()` containment guard; `Content-Disposition: attachment` forced for non-image/non-PDF; `Content-Disposition` filename CRLF-sanitized: `str_replace(["\r","\n","\0",'"'], '', basename($realPath))`
- `delete()`: `..` and null byte rejection before `realpath()` check
- `ValidateUploadGuard` middleware: entity-type allowlist, folder-group allowlist, AJAX-only flag, base64 MIME detection for cropper uploads
- Pixel-bomb guard + GD re-encode (EXIF strip) via `Files` component

| Framework     | finfo MIME | Random Name | Out-of-Webroot | Safe Content-Disposition | Rating |
|--------------|-----------|------------|----------------|-------------------------|--------|
| **MythPHP**  | ✅ | ✅ `random_bytes(16)` | ✅ | ✅ CRLF sanitized | ⭐⭐⭐⭐½ **(4.5)** |
| Native PHP   | ❌ | ❌ | ❌ | ❌ | ⭐ (1.0) |
| Laravel      | ✅ | ✅ | ✅ | ⚠️ | ⭐⭐⭐⭐ (4.0) |
| Yii2         | ✅ | ⚠️ | ❌ | ❌ | ⭐⭐⭐½ (3.5) |
| CI3          | ⚠️ | ❌ | ❌ | ❌ | ⭐⭐ (2.0) |
| CI4          | ✅ | ⚠️ | ❌ | ❌ | ⭐⭐⭐ (3.0) |
| CakePHP      | ✅ | ⚠️ | ❌ | ❌ | ⭐⭐⭐ (3.0) |

---

### 2.7 Rate Limiting & Brute-Force Protection

MythPHP provides two independent rate-limiting tiers:

**Tier 1 — `RateLimit` middleware (`app/http/middleware/RateLimit.php`):**
- 6 scope modes: `ip`, `route`, `auth`, `user`, `ip-route`, `auth-route`
- **APCu fast path:** `apcu_add()` (window init, atomic) + `apcu_inc()` (single OS-atomic increment) — zero race window
- **File fallback:** `flock(LOCK_SH)` on read + temp-file + `rename()` on write — no partial-read race
- Named profiles in `config/framework.php`; Laravel-style `throttle:60,1` syntax

**Tier 2 — `Core\Security\RateLimiter` (login brute-force):**
- User-ID-preferred key (falls back to trusted-proxy-aware IP) — defeats IP rotation
- `cache()->add()` = atomic SET NX
- `AuditLogger::bruteForce()` fires on threshold breach — writes to `security_audit_log` DB table + flat NDJSON file

**`ThrottleRequests` middleware:** aggressive IP blocking via `RateLimitingThrottleTrait`.

| Framework     | Atomic Increment | User-ID Keyed | Configurable Scopes | Audit Trail | Rating |
|--------------|-----------------|--------------|---------------------|------------|--------|
| **MythPHP**  | ✅ APCu / Redis `cache()` / flock | ✅ | ✅ 6 modes | ✅ `AuditLogger` | ⭐⭐⭐⭐½ **(4.5)** |
| Native PHP   | ❌ | ❌ | ❌ | ❌ | ⭐ (1.0) |
| Laravel      | ✅ Redis INCR | ✅ | ✅ Named limiters | ⚠️ | ⭐⭐⭐⭐⭐ (5.0) |
| Yii2         | ✅ | ✅ | ⚠️ | ❌ | ⭐⭐⭐⭐ (4.0) |
| CI3          | ❌ | ❌ | ❌ | ❌ | ⭐ (1.0) |
| CI4          | ⚠️ File not atomic | ❌ | ⚠️ | ❌ | ⭐⭐⭐ (3.0) |
| CakePHP      | ❌ 3rd-party | ❌ | ❌ | ❌ | ⭐⭐½ (2.5) |

---

### 2.8 Authentication Security

**Verified mechanisms:**
- `session_regenerate_id(true)` on every login (`systems/Components/Auth.php:531`) — prevents session fixation
- Session fingerprint: optional User-Agent hash (`strict` / `normalized` / `family` modes) + optional IP binding
- Session concurrency: configurable max-device limit; oldest-session invalidation
- JWT: algorithm locked to `HS256` from server-side config (never from the `alg` header) — blocks algorithm-confusion attacks; `hash_equals()` for signature; `nbf`/`exp` validated with configurable leeway
- API keys: plain-text never stored; SHA-256 hashed on creation; `hash_equals()` on comparison
- `Hasher::dummyVerify()` normalises response time — prevents timing-based enumeration
- `needsRehash()` upgrades legacy bcrypt to Argon2id transparently on next login

| Framework     | Session Fixation Guard | Fingerprint | JWT Algo-Confusion Block | Timing-Safe | Absolute Timeout | Rating |
|--------------|----------------------|------------|------------------------|-------------|-----------------|--------|
| **MythPHP**  | ✅ `session_regenerate_id(true)` | ✅ Configurable | ✅ Server-locked | ✅ `hash_equals` + dummy | ✅ `framework.session.absolute_lifetime` | ⭐⭐⭐⭐½ **(4.5)** |
| Native PHP   | ❌ | ❌ | ❌ | ❌ | ❌ | ⭐ (1.0) |
| Laravel      | ✅ Auto | ✅ Sanctum | ✅ | ✅ | ✅ | ⭐⭐⭐⭐⭐ (5.0) |
| Yii2         | ✅ | ⚠️ | ⚠️ Library | ✅ | ⚠️ | ⭐⭐⭐⭐ (4.0) |
| CI3          | ❌ | ❌ | ❌ | ❌ | ❌ | ⭐½ (1.5) |
| CI4          | ✅ Shield | ❌ | ❌ | ⚠️ | ❌ | ⭐⭐⭐ (3.0) |
| CakePHP      | ✅ | ❌ | ❌ | ⚠️ | ⚠️ | ⭐⭐⭐½ (3.5) |

---

### 2.9 IDOR Detection

**Verified implementation (`systems/Middleware/DetectIdor.php`):**
- Compares named route parameter (e.g., `user_id`) against authenticated session `user_id`
- Blocks with 403 on mismatch
- Super-admin bypass: `can('admin.access.any')` permission
- Fires `AuditLogger::idor()` — writes to `security_audit_log` with `is_idor_suspect=1` + flat file

| Framework     | Built-in Guard | Audit Trail | Admin Bypass | Rating |
|--------------|---------------|------------|-------------|--------|
| **MythPHP**  | ✅ `DetectIdor` | ✅ `AuditLogger` | ✅ | ⭐⭐⭐⭐½ **(4.5)** |
| Native PHP   | ❌ | ❌ | N/A | ⭐ (1.0) |
| Laravel      | ✅ Gates/Policies | ⚠️ | ✅ | ⭐⭐⭐⭐⭐ (5.0) |
| Yii2         | ✅ RBAC | ⚠️ | ✅ | ⭐⭐⭐⭐ (4.0) |
| CI3          | ❌ | ❌ | ❌ | ⭐ (1.0) |
| CI4          | ⚠️ Shield limited | ❌ | ⚠️ | ⭐⭐½ (2.5) |
| CakePHP      | ✅ Authorization | ⚠️ | ✅ | ⭐⭐⭐⭐ (4.0) |

---

### 2.10 Null-Byte & Path-Traversal Attacks

- `Request::fromGlobals()`: `stripNullBytes()` on `$_GET` + `$_POST` at Request creation — all downstream code is clean
- `FileUploadGuard`: null-byte strip + double-extension check on filename; `realpath()` containment in `serve()` and `delete()`
- `LocalFilesystemAdapter::normalizeRelativePath()`: throws `InvalidArgumentException` on `..` — traversal impossible via storage API
- `Response::sanitizeRedirectTarget()`: strips `\r\n\0`; blocks `javascript:`, `data:`, `//` prefixes

| Framework     | Null-Byte Strip | Upload Path Guard | Redirect Guard | Rating |
|--------------|----------------|-------------------|----------------|--------|
| **MythPHP**  | ✅ At Request creation | ✅ `realpath()` | ✅ `sanitizeRedirectTarget()` | ⭐⭐⭐⭐½ **(4.5)** |
| Native PHP   | ❌ | ❌ | ❌ | ⭐ (1.0) |
| Laravel      | ✅ Symfony Request | ✅ | ✅ | ⭐⭐⭐⭐ (4.0) |
| Yii2         | ✅ | ✅ | ✅ | ⭐⭐⭐½ (3.5) |
| CI3          | ⚠️ `removeInvisibleCharacters()` | ⚠️ | ❌ | ⭐⭐⭐ (3.0) |
| CI4          | ⚠️ | ⚠️ | ⚠️ | ⭐⭐⭐ (3.0) |
| CakePHP      | ✅ | ✅ | ✅ | ⭐⭐⭐½ (3.5) |

---

### 2.11 Routing & Request Safety

**Verified mechanisms (`app/http/middleware/ValidateRequestSafety.php`, `ValidateTrustedHosts.php`, `ValidateTrustedProxies.php`):**
- Allowed HTTP methods: `GET POST PUT PATCH DELETE OPTIONS HEAD` — others → 405
- URI length ≤ 2 000, body ≤ 1 MB, headers ≤ 64, input vars ≤ 200, JSON fields ≤ 200, multipart parts ≤ 50 — all configurable
- Host allow-list from `security.trusted.hosts`
- `X-Forwarded-For` honoured only when `REMOTE_ADDR` is in trusted proxy list

| Framework     | Method Filter | Host Allow-List | Proxy Trust | Request Size Limit | Rating |
|--------------|--------------|----------------|------------|-------------------|--------|
| **MythPHP**  | ✅ 7 methods | ✅ | ✅ Header-validated | ✅ Configurable | ⭐⭐⭐⭐½ **(4.5)** |
| Native PHP   | ❌ | ❌ | ❌ | ❌ | ⭐ (1.0) |
| Laravel      | ✅ | ✅ | ✅ `TrustProxies` | ⚠️ PHP.ini | ⭐⭐⭐⭐⭐ (5.0) |
| Yii2         | ✅ | ✅ | ✅ | ⚠️ | ⭐⭐⭐⭐ (4.0) |
| CI3          | ❌ | ❌ | ❌ | ❌ | ⭐½ (1.5) |
| CI4          | ✅ | ⚠️ | ⚠️ | ⚠️ | ⭐⭐⭐½ (3.5) |
| CakePHP      | ✅ | ✅ | ✅ | ⚠️ | ⭐⭐⭐⭐ (4.0) |

---

### 2.12 SSRF Protection

**Verified implementation (`systems/Core/Support/HttpClient.php`):**
- Blocks outbound requests to private/loopback ranges **before connection**
- IPv4: `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`, `127.0.0.0/8`
- IPv6: `::1`, `fe80::/10`, `fc00::/7`
- IPv6 bracket notation `[::1]` detected and rejected — prevents URL parsing bypass
- Strict host allowlist via `allowHosts()` / `security.http_client.allowed_hosts`
- Optional post-connect primary-IP validation via `assertConnectedIpIsSafe()` catches DNS rebinding when cURL reports a private/reserved connected IP
- Optional SPKI certificate pinning via `security.http_client.pins` and `pin_on_error`
- `filter_var()` + `inet_pton()` bitwise CIDR — no string-comparison bypass

| Framework     | Pre-Connect Block | IPv6 Covered | Strict Allowlist | Built-in | Rating |
|--------------|-----------------|-------------|-----------------|---------|--------|
| **MythPHP**  | ✅ + post-connect IP check / optional pinning | ✅ Brackets + CIDR | ✅ `allowHosts()` deny-all mode | ✅ | ⭐⭐⭐⭐½ **(4.5)** |
| Native PHP   | ❌ | ❌ | ❌ | ⭐ (1.0) |
| Laravel      | ❌ No built-in | ❌ | ❌ | ⭐⭐ (2.0) |
| Yii2         | ❌ | ❌ | ❌ | ⭐⭐ (2.0) |
| CI3          | ❌ | ❌ | ❌ | ⭐ (1.0) |
| CI4          | ❌ | ❌ | ❌ | ⭐ (1.0) |
| CakePHP      | ❌ | ❌ | ❌ | ⭐ (1.0) |

---

### 2.13 Signed URLs & Token Security

**Verified (`systems/Core/Security/SignedUrl.php`, `systems/Components/Auth.php`):**
- HMAC-SHA256 over `url + expires` using `APP_KEY`; `hash_equals()` comparison
- Expiry enforced server-side; `explode()` not `strtok()` (no global state)
- API tokens: `random_bytes(32)` plain-text; SHA-256 hash stored; `hash_equals()` on comparison; rotation invalidates old token

| Framework     | HMAC Signed URLs | Token: Plain Never Stored | Constant-Time | Rating |
|--------------|-----------------|--------------------------|--------------|--------|
| **MythPHP**  | ✅ HMAC-SHA256 | ✅ SHA-256 hash only | ✅ `hash_equals()` | ⭐⭐⭐⭐½ **(4.5)** |
| Native PHP   | ❌ | ❌ | ❌ | ⭐ (1.0) |
| Laravel      | ✅ `URL::signedRoute()` | ✅ Sanctum | ✅ | ⭐⭐⭐⭐⭐ (5.0) |
| Yii2         | ❌ | ✅ | ✅ | ⭐⭐⭐ (3.0) |
| CI3          | ❌ | ❌ | ❌ | ⭐ (1.0) |
| CI4          | ❌ | ⚠️ Shield optional | ⚠️ | ⭐⭐ (2.0) |
| CakePHP      | ❌ | ✅ | ✅ | ⭐⭐⭐ (3.0) |

---

### 2.14 Column-Level Encryption

**Verified (`systems/Core/Security/Encryptor.php`):**
- **AES-256-GCM** via `libsodium` — authenticated encryption; no padding oracle
- Key derived from `APP_KEY` via `sodium_crypto_pwhash()` (Argon2id KDF)
- Nonce: `random_bytes(SODIUM_CRYPTO_AEAD_AES256GCM_NPUBBYTES)` per call — never reused
- `blindIndex()`: HMAC-SHA256 of plaintext — enables `WHERE` equality queries without exposing plaintext
- `sodium_memzero()` on key material after use

| Framework     | Column Encryption | Auth. Encryption | Blind Index | Rating |
|--------------|------------------|-----------------|------------|--------|
| **MythPHP**  | ✅ AES-256-GCM libsodium | ✅ GCM auth tag | ✅ HMAC | ⭐⭐⭐⭐⭐ **(5.0)** |
| Native PHP   | ❌ | ❌ | ❌ | ⭐ (1.0) |
| Laravel      | ✅ via `spatie/db-encrypted` package | ✅ | ❌ | ⭐⭐⭐ (3.0) |
| Yii2         | ❌ | ❌ | ❌ | ⭐ (1.0) |
| CI3          | ❌ | ❌ | ❌ | ⭐ (1.0) |
| CI4          | ❌ | ❌ | ❌ | ⭐ (1.0) |
| CakePHP      | ❌ | ❌ | ❌ | ⭐ (1.0) |

---

### 2.15 Breach / Pwned-Password Detection

**Verified (`systems/Core/Security/PwnedPasswordChecker.php`):**
- **Have I Been Pwned k-Anonymity API**: sends only SHA-1 prefix (5 hex chars) — full hash never transmitted
- Match resolved client-side from suffix list
- `AuditLogger::pwnedPassword()` logs detection with user ID
- Outbound request via `HttpClient` — SSRF protection active during HIBP call

| Framework     | Pwned Check | k-Anonymity Privacy | Audit Trail | Rating |
|--------------|------------|---------------------|------------|--------|
| **MythPHP**  | ✅ HIBP | ✅ | ✅ `AuditLogger` | ⭐⭐⭐⭐⭐ **(5.0)** |
| Laravel      | ❌ No built-in | N/A | ❌ | ⭐ (1.0) |
| Yii2         | ❌ | N/A | ❌ | ⭐ (1.0) |
| CI3          | ❌ | N/A | ❌ | ⭐ (1.0) |
| CI4          | ❌ | N/A | ❌ | ⭐ (1.0) |
| CakePHP      | ❌ | N/A | ❌ | ⭐ (1.0) |
| Native PHP   | ❌ | N/A | ❌ | ⭐ (1.0) |

---

### 2.16 Security Scorecard

| Category                    | **MythPHP** | Laravel | Yii2 | CI4 | CakePHP | CI3 | Native PHP |
|----------------------------|------------|---------|------|-----|---------|-----|-----------|
| Password Hashing            | **5.0** | 4.0 | 3.5 | 1.0 | 3.5 | 1.0 | 2.0 |
| SQL Injection               | **4.5** | 5.0 | 5.0 | 4.0 | 4.5 | 3.0 | 2.0 |
| XSS Protection              | **4.5** | 5.0 | 4.5 | 3.5 | 4.0 | 2.5 | 1.5 |
| CSRF Protection             | **4.5** | 5.0 | 4.5 | 4.0 | 4.5 | 3.0 | 1.0 |
| Security Headers            | **4.5** | 3.0 | 3.0 | 2.0 | 3.5 | 1.5 | 1.0 |
| File Upload Security        | **4.5** | 4.0 | 3.5 | 3.0 | 3.0 | 2.0 | 1.0 |
| Rate Limiting               | **4.5** | 5.0 | 4.0 | 3.0 | 2.5 | 1.0 | 1.0 |
| Auth Security               | **4.5** | 5.0 | 4.0 | 3.0 | 3.5 | 1.5 | 1.0 |
| IDOR Detection              | **4.5** | 5.0 | 4.0 | 2.5 | 4.0 | 1.0 | 1.0 |
| Null-Byte / Path Traversal  | **4.5** | 4.0 | 3.5 | 3.0 | 3.5 | 3.0 | 1.0 |
| Routing / Request Safety    | **4.5** | 5.0 | 4.0 | 3.5 | 4.0 | 1.5 | 1.0 |
| SSRF Protection             | **4.5** | 2.0 | 2.0 | 1.0 | 1.0 | 1.0 | 1.0 |
| Signed URLs / Token Security| **4.5** | 5.0 | 3.0 | 2.0 | 3.0 | 1.0 | 1.0 |
| Column Encryption           | **5.0** | 3.0 | 1.0 | 1.0 | 1.0 | 1.0 | 1.0 |
| Pwned Password Detection    | **5.0** | 1.0 | 1.0 | 1.0 | 1.0 | 1.0 | 1.0 |
| **Security Average**        | **4.60** | **4.07** | **3.37** | **2.50** | **3.10** | **1.67** | **1.17** |

---

## 3. Database Performance Analysis

All figures are theoretical estimates for 1 M–2 M row datasets on a standard VPS (4 vCPU, 8 GB RAM, SSD). Measure on target hardware with `php myth db:benchmark`.

### 3.1 Pagination Strategies

| Strategy | Method | Mechanism | Performance at depth (2 M rows) |
|----------|--------|-----------|-------------------------------|
| Offset | `paginate()` | `LIMIT x OFFSET y` | Page 1: ~0.05s / 8 MB; Page 50k: ~6–8s / 25 MB |
| Keyset / Cursor | `cursorPaginate()` | `WHERE id > :last ORDER BY id LIMIT x` | All pages: ~0.03–0.05s / 5 MB |
| Streaming / lazy iteration | `chunk()`, `cursor()`, `lazy()`, `chunkById()`, `lazyById()`, model `each()` / `eachById()` | Chunked generator flow; adaptive chunk shrink on wide rows | Memory stays bounded on large exports / maintenance runs |

| Framework    | Offset | Cursor Paginate | Streaming | Rating |
|-------------|--------|----------------|----------|--------|
| **MythPHP** | ✅ | ✅ `cursorPaginate()` | ✅ `chunk()` / `cursor()` / `lazy()` / `chunkById()` / `lazyById()` | ⭐⭐⭐⭐½ (4.5) |
| Laravel | ✅ | ✅ `cursorPaginate()` | ✅ `chunk()` | ⭐⭐⭐⭐⭐ (5.0) |
| Yii2 | ✅ | ❌ | ✅ `batch()` | ⭐⭐⭐ (3.0) |
| CI3 | ✅ | ❌ | ❌ | ⭐⭐ (2.0) |
| CI4 | ✅ | ❌ | ❌ | ⭐⭐ (2.0) |
| CakePHP | ✅ | ❌ | ✅ | ⭐⭐⭐ (3.0) |

---

### 3.2 N+1 Detection

**Verified (`systems/Core/Database/PerformanceMonitor.php`):**
- Auto-detects N+1 patterns (same SQL template, different binds) when `APP_DEBUG=true`
- `DB_SLOW_QUERY_THRESHOLD_MS=750` — slow queries logged automatically
- `resetQueryLog()` for per-request reset in worker mode — prevents log growth across requests
- Adaptive chunk decisions are recorded in the performance report for wide-row streaming
- Eager loading: `->with()`, `->withOne()`, plus aggregate eager-load helpers eliminate common N+1 patterns at ORM level
- `toDebugSnapshot()` exposes SQL, profiler data, and the current performance report for debug-only query triage

| Framework    | N+1 Detection | Eager Loading | Worker-Safe Reset | Rating |
|-------------|--------------|--------------|------------------|--------|
| **MythPHP** | ✅ `PerformanceMonitor` | ✅ `with()` / `withOne()` / aggregate eager loads | ✅ | ⭐⭐⭐⭐½ (4.5) |
| Laravel | ✅ Telescope | ✅ `with()` | ✅ | ⭐⭐⭐⭐⭐ (5.0) |
| Yii2 | ⚠️ Debug toolbar | ✅ `with()` | ⚠️ | ⭐⭐⭐⭐ (4.0) |
| CI3 | ❌ | ❌ | ❌ | ⭐ (1.0) |
| CI4 | ❌ | ❌ | ❌ | ⭐½ (1.5) |
| CakePHP | ⚠️ DebugKit | ✅ `contain()` | ⚠️ | ⭐⭐⭐⭐ (4.0) |

---

### 3.3 Query Caching

**Verified — 3-tier query cache:**

| Tier | Storage | Latency | Scope |
|------|---------|---------|-------|
| 1st | PHP array | ~0 μs | Single request |
| 2nd | APCu shared memory | ~0.1 ms | Cross-request, single server |
| 3rd | File (temp+rename atomic) | ~0.5–2 ms | Cross-request, any host |

Cache key includes SQL template + sorted bind parameters. Opt-in per query: `->cache(ttl: 120)`.
Write-through invalidation: `QueryCache::invalidateTable('users')` bumps a per-table APCu version counter — stale entries become unreachable in O(1) across all workers without enumerating keys.

| Framework    | Multi-Tier Cache | Atomic Writes | Invalidation | Rating |
|-------------|-----------------|--------------|-------------|--------|
| **MythPHP** | ✅ 3-tier | ✅ | ✅ TTL + write-through | ⭐⭐⭐⭐½ (4.5) |
| Laravel | ⚠️ Single tier `remember()` | ✅ | ✅ | ⭐⭐⭐⭐ (4.0) |
| Yii2 | ⚠️ Single tier | ✅ | ✅ | ⭐⭐⭐½ (3.5) |
| CI3 | ⚠️ File only | ❌ | ⚠️ | ⭐½ (1.5) |
| CI4 | ⚠️ Limited | ⚠️ | ⚠️ | ⭐⭐ (2.0) |
| CakePHP | ⚠️ | ✅ | ✅ | ⭐⭐⭐ (3.0) |

---

### 3.4 Connection Management & SSL

**Verified MythPHP:**
- `ConnectionPool` persistent PDO connections per environment key
- `DB_STATEMENT_TIMEOUT_MS=15000` + `DB_LOCK_WAIT_TIMEOUT_SECONDS=15` set on connection
- Retry logic: 3 attempts, 50 ms delay (configurable)
- SSL: `DB_SSL_ENABLED=true` activates `PDO::MYSQL_ATTR_SSL_CA/CERT/KEY`; `DB_SSL_VERIFY_PEER=true` default

| Framework    | Connection Pool | DB Timeouts | SSL/TLS mTLS | Retry | Rating |
|-------------|----------------|------------|-------------|-------|--------|
| **MythPHP** | ✅ `ConnectionPool` | ✅ | ✅ | ✅ | ⭐⭐⭐⭐ (4.2) |
| Laravel | ✅ | ⚠️ Server-side | ✅ | ✅ | ⭐⭐⭐⭐ (4.0) |
| Yii2 | ✅ | ⚠️ | ✅ | ⚠️ | ⭐⭐⭐⭐ (4.0) |
| CI3 | ❌ | ❌ | ⚠️ | ❌ | ⭐⭐ (2.0) |
| CI4 | ⚠️ | ⚠️ | ✅ | ❌ | ⭐⭐⭐ (3.0) |
| CakePHP | ✅ | ⚠️ | ✅ | ⚠️ | ⭐⭐⭐½ (3.5) |

---

### 3.5 Read/Write Splitting

**Verified (`app/config/database.php`, `app/support/DatabaseRuntime.php`, `systems/Core/Database/ConnectionPool.php`):**
- Read/write routing is configured inside `default.read[]`, `default.write`, and `sticky` rather than being switched by the legacy `slave` block
- `DatabaseRuntime` normalizes replica aliases such as `default::read:1` and `default::write`, then wires them into framework-level read/write routing
- `SELECT` routes to replicas; `INSERT` / `UPDATE` / `DELETE` route to primary; sticky reads keep read-after-write consistency when enabled
- Falls back to primary when no read replicas are configured; `slave` remains available as a separate named connection for explicitly different usage
- `ConnectionPool::getConnectionWithFallback()` still provides replica health fallback when a configured replica becomes unavailable

| Framework    | Read Replica | Auto-Route SELECT | Fallback | Rating |
|-------------|-------------|------------------|---------|--------|
| **MythPHP** | ✅ `default.read[]` | ✅ | ✅ Sticky + health fallback | ⭐⭐⭐⭐½ (4.5) |
| Laravel | ✅ `read`/`write` config | ✅ | ✅ | ⭐⭐⭐⭐⭐ (5.0) |
| Yii2 | ✅ `slaves` | ✅ | ✅ | ⭐⭐⭐⭐ (4.0) |
| CI3 | ❌ | ❌ | N/A | ⭐ (1.0) |
| CI4 | ❌ | ❌ | N/A | ⭐ (1.0) |
| CakePHP | ✅ | ✅ | ✅ | ⭐⭐⭐⭐ (4.0) |

---

### 3.6 Database Performance Scorecard

| Category                   | **MythPHP** | Laravel | Yii2 | CI4 | CakePHP | CI3 | Native PHP |
|---------------------------|------------|---------|------|-----|---------|-----|-----------|
| Pagination (offset, 1M+)   | **4.5** | **5.0** | 3.5 | 2.5 | 3.5 | 2.0 | 1.5 |
| Cursor / Keyset Paginate   | **4.5** | 5.0 | 2.0 | 1.0 | 2.5 | 1.0 | 1.0 |
| N+1 Prevention             | **4.5** | 5.0 | 4.5 | 2.0 | 4.5 | 1.5 | 1.0 |
| Query Cache (multi-tier)   | **4.5** | 4.0 | 3.5 | 2.0 | 3.0 | 1.5 | 1.0 |
| Connection / SSL           | **4.25** | 4.0 | 4.0 | 3.0 | 3.5 | 2.0 | 1.0 |
| Read/Write Splitting       | **4.5** | 5.0 | 4.0 | 1.0 | 4.0 | 1.0 | 1.0 |
| Streaming Export / Chunking| **4.5** | 5.0 | 4.0 | 2.5 | 3.0 | 1.5 | 1.5 |
| Memory Efficiency          | **4.5** | 5.0 | 4.0 | 2.5 | 3.5 | 1.5 | 1.5 |
| **Database Average**       | **4.47** | **4.75** | **3.69** | **2.06** | **3.44** | **1.50** | **1.19** |

---

## 4. Cache & HTTP Performance

### 4.1 Cache Driver Tiers

**Verified (`systems/Core/Cache/`):**

| Driver | Class | Latency | Atomic? | Hosting |
|--------|-------|---------|---------|---------|
| `array` | `ArrayStore` | ~0 μs | N/A | Always |
| `apcu` | `ApcuStore` | ~0.1 ms | ✅ `apcu_inc()` | Degrades to `file` if APCu absent |
| `file` | `FileStore` | ~0.5–2 ms | ✅ temp+rename | Always |
| `redis` | `RedisDriver` | ~0.2 ms | ✅ `SET NX` / `INCR` | Needs Redis service |

| Framework    | Array | APCu | File (Atomic) | Redis | Rating |
|-------------|-------|------|--------------|-------|--------|
| **MythPHP** | ✅ | ✅ | ✅ | ✅ | ⭐⭐⭐⭐ (4.2) |
| Laravel | ✅ | ⚠️ via Predis | ✅ | ✅ | ⭐⭐⭐⭐⭐ (5.0) |
| Yii2 | ✅ | ✅ | ✅ | ✅ | ⭐⭐⭐⭐½ (4.5) |
| CI3 | ❌ | ❌ | ⚠️ Not atomic | ❌ | ⭐⭐ (2.0) |
| CI4 | ⚠️ | ❌ | ⚠️ | ✅ | ⭐⭐⭐ (3.0) |
| CakePHP | ✅ | ✅ | ✅ | ✅ | ⭐⭐⭐⭐ (4.0) |

---

### 4.2 Cache Atomicity

| Scenario | APCu path | File path |
|----------|-----------|-----------|
| 10 concurrent requests, limit = 5 | Exactly 5 allowed | ≤ 5 allowed (flock guards read-modify-write) |
| Burst test (100 req/s) | ~0% bypass | ~0% bypass |
| Shared host (no APCu) | Auto-degrades to file | ✅ |

`FileStore::put()` uses **temp-file + `rename()`** (POSIX atomic). Concurrent readers cannot observe a partial write.

---

### 4.3 HTTP Conditional Caching

**Verified (`systems/Core/Http/Response.php`):**

| Method | Behaviour |
|--------|-----------|
| `Response::etag($tag)` | Sets `ETag: "safe"` header; strips `"`, CR, LF |
| `Response::withCacheHeaders($content, $lastModified)` | Computes `md5($content)` ETag; sets `ETag` + `Last-Modified`; sends `304` and returns `true` if browser cache valid |
| `Response::cache($seconds)` | `Cache-Control: public, max-age=N, s-maxage=N` |
| `Response::noCache()` | `Cache-Control: no-store, no-cache, must-revalidate` |

```php
$html = view('reports.annual', $data);
if (Response::withCacheHeaders($html, new \DateTimeImmutable($reportDate))) {
    return; // 304 sent — zero bandwidth, zero render
}
echo $html;
```

| Framework    | ETag | 304 Conditional GET | Last-Modified | Vary Header | stale-while-revalidate | Rating |
|-------------|------|---------------------|--------------|-------------|------------------------|--------|
| **MythPHP** | ✅ | ✅ `withCacheHeaders()` | ✅ | ✅ `vary()` | ✅ `staleWhileRevalidate()` | ⭐⭐⭐⭐½ (4.5) |
| Laravel | ✅ | ✅ HTTP kernel | ✅ | ✅ | ✅ | ⭐⭐⭐⭐⭐ (5.0) |
| Yii2 | ✅ | ✅ | ✅ | ✅ | ❌ | ⭐⭐⭐⭐ (4.0) |
| CI3 | ❌ | ❌ | ❌ | ❌ | ❌ | ⭐ (1.0) |
| CI4 | ❌ | ❌ | ❌ | ❌ | ❌ | ⭐⭐ (2.0) |
| CakePHP | ❌ | ❌ | ❌ | ❌ | ❌ | ⭐⭐ (2.0) |

---

### 4.4 Response Compression

**Verified (`systems/Middleware/CompressResponse.php`):**
- Negotiates `Accept-Encoding`; prefers `br` (brotli) if `ext-brotli` loaded; falls back to `gzip`
- `ob_start()` called **before** `$next($request)` — captures full response buffer correctly
- Brotli invoked via `call_user_func('brotli_compress', ...)` — avoids fatal errors on hosts without the extension
- Sets `Content-Encoding` + `Vary: Accept-Encoding`; skips already-compressed responses

| Framework    | Gzip | Brotli | Correct ob Order | Binary-type Skip | Quality Config | Rating |
|-------------|------|--------|-----------------|-----------------|----------------|--------|
| **MythPHP** | ✅ | ✅ ext-brotli | ✅ | ✅ 14 types | ✅ `framework.compress.*` | ⭐⭐⭐⭐½ (4.5) |
| Laravel | ✅ | ⚠️ server-level | ✅ | ⚠️ | ❌ | ⭐⭐⭐⭐ (4.0) |
| Yii2 | ✅ | ⚠️ | ✅ | ⚠️ | ❌ | ⭐⭐⭐½ (3.5) |
| CI3 | ⚠️ | ❌ | ⚠️ | ❌ | ❌ | ⭐⭐ (2.0) |
| CI4 | ✅ | ❌ | ✅ | ❌ | ❌ | ⭐⭐⭐ (3.0) |
| CakePHP | ✅ | ❌ | ✅ | ❌ | ❌ | ⭐⭐⭐ (3.0) |

---

### 4.5 Filesystem Interface

**Verified (`app/support/Filesystem/`):**

| Method | Interface | Local Adapter |
|--------|-----------|--------------|
| `put`, `get`, `delete`, `exists` | ✅ | ✅ |
| `writeStream`, `readStream` | ✅ | ✅ |
| `copy`, `move`, `path`, `url` | ✅ | ✅ |
| `files`, `allFiles`, `makeDirectory` | ✅ | ✅ |
| `size()` | ✅ | ✅ `filesize()` |
| `lastModified()` | ✅ | ✅ `filemtime()` |
| `temporaryUrl()` | ✅ | ✅ `SignedUrl::generate()` — HMAC, no service |

Path traversal blocked at `normalizeRelativePath()` — `..` → `InvalidArgumentException`.

---

## 5. Worker / Octane Mode

**Verified implementations:**

**`WorkerState` (`systems/Core/Server/WorkerState.php`):**
- Resets all stateful singletons between requests in long-lived worker processes
- `CORE_STATEFUL_CLASSES` lists all framework singletons; calls `reset()`, `flushCache()`, or `resetQueryLog()` per class
- `register()` API for application-defined stateful classes — deduplication enforced

**`RoadRunnerWorker` (`systems/Core/Server/RoadRunnerWorker.php`):**
- Superglobal bridge: maps PSR-7 ServerRequest → `$_GET`, `$_POST`, `$_SERVER`, `$_FILES`, `$_COOKIE`
- `ob_start()` captures output; `ob_get_clean()` feeds into PSR-7 Response
- `WorkerState::flush()` after every request

**`preload.php` (`systems/Core/Server/preload.php`):**
- OPcache preload: pre-compiles all `systems/Core/` PHP at startup
- Eliminates per-request compile overhead in production

| Framework    | Stateful Reset | OPcache Preload | Superglobal Bridge | FrankenPHP Worker | Rating |
|-------------|---------------|-----------------|-------------------|------------------|--------|
| **MythPHP** | ✅ `WorkerState` | ✅ `preload.php` | ✅ RoadRunner | ✅ `public/index.php` native | ⭐⭐⭐⭐½ (4.5) |
| Laravel | ✅ Octane built-in | ✅ | ✅ | ✅ | ⭐⭐⭐⭐⭐ (5.0) |
| Yii2 | ⚠️ Manual | ⚠️ | ⚠️ | ❌ | ⭐⭐½ (2.5) |
| CI3 | ❌ | ❌ | ❌ | ❌ | ⭐ (1.0) |
| CI4 | ⚠️ Partial | ⚠️ | ❌ | ❌ | ⭐⭐ (2.0) |
| CakePHP | ⚠️ Manual | ⚠️ | ❌ | ❌ | ⭐⭐ (2.0) |

---

## 6. Aggregate Scorecard

> This table reflects Phase 11 improvements. See §15 for the full extended scorecard with all 11 categories.

| Category                  | **MythPHP** | **Laravel** | Yii2 | CI4 | CakePHP | CI3 | Native PHP |
|--------------------------|------------|------------|------|-----|---------|-----|-----------|
| **Security Average**      | **4.60** | 4.07 | 3.37 | 2.50 | 3.10 | 1.67 | 1.17 |
| **Database Average**      | **4.47** | 4.75 | 3.69 | 2.06 | 3.44 | 1.50 | 1.19 |
| **Cache & HTTP Average**  | **4.60** | 4.63 | 4.13 | 2.50 | 3.25 | 1.75 | 1.00 |
| **Worker Mode**           | **4.50** | 5.00 | 2.50 | 2.00 | 2.00 | 1.00 | 1.00 |
| **Event System**          | **5.00** | 5.00 | 4.00 | 3.00 | 4.00 | 1.50 | 1.00 |
| **Queue System**          | **4.75** | 5.00 | 3.00 | 1.00 | 2.00 | 1.00 | 1.00 |
| **Overall Average**       | **4.65** | **4.74** | **3.45** | **2.18** | **2.96** | **1.49** | **1.06** |

### MythPHP vs Laravel — Gap Analysis

| Area | MythPHP | Laravel | Who Leads |
|------|---------|---------|-----------|
| Password hashing (Argon2id default) | ✅ Argon2id, 64 MB | ⚠️ Bcrypt default | **MythPHP** |
| SSRF protection | ✅ Built-in `HttpClient` | ❌ No built-in | **MythPHP** |
| Column-level encryption | ✅ AES-256-GCM + blind index built-in | ⚠️ Package required | **MythPHP** |
| Pwned password detection | ✅ HIBP k-Anonymity built-in | ❌ No built-in | **MythPHP** |
| Security headers | ✅ 9 headers built-in | ⚠️ `spatie/laravel-csp` needed | **MythPHP** |
| SQL injection (strictness) | 4.5/5 | 5.0/5 | **Laravel** (fillable is secure-by-default; MythPHP requires explicit opt-in) |
| XSS (GET params) | ✅ All methods scanned (GET included) | Blade output escaping handles it | **Tied** |
| CSRF (auto-apply) | ✅ Applied to all web routes via `web` middleware group | Also applied to all web routes | **Tied** |
| Database ORM maturity | 4.4/5 | 4.75/5 | **Laravel** |
| Worker/Octane maturity | 4.5/5 | 5.0/5 | **Laravel** (battle-tested) |
| Package ecosystem | Custom — growing | Massive Packagist ecosystem | **Laravel** far ahead |

---

## 7. MythPHP Strengths & Known Gaps

### Strengths (All Verified Against Source)

| Area | Verified Mechanism |
|------|-------------------|
| Argon2id password hashing | `Hasher::make()` — OWASP 2024 compliant; single codebase entry point; bcrypt upgrade path |
| `$fillable` / `$guarded` mass-assignment guard | `BaseDatabase::sanitizeColumn()` — two-layer: schema guard always active; `$fillable` restricts to declared columns; `$guarded` hard-blocks sensitive columns |
| AES-256-GCM column encryption | `Encryptor` — libsodium authenticated; blind-index searchability; key zeroed after use |
| k-Anonymity breach detection | `PwnedPasswordChecker` — SHA-1 prefix only to HIBP; audit logged; SSRF-protected call |
| SSRF protection | `HttpClient` — RFC 1918 + loopback + IPv6 blocked before connection; post-connect IP validation and optional SPKI pinning available |
| Atomic rate limiting | APCu `apcu_inc()` or flock + temp-rename — no read-modify-write race in either path |
| IDOR detection | `DetectIdor` — route-param ownership check; `AuditLogger` writes DB row + flat file |
| Full security header suite | 9 headers via `SecurityHeadersTrait` including CSP nonce, Permissions-Policy (array format), COOP, CORP |
| Signed URLs | `SignedUrl` — HMAC-SHA256; `hash_equals()`; no external service required |
| Keyset pagination | `cursorPaginate()` — O(1) deep pages regardless of dataset size |
| Large-dataset iteration & maintenance | `chunk()`, `cursor()`, `lazy()`, `chunkById()`, `lazyById()`, model `each()` / `eachById()`, `importInBatches()`, `upsertInBatches()`, `updateInBatches()` |
| Safe query triage | `toDebugSnapshot()` is gated to CLI / `APP_DEBUG`; slow-query logs redact binds and omit expanded full SQL |
| Content-Disposition safety | `FileUploadGuard::serve()` CRLF-sanitizes filename before header output |
| Worker mode | `WorkerState` + `RoadRunnerWorker` — per-request singleton reset; OPcache preload |
| Shared hosting | APCu → file graceful degrade; all security features work without Redis or root access |

### Known Gaps

| Priority | Gap | Impact | Mitigation |
|----------|-----|--------|-----------|
| **MEDIUM** | `temporaryUrl()` not implemented for S3/GDrive adapters | Remote disk: no signed temp URLs | Local disk fully covered; remote adapters return plain URL |
| **LOW** | Certificate pinning is opt-in per host | HTTPS outbound calls fall back to CA validation unless pins are configured | Add `security.http_client.pins` for high-value upstreams; `pin_on_error` can block or log-only |
| **LOW** | App-defined worker state still needs manual registration | Long-lived workers can retain userland static state outside framework-managed classes | Register custom classes with `WorkerState::register()` |
| **LOW** | No built-in GraphQL rate limiting | REST rate limiting solid; GraphQL depth/complexity unguarded | Add custom query complexity middleware |

---

## 8. Shared Hosting Compatibility Matrix

| Feature | Shared (no APCu, no Redis) | With APCu | VPS / Dedicated |
|---------|---------------------------|-----------|----------------|
| File cache — atomic writes | ✅ temp+rename | ✅ | ✅ |
| Rate limiter — no race | ✅ flock file path | ✅ `apcu_inc()` | ✅ |
| APCu cache tier | Auto-degrades to file | ✅ | ✅ |
| Redis cache tier | ❌ Needs Redis | ❌ | ✅ |
| ETag / 304 conditional GET | ✅ Pure HTTP | ✅ | ✅ |
| Temporary signed file URLs | ✅ HMAC-SHA256, no service | ✅ | ✅ |
| Storage `size()` / `lastModified()` | ✅ `filesize()` / `filemtime()` | ✅ | ✅ |
| Response compression (gzip) | ✅ `ob_gzhandler` always available | ✅ | ✅ |
| Response compression (brotli) | ❌ Needs `ext-brotli` | ❌ unless loaded | ✅ Usually |
| Column encryption (libsodium) | ✅ `ext-sodium` bundled in PHP 8+ | ✅ | ✅ |
| Pwned password check | ✅ HTTPS outbound only | ✅ | ✅ |
| DB SSL/TLS | ✅ PDO config | ✅ | ✅ |
| N+1 detection | ✅ `APP_DEBUG=true` | ✅ | ✅ |
| 3-tier query cache | ✅ memory + file | ✅ memory + APCu + file | ✅ all tiers |
| Worker mode (RoadRunner) | ❌ Needs process control | ❌ | ✅ |
| OPcache preload | ⚠️ Host-dependent | ⚠️ | ✅ |

### Quick-Start Config Examples

```php
// app/config/cache.php
'stores' => [
    'file'  => ['driver' => 'file', 'path' => 'storage/cache/app'],
    'array' => ['driver' => 'array'],
    'apcu'  => ['driver' => 'apcu', 'prefix' => 'myth_app:'],     // APCu on cPanel hosts
    'redis' => ['driver' => 'redis', 'host' => env('REDIS_HOST', '127.0.0.1'), 'port' => 6379],
],
// .env: CACHE_DRIVER=apcu  (or file, or redis)
```

```php
// Keyset pagination — O(1) regardless of page depth:
$users = db()->table('users')
    ->orderBy('id')
    ->cursorPaginate(perPage: 20, cursor: $request->input('cursor'));
```

```php
// Conditional GET — zero bandwidth on cache hit:
$html = view('dashboard', $data);
if (Response::withCacheHeaders($html, new \DateTimeImmutable($lastUpdated))) {
    return; // 304 sent
}
echo $html;
```

```php
// Column-level PII encryption:
$encryptedEmail = Encryptor::encrypt($email);
$searchIndex    = Encryptor::blindIndex($email); // store for WHERE equality
```

```php
// Pwned password check on registration:
if (PwnedPasswordChecker::isPwned($password)) {
    return ['code' => 422, 'message' => 'This password appears in a known data breach.'];
}
```

---

## 9. Queue System Comparison

**Verified MythPHP implementation (`systems/Core/Queue/`):**

**`Job` (abstract base):**
- Jobs are classes extending `Job`; `handle()` method contains the job logic
- `toPayload()` serializes the full job object; `fromPayload()` enforces `allowed_classes: [$class]`, class mismatch check, and `is_subclass_of(Job::class)` guard — blocks PHP object injection attacks via queue table manipulation
- `$maxAttempts` per job; `$retryAfter` delay in seconds; `$timeout` max execution time

**`Worker` (`systems/Core/Queue/Worker.php`):**
- `pop()` uses `SELECT ... FOR UPDATE SKIP LOCKED` — multi-worker safe without advisory locks
- Wrapped in `db()->transaction()` for atomicity — job row is deleted only after successful `handle()`
- `SIGTERM`/`SIGINT` signal handlers for graceful shutdown (finish current job, then exit)
- Sleep loop uses **100 ms `usleep()` chunks** with `pcntl_signal_dispatch()` between each tick — SIGTERM is processed within 100 ms rather than waiting up to `$sleep` seconds (Phase 10 fix)
- `$memory` limit: `memory_get_usage(true)` compared at each iteration; worker exits cleanly when exceeded

| Feature | MythPHP | Laravel | Yii2 | CI4 | CakePHP | CI3 | Native PHP |
|---------|---------|---------|------|-----|---------|-----|------------|
| Queue backend | ✅ DB + Redis | ✅ DB / Redis / SQS / Beanstalk | ✅ DB / Redis | ❌ None built-in | ✅ Via plugin | ❌ | ❌ |
| SKIP LOCKED multi-worker | ✅ | ✅ | ⚠️ | N/A | ⚠️ | N/A | ❌ |
| Atomic pop (transaction) | ✅ | ✅ | ⚠️ | N/A | ⚠️ | N/A | ❌ |
| Graceful SIGTERM shutdown | ✅ | ✅ | ❌ | N/A | ❌ | N/A | ❌ |
| Job retry / delay | ✅ | ✅ | ✅ | N/A | ⚠️ | N/A | ❌ |
| Object injection guard | ✅ `allowed_classes` + mismatch check | ✅ | ⚠️ | N/A | ⚠️ | N/A | ❌ |
| Memory limit per worker | ✅ | ✅ | ❌ | N/A | ❌ | N/A | ❌ |
| Redis queue support | ✅ `RedisQueue` (ZADD + RPOPLPUSH) | ✅ | ✅ | ❌ | ⚠️ | ❌ | ❌ |
| Delayed job scheduling | ✅ sorted-set `migrateDelayed()` | ✅ | ✅ | ❌ | ⚠️ | ❌ | ❌ |
| **Rating** | ⭐⭐⭐⭐¾ (4.75) | ⭐⭐⭐⭐⭐ (5.0) | ⭐⭐⭐ (3.0) | ⭐ (1.0) | ⭐⭐ (2.0) | ⭐ (1.0) | ⭐ (1.0) |

---

## 10. Event System Comparison

**Verified MythPHP implementation (`app/support/EventDispatcher.php`, `systems/Core/Events/`, `app/providers/EventServiceProvider.php`):**
- `EventDispatcher::dispatch(string|object $event, array $payload)` — object-style dispatch (`dispatch(new UserRegistered($id))`) or string-keyed dispatch; fires all registered listeners
- Listeners registered in `EventServiceProvider::$listen` — `'EventName' => [ListenerClass::class, ...]`; also callable/closure listeners
- **Wildcard listeners**: `*` catches all events — useful for audit trails and logging
- **Stoppable propagation**: events extending `Core\Events\StoppableEvent` can call `$event->stopPropagation()`; `EventDispatcher` breaks the listener chain immediately
- **Queued / async listeners**: listener classes implementing `Core\Events\ShouldQueue` are automatically pushed to the background queue via `QueuedListenerJob`; synchronous fallback if queue unavailable
- **Model observers**: `Core\Events\ModelObserver` base class with 10 lifecycle hooks (`creating`, `created`, `updating`, `updated`, `deleting`, `deleted`, `restored`, `forceDeleting`, `forceDeleted`, `retrieved`); registered via `ModelObserverRegistry::observe()`; fires up the inheritance chain
- `EventServiceProvider::boot()` auto-registers all listeners on service container boot
- Per-listener exception isolation: failed listeners are logged; remaining listeners still execute

| Feature | MythPHP | Laravel | Yii2 | CI4 | CakePHP | CI3 | Native PHP |
|---------|---------|---------|------|-----|---------|-----|------------|
| Event dispatch | ✅ String + Object style | ✅ | ✅ | ✅ | ✅ | ⚠️ hooks | ❌ |
| Wildcard listeners | ✅ `*` | ✅ `*` | ❌ | ❌ | ⚠️ | ❌ | ❌ |
| Listener auto-discovery | ✅ `$listen` map | ✅ Auto-discover | ❌ Manual | ❌ | ❌ | ❌ | ❌ |
| Queued / async listeners | ✅ `ShouldQueue` interface | ✅ `ShouldQueue` | ✅ | ❌ | ❌ | ❌ | ❌ |
| Stoppable propagation | ✅ `StoppableEvent` base class | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ |
| Event observers | ✅ `ModelObserver` + `ModelObserverRegistry` | ✅ Eloquent | ⚠️ | ❌ | ✅ | ❌ | ❌ |
| Per-listener fault isolation | ✅ Exception caught, next listener runs | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| **Rating** | ⭐⭐⭐⭐⭐ (5.0) | ⭐⭐⭐⭐⭐ (5.0) | ⭐⭐⭐⭐ (4.0) | ⭐⭐⭐ (3.0) | ⭐⭐⭐⭐ (4.0) | ⭐½ (1.5) | ⭐ (1.0) |

---

## 11. Routing & CLI / Console Comparison

### 11.1 Routing

**Verified MythPHP (`systems/Core/Routing/Router.php`, `app/routes/`):**
- Named routes + route groups with prefix, namespace, middleware stacks
- **Automatic route model binding**: type-hinted `Model` subclass parameters in controller methods are resolved automatically via `findById()` — 404 JSON response on miss; no manual lookup needed
- **Route caching**: `php myth route:cache` — serializes route table to PHP file; ~0 μs dispatch overhead on cache hit
- Middleware pipeline: global → group → route-specific; overridable middleware aliases supported
- RESTful resource routing via `route()->resource()`
- API versioned routes: `app/routes/API/`
- `Router::url()` generates named-route URLs; signed URLs via `SignedUrl`
- `FormRequest` auto-injection with `validateResolved()` before controller is called

| Feature | MythPHP | Laravel | Yii2 | CI4 | CakePHP | CI3 | Native PHP |
|---------|---------|---------|------|-----|---------|-----|------------|
| Named routes | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ |
| Route groups + middleware | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ |
| Route caching | ✅ `route:cache` | ✅ `route:cache` | ❌ | ❌ | ⚠️ | ❌ | ❌ |
| Resource routing | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ |
| Route model binding | ✅ **Auto** type-hinted | ✅ Auto | ⚠️ | ❌ | ⚠️ | ❌ | ❌ |
| FormRequest auto-injection | ✅ | ✅ | ⚠️ | ✅ | ⚠️ | ❌ | ❌ |
| API versioning | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ |
| Subdomain routing | ❌ | ✅ | ⚠️ | ❌ | ✅ | ❌ | ❌ |
| **Rating** | ⭐⭐⭐⭐¾ (4.75) | ⭐⭐⭐⭐⭐ (5.0) | ⭐⭐⭐⭐ (4.0) | ⭐⭐⭐ (3.0) | ⭐⭐⭐⭐ (4.0) | ⭐⭐ (2.0) | ⭐ (1.0) |

### 11.2 CLI / Console Tooling

**Verified MythPHP (`myth` binary, `systems/Core/Console/`):**

| Command | Purpose |
|---------|---------|
| `php myth serve` | Built-in dev server (Caddy / PHP built-in) |
| `php myth migrate` | Run pending DB migrations |
| `php myth migrate:rollback` | Roll back last batch |
| `php myth db:seed` | Run database seeders |
| `php myth db:benchmark` | Performance benchmark |
| `php myth route:cache` | Cache route table for production |
| `php myth route:clear` | Clear route cache |
| `php myth view:cache` | Pre-compile all Blade templates |
| `php myth view:clear` | Clear compiled view cache |
| `php myth config:cache` | Cache all config files into single PHP file |
| `php myth key:generate` | Generate new `APP_KEY` and write to `.env` |
| `php myth schedule:run` | Execute all due scheduled commands (cron target) |
| `php myth schedule:work` | Run scheduler in foreground (dev mode) |
| `php myth schedule:list` | List all registered scheduled tasks |
| `php myth queue:work` | Start queue worker |
| `php myth queue:retry {id\|all}` | Re-queue failed job(s) |
| `php myth queue:failed` | List failed jobs |
| `php myth queue:flush` | Delete all failed jobs |
| `php myth queue:clear` | Clear all pending jobs from a queue |
| `php myth make:controller` | Scaffold controller |
| `php myth make:model` | Scaffold model |
| `php myth make:migration` | Scaffold migration file |
| `php myth make:command` | Scaffold custom console command |

| Feature | MythPHP | Laravel | Yii2 | CI4 | CakePHP | CI3 | Native PHP |
|---------|---------|---------|------|-----|---------|-----|------------|
| CLI entry point | ✅ `myth` | ✅ `artisan` | ✅ `yii` | ✅ `spark` | ✅ `cake` | ❌ | ❌ |
| Route caching CLI | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| View pre-compilation CLI | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Config cache CLI | ✅ `config:cache` | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Key generation CLI | ✅ `key:generate` | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Task scheduling | ✅ `schedule:run/work/list` | ✅ | ⚠️ | ⚠️ | ✅ | ❌ | ❌ |
| Queue worker CLI | ✅ (5 queue commands) | ✅ | ✅ | ❌ | ⚠️ | ❌ | ❌ |
| Code scaffolding | ✅ make:* | ✅ make:* | ✅ gii | ✅ make:* | ✅ bake | ❌ | ❌ |
| Custom commands | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ |
| **Rating** | ⭐⭐⭐⭐¾ (4.75) | ⭐⭐⭐⭐⭐ (5.0) | ⭐⭐⭐⭐ (4.0) | ⭐⭐⭐ (3.0) | ⭐⭐⭐⭐ (4.0) | ⭐ (1.0) | ⭐ (1.0) |

---

## 12. Validation System Comparison

**Verified MythPHP (`systems/Components/Validation.php`, `app/http/requests/`):**
- `FormRequest` base class — validation rules declared in `rules()` method
- `$request->validate(rules)` inline validation with auto-bail
- **60+ built-in rules** verified in source: `required`, `string`, `numeric`, `integer`, `boolean`, `email`, `url`, `ip`, `min`, `max`, `min_length`, `max_length`, `between`, `size`, `same`, `different`, `confirmed`, `in`, `not_in`, `alpha`, `alpha_num`, `alpha_dash`, `regex`, `not_regex`, `date`, `date_format`, `before`, `after`, `date_equals`, `accepted`, `array`, `file`, `image`, `mimes`, `gt`, `gte`, `lt`, `lte`, `starts_with`, `ends_with`, `required_if`, `required_unless`, `required_with`, `required_without`, `required_without_all`, `exclude_if`, `exclude_unless`, `json`, `uuid`, `password`, `base64`, `xss`, `safe_html`, `no_sql_injection`, `secure_filename`, `secure_value`, `file_extension`, `max_file_size`, `deep_array`, `array_keys`, `nullable`, `sometimes`, `bail`, `distinct`, `prohibited`, `prohibited_if`, `current_password`, `filled`
- Security-specialised rules: `xss`, `no_sql_injection`, `secure_filename`, `secure_value` — MythPHP unique
- Custom validation rules via `Closure` or rule class implementing `ValidationRule`
- Error messages: default English set; customizable per field via `messages()` override
- Nested/array validation: `items.*`, `items.*.field` dot-notation supported
- `validateCurrentpassword()` — uses `Hasher::verify()` for current-password confirmation

| Feature | MythPHP | Laravel | Yii2 | CI4 | CakePHP | CI3 | Native PHP |
|---------|---------|---------|------|-----|---------|-----|------------|
| Request class validation | ✅ `FormRequest` | ✅ `FormRequest` | ✅ Model rules | ⚠️ Limited | ✅ | ❌ | ❌ |
| 60+ built-in rules | ✅ **60+** | ✅ 60+ | ✅ | ✅ | ✅ | ⚠️ | ❌ |
| Security rules (XSS, SQLi, etc.) | ✅ **Built-in** | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Nested array rules | ✅ `items.*` | ✅ | ⚠️ | ✅ | ✅ | ❌ | ❌ |
| Custom rule classes | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ |
| Unique / exists DB rules | ✅ | ✅ | ✅ | ⚠️ | ✅ | ❌ | ❌ |
| Conditional rules (`sometimes`) | ✅ | ✅ | ✅ | ⚠️ | ✅ | ❌ | ❌ |
| Auto-halt on first failure | ✅ `bail` | ✅ `bail` | ✅ | ❌ | ✅ | ❌ | ❌ |
| **Rating** | ⭐⭐⭐⭐¾ (4.75) | ⭐⭐⭐⭐⭐ (5.0) | ⭐⭐⭐⭐ (4.0) | ⭐⭐⭐ (3.0) | ⭐⭐⭐⭐ (4.0) | ⭐⭐ (2.0) | ⭐ (1.0) |

---

## 13. View / Template Engine Comparison

**Verified MythPHP (`systems/Core/View/BladeEngine.php`):**
- Compatible Blade syntax: `{{ $var }}` (auto-escaped), `{!! $raw !!}`, `@if`, `@foreach`, `@forelse`, `@while`, `@switch/@case`, `@include`, `@extends`, `@section`, `@yield`, `@component`
- `@csrf` — outputs CSRF hidden input
- `@nonce` — outputs `nonce="{{ $csp_nonce }}"` HTML-escaped
- `@sri('url', 'hash')` — SRI integrity attribute; validates hash format before output
- `@auth` / `@guest` / `@can` — auth/permission directives
- **Component system**: `@component('view', ['prop' => $val])` / `@slot('name')` / `@endslot` / `@endcomponent` — named slots become individual variables inside the component view; default content is always `$slot`
- **View caching**: `php myth view:cache` pre-compiles all templates to PHP; production zero-compile overhead
- **View route caching**: compiled paths cached in static `$compiledPathCache` (max 256 entries) — bounded memory growth in workers
- `BladeEngine::reset()` — static method for worker-mode per-request cache flush (prevents stale CSP nonce leakage)
- Custom directive registration via `addDirective(string $name, callable $handler)`

| Feature | MythPHP | Laravel | Yii2 | CI4 | CakePHP | CI3 | Native PHP |
|---------|---------|---------|------|-----|---------|-----|------------|
| Auto-escape | ✅ `{{ }}` | ✅ `{{ }}` | ✅ `<?= h() ?>` | ⚠️ Manual | ✅ `<?= h() ?>` | ⚠️ | ❌ Manual |
| Template inheritance | ✅ `@extends` | ✅ | ✅ | ✅ | ✅ | ⚠️ | ❌ |
| Component / slot system | ✅ `@component`/`@slot` | ✅ Blade components | ⚠️ | ❌ | ⚠️ | ❌ | ❌ |
| View pre-compilation CLI | ✅ `view:cache` | ✅ `view:cache` | ❌ | ❌ | ❌ | ❌ | ❌ |
| CSP nonce integration | ✅ `@nonce` | ✅ Jetstream | ❌ | ❌ | ❌ | ❌ | ❌ |
| SRI directive | ✅ `@sri` | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Custom directives | ✅ | ✅ | ⚠️ | ❌ | ⚠️ | ❌ | ❌ |
| Auth directives (`@auth`, `@can`) | ✅ | ✅ | ⚠️ | ❌ | ⚠️ | ❌ | ❌ |
| Worker-safe static reset | ✅ `BladeEngine::reset()` | ✅ Octane | ❌ | ❌ | ❌ | ❌ | ❌ |
| **Rating** | ⭐⭐⭐⭐¾ **(4.75)** | ⭐⭐⭐⭐⭐ (5.0) | ⭐⭐⭐½ (3.5) | ⭐⭐⭐ (3.0) | ⭐⭐⭐½ (3.5) | ⭐⭐ (2.0) | ⭐ (1.0) |

---

## 14. Error Handling & Logging Comparison

**Verified MythPHP:**

**Error Handling (`systems/Core/`, `app/http/Kernel.php`):**
- Global `set_exception_handler` + `set_error_handler` registered in bootstrap
- `APP_DEBUG=true` → renders full exception trace with code context in browser/response
- `APP_DEBUG=false` → generic error page; stack trace written to log only
- `ExceptionHandler::$httpExceptionMap` — 12 exception class → HTTP status mappings (`InvalidArgumentException`→4xx, `BadMethodCallException`→405, `OverflowException`→409, etc.); `resolveStatusCode()` checks exact class, then instanceof hierarchy, then `$e->getCode()` fallback — no more status=0 surprises
- HTTP error views: `views/errors/404.php`, `views/errors/500.php` — fully customizable
- `Response::json(['error' => ...], 500)` for API routes — never leaks stack trace to client in production

**Logging (`systems/Core/Log/`, Monolog):**
- Backed by **Monolog** — multiple handlers: `StreamHandler` (rotating file), configurable
- `LogServiceProvider` registers `Psr\Log\LoggerInterface` in container
- `AuditLogger` — dedicated security event log: IDOR suspects, brute force, pwned passwords, all written to `security_audit_log` DB table + NDJSON flat file — queryable and grep-friendly
- `PerformanceMonitor::logSlowQuery()` — slow queries logged with full SQL + execution time

| Feature | MythPHP | Laravel | Yii2 | CI4 | CakePHP | CI3 | Native PHP |
|---------|---------|---------|------|-----|---------|-----|------------|
| PSR-3 logging | ✅ Monolog | ✅ Monolog | ✅ | ✅ | ✅ | ❌ | ❌ |
| Rotating file logs | ✅ | ✅ | ✅ | ✅ | ✅ | ⚠️ | ❌ |
| Debug ≠ production error | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ |
| Security audit log | ✅ DB + NDJSON | ❌ No built-in | ❌ | ❌ | ❌ | ❌ | ❌ |
| Slow query logging | ✅ `PerformanceMonitor` | ✅ Telescope | ⚠️ | ❌ | ❌ | ❌ | ❌ |
| Custom exception handlers | ✅ | ✅ | ✅ | ✅ | ✅ | ⚠️ | ✅ `set_exception_handler` |
| Stack trace hidden in prod | ✅ | ✅ | ✅ | ✅ | ✅ | ⚠️ | ❌ |
| Structured API error response | ✅ JSON, no leak in prod | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ |
| **Rating** | ⭐⭐⭐⭐½ (4.5) | ⭐⭐⭐⭐⭐ (5.0) | ⭐⭐⭐⭐ (4.0) | ⭐⭐⭐⭐ (4.0) | ⭐⭐⭐⭐ (4.0) | ⭐⭐ (2.0) | ⭐½ (1.5) |

---

## 15. Extended Aggregate Scorecard

> Updated after a May 2026 source re-audit covering score reconciliation, XSS middleware behaviour, HttpClient hardening, and database streaming / batching capabilities.

| Category                     | **MythPHP** | **Laravel** | Yii2 | CI4 | CakePHP | CI3 | Native PHP |
|-----------------------------|------------|------------|------|-----|---------|-----|-----------|
| **Security Average**         | **4.60** | 4.07 | 3.37 | 2.50 | 3.10 | 1.67 | 1.17 |
| **Database Average**         | **4.47** | 4.75 | 3.69 | 2.06 | 3.44 | 1.50 | 1.19 |
| **Cache & HTTP Average**     | **4.60** | 4.63 | 4.13 | 2.50 | 3.25 | 1.75 | 1.00 |
| **Worker Mode**              | **4.50** | 5.00 | 2.50 | 2.00 | 2.00 | 1.00 | 1.00 |
| **Queue System**             | **4.75** | 5.00 | 3.00 | 1.00 | 2.00 | 1.00 | 1.00 |
| **Event System**             | **5.00** | 5.00 | 4.00 | 3.00 | 4.00 | 1.50 | 1.00 |
| **Routing**                  | **4.75** | 5.00 | 4.00 | 3.00 | 4.00 | 2.00 | 1.00 |
| **CLI / Console**            | **4.75** | 5.00 | 4.00 | 3.00 | 4.00 | 1.00 | 1.00 |
| **Validation**               | **4.75** | 5.00 | 4.00 | 3.00 | 4.00 | 2.00 | 1.00 |
| **View / Templating**        | **4.75** | 5.00 | 3.50 | 3.00 | 3.50 | 2.00 | 1.00 |
| **Error Handling / Logging** | **4.50** | 5.00 | 4.00 | 4.00 | 4.00 | 2.00 | 1.00 |
| **Extended Overall Average** | **4.67** | **4.86** | **3.65** | **2.64** | **3.39** | **1.58** | **1.03** |

### Gap Summary vs Laravel (Phase 11)

| Area | MythPHP | Laravel | Who Leads |
|------|---------|---------|-----------|
| Security | **4.60** | 4.07 | **MythPHP** +0.53 |
| Database | **4.47** | **4.75** | Laravel +0.28 |
| Cache & HTTP | 4.60 | **4.63** | Laravel +0.03 (near parity) |
| Worker Mode | 4.50 | **5.00** | Laravel +0.50 |
| Queue System | 4.75 | **5.00** | Laravel +0.25 |
| Event System | **5.00** | **5.00** | **Tied** |
| Routing | 4.75 | **5.00** | Laravel +0.25 |
| CLI / Console | 4.75 | **5.00** | Laravel +0.25 |
| Validation | 4.75 | **5.00** | Laravel +0.25 |
| View / Templating | 4.75 | **5.00** | Laravel +0.25 |
| Error Handling | 4.50 | **5.00** | Laravel +0.50 |
| **Extended Overall** | **4.67** | **4.86** | Laravel +0.19 |

---

**MythPHP leads in:** Security (Argon2id default, column encryption, SSRF guard, pwned-password detection, 9 security headers, audit trail — all built-in, zero packages required). Event system at full parity with Laravel. Cache & HTTP within 0.03 of Laravel (effectively tied).

**Laravel leads in:** Ecosystem maturity, Eloquent ORM, Octane battle-hardening, subdomain routing, massive package ecosystem.

**Remaining improvement roadmap for full parity:**
1. ~~View: add component system (Blade components / slots)~~ **✅ Done (Phase 12)** — `@component('view', [props])` / `@slot('name')` / `@endslot` / `@endcomponent` added to `BladeEngine`; named slots become individual variables; default content exposed as `$slot`
2. ~~Database: add eager-loading relationship definitions and scope methods~~ **✅ Done (prior phase)** — `Scopeable` trait provides `scope()`, `withGlobalScope()`, `withoutGlobalScopes()`; eager loading via `with()` / `withOne()` plus aggregate eager-load helpers
3. ~~Error handling: add structured exception rendering with HTTP status mapping per exception type~~ **✅ Done (Phase 12)** — `ExceptionHandler::$httpExceptionMap` maps 12 exception classes → HTTP status codes; `resolveStatusCode()` checks exact class, then instanceof hierarchy, then `$e->getCode()` fallback

---

*All MythPHP claims are based on direct source inspection, May 2026. This re-audit covered `HttpClient`, `XssProtection`, `DatabaseRuntime`, `PerformanceMonitor`, `SlowQueryLogger`, `Model`, `HasStreaming`, `BladeEngine`, `WorkerState`, `Worker`, `Job`, and the surrounding security / database subsystems. Local verification during this review included green reruns of the previously failing HttpClient and model-related test slices plus focused PHPStan reruns for the affected test files. Performance figures remain algorithm-analysis estimates; measure on target hardware.*
