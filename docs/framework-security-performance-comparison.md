# Framework Security & Performance Comparison Report

**Generated:** May 2026 (Updated)  
**PHP Baseline:** PHP 8.4  
**Scope:** MythPHP vs Native PHP, Laravel, Yii2, CodeIgniter 3, CodeIgniter 4, CakePHP  
**Evaluation Areas:** Security (OWASP attack vectors) + Large-Dataset Database Performance

---

## Table of Contents

1. [Frameworks Under Review](#1-frameworks-under-review)
2. [Security Analysis](#2-security-analysis)
   - 2.1 SQL Injection Protection
   - 2.2 XSS Protection
   - 2.3 Null Byte Attacks
   - 2.4 IDOR (Insecure Direct Object Reference)
   - 2.5 CSRF Protection
   - 2.6 Security Headers
   - 2.7 Rate Limiting & Brute Force
   - 2.8 Authentication Security
   - 2.9 File Upload Security
   - 2.10 Routing Safety & Middleware Permission
3. [Database Performance Analysis (1M–2M Records)](#3-database-performance-analysis-1m2m-records)
   - 3.1 Pagination Performance
   - 3.2 Export / Report Generation
   - 3.3 N+1 Query Problem Handling
   - 3.4 Memory & Time Benchmarks (Theoretical Estimates)
4. [Aggregate Scorecard](#4-aggregate-scorecard)
5. [MythPHP Strengths & Weaknesses Summary](#5-mythphp-strengths--weaknesses-summary)
6. [Improvement Recommendations for MythPHP](#6-improvement-recommendations-for-mythphp)

---

## 1. Frameworks Under Review

| # | Framework      | Version Basis | Type          | Notes |
|---|---------------|--------------|---------------|-------|
| A | **MythPHP**   | Custom (May 2026) | Custom        | Lightweight, Laravel-inspired, no Composer model layer; PHP 8.4 |
| B | **Native PHP**| PHP 8.4          | Procedural    | No framework layer; developer-managed security |
| C | **Laravel**   | 12.x (Feb 2025)  | Full-Stack    | Industry standard, Eloquent ORM, extensive security; PHP 8.2+ |
| D | **Yii2**      | 2.0.52+          | Full-Stack    | Enterprise-grade, ActiveRecord, strong RBAC; maintained |
| E | **CI3**       | 3.1.13 (EOL)     | Micro         | Legacy, procedural style, no ORM; security patch-only |
| F | **CI4**       | 4.6.x (2025)     | Micro         | Modern rewrite, PSR-4, improved security; PHP 8.1+ |
| G | **CakePHP**   | 5.1.x (2025)     | Full-Stack    | Convention-over-configuration, mature ORM; PHP 8.1+ |

---

## 2. Security Analysis

### 2.1 SQL Injection Protection

**Attack:** Injecting malicious SQL via user-supplied input to manipulate queries.

| Framework     | Mechanism                                                                 | Rating |
|--------------|---------------------------------------------------------------------------|--------|
| MythPHP      | PDO prepared statements throughout query builder; `containsSqlInjection()` validator helper; injection detection in `ValidateRequestSafety`; `whereRaw` accepts binding arrays; **`sanitizeColumn()` auto-strips unknown columns on every `insert()`/`update()`**; **FormRequest `rules()` whitelist drops any field not declared in rules — equivalent to `$fillable`** | ⭐⭐⭐⭐ (4.0/5) |
| Native PHP   | Developer must manually use `PDO::prepare()` / `mysqli_prepare()`; no framework layer | ⭐⭐ (2.0/5) |
| Laravel      | Eloquent ORM + Query Builder with full PDO binding; mass-assignment `$fillable`/`$guarded`; Eloquent Strict mode warnings | ⭐⭐⭐⭐⭐ (5.0/5) |
| Yii2         | ActiveRecord + Query Builder with PDO parameterization; `\yii\db\Expression` for raw | ⭐⭐⭐⭐⭐ (5.0/5) |
| CI3          | Query Builder `$this->db->escape()` and active record bindings; but older `$this->db->query()` with raw SQL is still common | ⭐⭐⭐ (3.0/5) |
| CI4          | Query Builder with PDO binding; improved validator; `RawSql` class for explicit raw injection | ⭐⭐⭐⭐ (4.0/5) |
| CakePHP      | ORM with parameterized queries; `Query::where()` auto-binds; raw query wrapper available | ⭐⭐⭐⭐½ (4.5/5) |

**MythPHP Notes:**
- ✅ All query builder methods (`where`, `whereIn`, `whereBetween`, etc.) use PDO prepared statements
- ✅ `containsSqlInjection()` in `Security` component for application-layer detection
- ✅ Grouped closure `where()` clauses reuse builder-generated SQL instead of re-triggering raw-query guards
- ✅ Empty `whereIn([])` compiles to a safe false predicate instead of invalid SQL
- ✅ **`sanitizeColumn($data)`** — called automatically inside `insert()` and `update()`; fetches `SHOW COLUMNS` from DB at runtime (cached per-request), drops any key whose column does not exist in the actual table. Prevents unknown-column injection and is the DB-layer equivalent of `$fillable`.
- ✅ **FormRequest whitelist** — after validation passes, all fields NOT declared in `rules()` are silently dropped (`foreach (array_keys($rules) as $key)`). This is the form-input equivalent of `$guarded` / `$fillable`. No explicit allow-list annotation needed.
- ⚠️ `whereRaw()` requires developer discipline — same as all frameworks

---

### 2.2 XSS (Cross-Site Scripting) Protection

**Attack:** Injecting malicious scripts that execute in other users' browsers.

| Framework     | Mechanism                                                                 | Rating |
|--------------|---------------------------------------------------------------------------|--------|
| MythPHP      | `XssProtection` middleware with `XssProtectionTrait`; file name XSS scanning; `containsXss()` in Security; CSP header policy; Blade-like `{{ }}` escaping in templates | ⭐⭐⭐⭐ (4.0/5) |
| Native PHP   | Manual `htmlspecialchars()` — easily forgotten; no auto-escaping          | ⭐½ (1.5/5) |
| Laravel      | Blade `{{ }}` auto-escapes with `htmlspecialchars`; `{!! !!}` for raw; `@json` directive is safe; Sanitizer package ecosystem | ⭐⭐⭐⭐⭐ (5.0/5) |
| Yii2         | `Html::encode()` auto-applied in widget rendering; `HtmlPurifier` integration | ⭐⭐⭐⭐½ (4.5/5) |
| CI3          | Manual escaping; `xss_clean()` filter available but not default; no auto-escaping in views | ⭐⭐½ (2.5/5) |
| CI4          | `esc()` function; `IncomingRequest::getVar()` has optional XSS filtering; no auto-escaping in Twig alternative | ⭐⭐⭐½ (3.5/5) |
| CakePHP      | `h()` helper auto-escapes in templates; Form helper auto-escapes field output | ⭐⭐⭐⭐ (4.0/5) |

**MythPHP Notes:**
- ✅ Middleware-level scanning of POST/PUT/PATCH/DELETE payloads
- ✅ File name scanning detects `null bytes`, path traversal, HTML tags, dangerous extensions
- ✅ CSP configured to disallow inline scripts by default in `SecurityHeadersTrait`
- ✅ `containsMalicious()` detects script tags, `data://`, `php://`, obfuscation patterns
- ⚠️ XSS middleware only applies to state-changing requests (GET not scanned)
- ⚠️ `{!! $var !!}` raw output directive bypasses auto-escaping — must only receive pre-sanitized or trusted values; no linter/audit tooling exists to flag unsafe `{!! !!}` usage in templates

---

### 2.3 Null Byte Attacks

**Attack:** Injecting `\x00` (null byte) to truncate strings, bypass file extension checks, or exploit C-level string handling.

| Framework     | Mechanism                                                                 | Rating |
|--------------|---------------------------------------------------------------------------|--------|
| MythPHP      | `Security::normalizeRelativeProjectPath()` explicitly rejects null bytes; `fileNameHasXss()` pattern `/[\x00<>]/` blocks null bytes in file names; `sanitizeStorageSegment()` strips control chars; **`Request::capture()` now strips null bytes from all GET, POST, JSON body, and PUT/PATCH/DELETE form-body inputs** | ⭐⭐⭐⭐½ (4.5/5) |
| Native PHP   | No built-in protection; `preg_replace('/\x00/', '', $str)` must be added manually | ⭐½ (1.5/5) |
| Laravel      | Path normalization via Symfony filesystem; file upload uses `getClientOriginalName()` with sanitization; Storage facade normalizes paths | ⭐⭐⭐⭐ (4.0/5) |
| Yii2         | `BaseYii::getAlias()` validates path components; no explicit null byte guard exposed in docs | ⭐⭐⭐½ (3.5/5) |
| CI3          | `Security::sanitize_filename()` removes null bytes; Input class strips with `_clean_input_data()` | ⭐⭐⭐ (3.0/5) |
| CI4          | `Security::sanitizeFilename()` improved; IncomingRequest input cleaning strips null bytes | ⭐⭐⭐½ (3.5/5) |
| CakePHP      | `Text::cleanInsert()` and file path helpers handle null bytes; `Validation::filename()` strips | ⭐⭐⭐½ (3.5/5) |

**MythPHP Notes:**
- ✅ Null byte rejection is explicit (`/[\x00<>]/`) in file name validation
- ✅ Storage segment sanitizer removes control characters (covers `\x00`–`\x1F`)
- ✅ Path normalization rejects the patterns before they reach filesystem operations
- ✅ **IMPLEMENTED:** `Request::capture()` now calls `stripNullBytes()` on `$_GET`, `$_POST`, JSON-decoded body, and form-parsed PUT/PATCH/DELETE bodies via `array_walk_recursive()` — global coverage
- ~~⚠️ No global request-level null byte stripping on GET/POST values~~

---

### 2.4 IDOR (Insecure Direct Object Reference)

**Attack:** Accessing resources by guessing/incrementing IDs without proper authorization checks.

| Framework     | Mechanism                                                                 | Rating |
|--------------|---------------------------------------------------------------------------|--------|
| MythPHP      | RBAC middleware (`permission`, `permission.any`, `role`, `ability`); fluent route-level authorization via `->permission()`, `->can()`, `->role()`, `->ability()`, `->permissionAny()`, `->canAny()` on `RouteDefinition`; `->where('id','[0-9]+')` parameter constraints prevent type confusion; `can()`/`cannot()` in Controller base; encoded ID helpers (`restoreByEncodedId`, `destroyByEncodedId`); FormRequest `authorize()` method | ⭐⭐⭐⭐ (4.0/5) |
| Native PHP   | No protection — fully manual; IDs are typically sequential integers in plain sight | ⭐ (1.0/5) |
| Laravel      | Policies + Gates; FormRequest `authorize()`; Route model binding with ownership check; `can()` in controllers; Sanctum/Passport scopes | ⭐⭐⭐⭐⭐ (5.0/5) |
| Yii2         | RBAC with configurable rules and `checkAccess()`; `AccessControl` filter on controllers | ⭐⭐⭐⭐½ (4.5/5) |
| CI3          | No built-in RBAC; manual `$this->session->userdata()` check; IDOR entirely developer responsibility | ⭐½ (1.5/5) |
| CI4          | Filter system + Shield library provides Auth/RBAC; still no automatic route-model binding | ⭐⭐⭐½ (3.5/5) |
| CakePHP      | Authorization component with policies and `mapAction()`; `can()` helper; ownership check possible via custom policies | ⭐⭐⭐⭐½ (4.5/5) |

**MythPHP Notes:**
- ✅ Middleware aliases `permission`, `role`, `ability` cover route-level authorization
- ✅ Fluent route authorization: `->permission('user-edit')`, `->can('post-delete')`, `->role(['admin','manager'])`, `->ability(['edit-post','delete-post'])`, `->permissionAny(['read','write'])` on any `RouteDefinition`
- ✅ Route parameter type-safety: `->where('id', '[0-9]+')` ensures IDs are always numeric before hitting controller
- ✅ Encoded ID helpers make sequential integer IDs harder to enumerate
- ✅ FormRequest `authorize()` lifecycle step allows record-level ownership checks
- ⚠️ No automatic route-model binding with ownership verification (like Laravel's)
- ⚠️ Encoded IDs (`restoreByEncodedId`) are a mitigation aid, not a security guarantee — authorization check is still required
- **Recommendation:** Enforce FormRequest `authorize()` on all resource routes; document an ownership-check pattern

---

### 2.5 CSRF Protection

**Attack:** Tricking an authenticated user's browser into making unauthorized state-changing requests.

| Framework     | Mechanism                                                                 | Rating |
|--------------|---------------------------------------------------------------------------|--------|
| MythPHP      | `VerifyCsrfToken` middleware; Origin/Referer check; configurable `SameSite=Lax` cookie; token in header (`X-CSRF-TOKEN`); `@csrf` Blade directive; API routes excluded by wildcard; secure cookie flag from `.env` | ⭐⭐⭐⭐ (4.0/5) |
| Native PHP   | Fully manual — `$_SESSION['token']` pattern if developer implements it   | ⭐ (1.0/5) |
| Laravel      | `VerifyCsrfToken` middleware auto-applied to `web` group; `@csrf` directive; token stored in session and cookie; AJAX helper via meta tag | ⭐⭐⭐⭐⭐ (5.0/5) |
| Yii2         | Built-in CSRF validation; `Html::beginForm()` auto-injects token; AJAX header support | ⭐⭐⭐⭐½ (4.5/5) |
| CI3          | Optional CSRF protection in `config.php`; token per-URI or global; no Origin check | ⭐⭐⭐ (3.0/5) |
| CI4          | CSRF filter; cookie-based token; `SameSite` support; per-session or per-request tokens | ⭐⭐⭐⭐ (4.0/5) |
| CakePHP      | `CsrfProtectionMiddleware`; auto-injected into Form helper; supports cookie + session modes | ⭐⭐⭐⭐½ (4.5/5) |

**MythPHP Notes:**
- ✅ `CSRF_REGENERATE=false` default prevents modal/AJAX token drift
- ✅ Origin/Referer double-submit check as extra layer
- ✅ Frontend helpers sync CSRF token from AJAX responses and inject into modal-loaded forms
- ✅ Configurable `csrf_exclude_uris` and `csrf_include_uris` for API routes
- ⚠️ Token regeneration disabled by default — tokens live until expiry (`CSRF_EXPIRE`)

---

### 2.6 Security Headers

**Attack surface:** Clickjacking, MIME sniffing, data: URI attacks, cross-origin resource leakage.

| Framework     | Mechanism                                                                 | Rating |
|--------------|---------------------------------------------------------------------------|--------|
| MythPHP      | `SetSecurityHeaders` middleware: HSTS, CSP (config-driven), X-Frame-Options, X-Content-Type-Options, Permissions-Policy, Referrer-Policy, COOP, CORP, X-DNS-Prefetch-Control — all config-driven | ⭐⭐⭐⭐½ (4.5/5) |
| Native PHP   | Entirely manual `header()` calls                                          | ⭐ (1.0/5) |
| Laravel      | No built-in security header middleware (requires `spatie/laravel-csp` or custom); some security via session cookie flags | ⭐⭐⭐ (3.0/5) |
| Yii2         | No built-in security header middleware; extensible but requires custom/3rd-party | ⭐⭐⭐ (3.0/5) |
| CI3          | No built-in security header support                                        | ⭐½ (1.5/5) |
| CI4          | No built-in security header middleware; manual `$response->setHeader()` | ⭐⭐ (2.0/5) |
| CakePHP      | `SecurityHeadersMiddleware` added in 3.5+; HSTS, X-Frame-Options, X-Content-Type available | ⭐⭐⭐½ (3.5/5) |

**MythPHP Notes:**
- ✅ Out-of-the-box HSTS with `includeSubDomains` + `preload` options
- ✅ CSP with configurable CDN domain allow-listing (not hardcoded)
- ✅ Permissions-Policy for fine-grained browser feature control
- ✅ Cross-Origin isolation headers (COOP/CORP) configured by default
- ✅ **IMPLEMENTED:** `nonce_enabled: true` in `security.php` activates per-request nonces via `Core\Security\CspNonce`; removes `'unsafe-inline'` and injects `nonce-{value}` into `script-src` / `style-src`; Blade `{{ $csp_nonce }}` and `@nonce` directive available
- ⚠️ `nonce_enabled` defaults to `false` for backward compatibility — set to `true` in production for strongest XSS defense

---

### 2.7 Rate Limiting & Brute Force Protection

| Framework     | Mechanism                                                                 | Rating |
|--------------|---------------------------------------------------------------------------|--------|
| MythPHP      | `RateLimit` middleware (6 scope modes); `ThrottleRequests` for aggressive IP blocking; `systems_login_policy` config with attempt tracking, lockout, auto-ban; brute force record in `system_login_attempt` | ⭐⭐⭐⭐ (4.0/5) |
| Native PHP   | Manual; session-based counters or database tracking if implemented        | ⭐ (1.0/5) |
| Laravel      | `throttle` middleware; `RateLimiter` facade; per-route configurable limiters; Sanctum per-token limits | ⭐⭐⭐⭐⭐ (5.0/5) |
| Yii2         | `RateLimiter` behavior on controllers; `yii\filters\RateLimiter`          | ⭐⭐⭐⭐ (4.0/5) |
| CI3          | No built-in rate limiting                                                  | ⭐ (1.0/5) |
| CI4          | `Throttle` filter available; Atom/Shield provides login protection         | ⭐⭐⭐ (3.0/5) |
| CakePHP      | No built-in rate limiting (3rd-party required); Auth plugin handles lockout | ⭐⭐½ (2.5/5) |

---

### 2.8 Authentication Security

| Framework     | Mechanism                                                                 | Rating |
|--------------|---------------------------------------------------------------------------|--------|
| MythPHP      | Session + Token + JWT + API Key + OAuth + Basic + Digest; SHA-256 token hashing; session concurrency control; fingerprint binding (UA mode family/normalized/strict); auth debug logging | ⭐⭐⭐⭐ (4.0/5) |
| Native PHP   | Manual; `password_hash()` / `password_verify()` if used correctly         | ⭐½ (1.5/5) |
| Laravel      | Auth scaffolding; `bcrypt`/`argon2`; Sanctum/Passport; session invalidation; `Auth::logoutOtherDevices()` | ⭐⭐⭐⭐⭐ (5.0/5) |
| Yii2         | `IdentityInterface`; `passwordHash()` via `Security` component; RBAC; `loginRequired` | ⭐⭐⭐⭐½ (4.5/5) |
| CI3          | Session-based; `password_hash()` available; no built-in token/JWT        | ⭐⭐ (2.0/5) |
| CI4          | Shield library provides full auth; JWT planned; bcrypt/argon2             | ⭐⭐⭐½ (3.5/5) |
| CakePHP      | Authentication plugin; `DefaultPasswordHasher`; multi-step auth; API token auth | ⭐⭐⭐⭐ (4.0/5) |

---

### 2.9 File Upload Security

**Attack vectors:** Malicious file upload (web shell), MIME spoofing, path traversal, zip bomb/decompression bomb, SVG XSS, CSV injection, content-type header injection, double extension bypass (`file.php.jpg`).

| Framework     | Mechanism                                                                 | Rating |
|--------------|---------------------------------------------------------------------------|--------|
| MythPHP      | `Components\Files` + `Components\Security`: `finfo`-based MIME detection (not header trust); blocklist of dangerous extensions + blocked MIME types; `isSafeUploadFilename()` (null bytes, path traversal, Windows reserved names, XSS patterns, length guard); pixel-count bomb guard for images; base64 decoded-size estimate before write; streaming CSV/JSON/XML content scan; `is_uploaded_file()` source verification; random `bin2hex(random_bytes(16))` filenames; `ValidateUploadGuard` middleware policy | ⭐⭐⭐⭐½ (4.5/5) |
| Native PHP   | Manual `$_FILES['type']` check — trivially spoofed; developer must add all guards | ⭐ (1.0/5) |
| Laravel      | `Illuminate\Http\UploadedFile`: `getClientMimeType()` (header-only), `getMimeType()` (finfo), `validate('mimes:')` rule; `store()` with random names; Intervention Image for processing; no built-in content scan | ⭐⭐⭐⭐ (4.0/5) |
| Yii2         | `UploadedFile::getInstance()` with `FileValidator`; `mimeTypes` rule with finfo; `extensions` rule; `maxSize`; `minSize`; no content scanning; developer supplies safe filename | ⭐⭐⭐½ (3.5/5) |
| CI3          | `CI_Upload` class; `allowed_types` extension list; MIME validation optional; no finfo-based detection; no content scanning | ⭐⭐ (2.0/5) |
| CI4          | `UploadedFile`: `getMimeType()` via finfo; `isValid()`; `move()` with random names; `Rules::uploaded()` + `Rules::mime_in()`; no content scanning | ⭐⭐⭐ (3.0/5) |
| CakePHP      | `UploadedFileInterface`; `Validation::uploadedFile()`; finfo MIME detection; file size/extension rules; no built-in content scanning | ⭐⭐⭐ (3.0/5) |

**MythPHP Upload Security Pipeline:**

```
Browser/API → ValidateUploadGuard middleware (entity type/folder/MIME policy)
           → Files::upload() / uploadBase64Image()
           → assertValidUploadArray() — PHP error code, UPLOAD_ERR_INI_SIZE
           → assertUploadedFile()     — is_uploaded_file() source verification
           → size check (PHP ini vs configured max)
           → analyzeFile():
               ├─ canReadPath() + is_link() check (no symlink traversal)
               ├─ isSafeUploadFilename() — null bytes, traversal, XSS, Windows reserved, length
               ├─ filesize() — empty file guard + second max-size check
               ├─ detectMimeType() — finfo (primary) → mime_content_type() (fallback); NOT $_FILES['type']
               ├─ isBlockedUploadMimeType() — deny-list: text/html, application/x-php, etc.
               ├─ assertMimeAllowed()  — positive allow-list from setAllowedMimeTypes()
               ├─ extension from MIME (not from filename!) → isBlockedUploadExtension()
               ├─ assertValidImage()   — getimagesize() + dimension/pixel bomb guard (6000×6000 / 24M px)
               └─ inspectDocumentContent() — streaming line-by-line CSV/JSON/XML/text scan for injections
           → storeAnalyzedFile():
               ├─ reserveTargetPath() — bin2hex(random_bytes(16)) filename (no original name in storage)
               ├─ move_uploaded_file() (PHP-level atomicity) or rename/copy for non-PHP-upload sources
               └─ chmod(0644) on stored file
```

**MythPHP Notes:**
- ✅ MIME type is detected from file bytes (`finfo`), never trusted from `$_FILES['type']` or Content-Type header
- ✅ Extension derived from detected MIME, not from the original filename — double extension bypass blocked
- ✅ Random cryptographic filenames via `bin2hex(random_bytes(16))` — no original name in storage
- ✅ `is_uploaded_file()` prevents path injection via `tmp_name` forgery
- ✅ Symlink traversal blocked via `is_link()` check
- ✅ Pixel-count / decompression bomb guard on images (6000×6000 max, 24M pixel cap)
- ✅ Base64 upload size estimated from base64 length before decode — prevents memory exhaustion
- ✅ Streaming document content scanner (CSV, JSON, XML, text) checks for SQL injection, XSS, script tags, wrapper abuse
- ✅ `ValidateUploadGuard` middleware can enforce entity type, folder group, and base64 MIME allow-lists at route level
- ✅ GD extension re-encodes images through `imagecreatefromjpeg/png/gif/webp` — strips embedded EXIF payloads and PHP tags in image files
- ⚠️ `upload()` / `moveFile()` helpers set MIME allow-list to `'*'` for backward compatibility — rely on the deny-list (`isBlockedUploadMimeType()`) as the sole type gate; controllers should call `setAllowedMimeTypes()` explicitly for stricter control
- ⚠️ SVG uploads: `image/svg+xml` is not in the default allowed MIME map (correct — SVG can embed scripts); add explicitly only when sanitization is in place
- ⚠️ Content scanning (`validate_content`) is opt-in — not applied by default to non-image uploads

---

### 2.10 Routing Safety & Middleware Permission

**Attack surface:** Unauthorized access via route enumeration, parameter type confusion (e.g. `/users/../../admin`), missing permission gates, privilege escalation through unprotected routes.

| Framework     | Mechanism                                                                 | Rating |
|--------------|---------------------------------------------------------------------------|--------|
| MythPHP      | Fluent per-route authorization: `->permission()`, `->can()`, `->permissionAny()`, `->canAny()`, `->role()`, `->ability()`, `->auth()`, `->webAuth()`, `->apiAuth()`, `->guestOnly()`, `->featureFlag()`; `->where('param', 'regex')` type-enforcing parameter constraints; `ValidateRequestSafety` middleware on all state-changing methods; full RBAC middleware stack | ⭐⭐⭐⭐ (4.0/5) |
| Native PHP   | No routing layer — URL dispatch fully manual; parameter types unchecked | ⭐ (1.0/5) |
| Laravel      | Route middleware groups; `Route::can()`; route model binding with implicit ownership; `Route::prefix()`+`->middleware()` group inheritance; signed URLs; `auth` / `throttle` / `verified` first-class guards | ⭐⭐⭐⭐⭐ (5.0/5) |
| Yii2         | `AccessControl` behavior on controllers; `checkAccess()` in RBAC; URL filters; controller-level `beforeAction()` hooks | ⭐⭐⭐⭐ (4.0/5) |
| CI3          | Basic router with no middleware/filter concept; manual `$this->session` checks; param types fully developer-managed | ⭐½ (1.5/5) |
| CI4          | Filter system (`before`/`after`); Shield filters for auth/permission; route groups with shared filters; no implicit ownership check | ⭐⭐⭐½ (3.5/5) |
| CakePHP      | `AuthorizationMiddleware` + policy resolution; `$this->Authorization->authorize()`; route constraints; scoped routes | ⭐⭐⭐⭐ (4.0/5) |

**MythPHP Route Safety Examples:**

```php
// app/routes/web.php

// Auth guard
Route::get('/dashboard', [DashboardController::class, 'index'])
    ->webAuth()
    ->name('dashboard');

// Single permission gate
Route::post('/users/{id}/edit', [UserController::class, 'update'])
    ->webAuth()
    ->permission('user-edit')
    ->where('id', '[0-9]+');   // ← type-constraint: id must be numeric

// Any of multiple permissions
Route::delete('/posts/{id}', [PostController::class, 'destroy'])
    ->webAuth()
    ->permissionAny(['post-delete', 'super-admin'])
    ->where('id', '[0-9]+');

// Role-based
Route::get('/admin', [AdminController::class, 'index'])
    ->webAuth()
    ->role(['admin', 'manager']);

// Ability (granular RBAC)
Route::put('/settings', [SettingsController::class, 'update'])
    ->webAuth()
    ->ability(['settings-write']);

// Feature flag (gradual rollout)
Route::get('/beta-feature', [BetaController::class, 'index'])
    ->webAuth()
    ->featureFlag('beta-dashboard');

// API route group with shared permission
Route::group(['prefix' => 'api/v1/reports', 'middleware' => ['auth.api', 'permission:reports-view']], function () {
    Route::get('/', [ReportController::class, 'index']);
    Route::get('/{id}', [ReportController::class, 'show'])->where('id', '[0-9]+');
});
```

**FormRequest `authorize()` for Record-Level Ownership:**

```php
// app/http/requests/UpdatePostRequest.php
public function authorize(): bool
{
    $postId = $this->input('id');
    $post = db()->table('posts')->where('id', $postId)->fetch();

    // Block if post doesn't exist OR belongs to another user
    if (empty($post) || $post['user_id'] !== auth()->id()) {
        return false;  // → 403 ValidationException
    }

    return true;
}
```

**MythPHP Notes:**
- ✅ All route-level authorization methods are fluent, chainable on `RouteDefinition`
- ✅ `->where('id', '[0-9]+')` prevents path traversal / type confusion at routing layer (before controller)
- ✅ Auth middleware types: `auth` (generic), `auth.web` (session), `auth.api` (token/API key), `guest` (redirect if logged in)
- ✅ Feature flags integrate at the route level — no code changes needed to enable/disable features per user group
- ✅ FormRequest `authorize()` runs before rules validation, giving early 403 without touching DB unnecessarily
- ⚠️ No automatic route-model binding with ownership assertion (Laravel-style `findOrFail` + policy check in route resolution)
- ⚠️ Route parameter constraints (`where`) must be added manually — no default `[0-9]+` constraint on `{id}` patterns

---

## 3. Database Performance Analysis (1M–2M Records)

### Test Setup Assumptions

- **Database:** MySQL/MariaDB with InnoDB
- **Main table:** `users` — 1,000,000 rows
- **Related tables (6):** `profiles`, `roles`, `permissions`, `addresses`, `orders`, `logs` — 100K–2M rows each
- **Indexes:** Primary key + foreign key indexes assumed
- **PHP memory limit:** 256MB
- **Server:** Typical shared/VPS environment (4 CPU, 8GB RAM)

> **Note:** Numbers below are realistic theoretical estimates derived from implementation analysis and known algorithmic behavior (OFFSET degradation curves, keyset vs. offset cost models, eager-load batch query counts). They are not from live benchmark runs on actual hardware. Actual numbers will vary with hardware, index health, MySQL configuration, and network latency.

---

### 3.1 Pagination Performance

#### Problem with OFFSET at Scale

Standard `LIMIT x OFFSET y` degrades as OFFSET grows — MySQL still scans and discards `y` rows, making deep pagination O(n).

```
EXPLAIN SELECT * FROM users LIMIT 20 OFFSET 999980;
-- MySQL scans 1,000,000 rows, discards 999,980
-- Time: ~3–8 seconds at 1M rows with no tuning
```

#### Keyset (Cursor-based) Pagination

```
SELECT * FROM users WHERE id > :last_seen_id ORDER BY id LIMIT 20;
-- MySQL seeks to last_seen_id via B-tree index
-- Time: ~0.01–0.05 seconds regardless of page depth
```

| Framework    | Mechanism                          | Page 1 Est. | Page 50,000 Est. | Memory Est. | Notes |
|-------------|-------------------------------------|-------------|-----------------|-------------|-------|
| **MythPHP** | `paginate()` (OFFSET); `cursorPaginate()` (keyset, for deep pagination); auto-delegates to `chunkById()`/`lazyById()` for keyset-eligible streaming | ~0.05s | ~4–7s (OFFSET `paginate()`) / ~0.05s (keyset `cursorPaginate()`) | 15–30MB | **`cursorPaginate()` now built-in**: returns `data`, `next_cursor`, `prev_cursor`, `has_more` |
| Native PHP  | Manual `LIMIT/OFFSET`              | ~0.05s | ~4–8s | 30–60MB | No pagination class |
| Laravel     | `paginate()` (OFFSET), `cursorPaginate()` (keyset), `simplePaginate()` | ~0.05s | ~4–7s / ~0.03s (cursor) | 10–25MB | `cursorPaginate()` is production-grade keyset |
| Yii2        | `Pagination` class (OFFSET-based)  | ~0.05s | ~3–6s | 15–30MB | No native keyset pagination |
| CI3         | `Pagination` class (OFFSET)        | ~0.06s | ~5–10s | 25–50MB | Very basic; no keyset |
| CI4         | `Pager` class (OFFSET)            | ~0.05s | ~4–8s | 20–40MB | No native keyset pagination |
| CakePHP     | `Paginator` component (OFFSET); cursor with 3rd-party | ~0.05s | ~3–6s | 15–35MB | Plugin for keyset available |

**MythPHP Rating (Pagination):** ⭐⭐⭐⭐ (4.0/5)

**✅ IMPLEMENTED:** `cursorPaginate(int $perPage, string $column, ?string $cursorToken)` added to `HasStreaming`. Uses `WHERE id > :after ORDER BY id ASC LIMIT n+1` (forward) or `WHERE id < :before ORDER BY id DESC LIMIT n+1` (backward). Returns base64url-encoded cursor tokens for stateless deep pagination.

---

### 3.2 Export / Report Generation

Exporting 100K–1M rows to CSV/Excel/PDF — the critical factor is memory stability.

| Framework    | Mechanism                          | 100K rows Mem. | 1M rows Mem. | Time (1M) | Notes |
|-------------|-------------------------------------|----------------|--------------|-----------|-------|
| **MythPHP** | `cursor(300)` generator; `chunk(1000)` with `gc_collect_cycles()`; `lazy(200)`; keyset streaming on eligible queries; **`exportCsv()` built-in CSV streaming export** | ~8–15MB | ~12–25MB | ~15–45s | GC hint on chunks ≥500; auto-selects `chunkById()` (keyset) vs `chunk()` (offset); UTF-8 BOM for Excel; sanitized filename |
| Native PHP  | Manual chunked `while` loop with `PDOStatement::fetchAll()` | ~50–100MB | OOM risk | ~20–60s | Developer must implement chunking |
| Laravel     | `cursor()` with `LazyCollection`; `chunk()`; queue-based export (Laravel Excel) | ~5–10MB | ~8–15MB | ~10–30s | `LazyCollection` is generator-based; queue export is async |
| Yii2        | `BatchQueryResult` generator (default 100 rows/batch) | ~10–20MB | ~15–30MB | ~20–50s | Generator-based; efficient on memory |
| CI3         | No built-in; raw `PDO::query()` with manual loop | ~80–150MB | OOM likely | ~30–80s | Requires entirely custom implementation |
| CI4         | `db()->query()` with manual chunking or cursor; no native large-export util | ~40–80MB | ~60–120MB | ~25–60s | Some improvement over CI3 |
| CakePHP     | `ResultSet` iterator; custom chunked queries; 3rd-party excel lib needed | ~15–30MB | ~20–40MB | ~15–40s | Iterator-based fetch; no native large-export built-in |

**MythPHP Rating (Export/Report):** ⭐⭐⭐⭐ (4.0/5)

**✅ IMPLEMENTED:** `exportCsv(string $filename, array $columns = [], int $chunkSize = 500)` added to `HasStreaming`. Streams directly to `php://output` via `fputcsv()`; sends `Content-Disposition: attachment` headers; auto-selects keyset chunking when eligible. Usage: `db()->table('users')->where('status', 1)->exportCsv('users.csv', ['id', 'name', 'email']);`

---

### 3.3 N+1 Query Problem Handling

**Problem:** Loading a list of 1000 users and then querying 6 related tables per user = **1 + (1000 × 6) = 6,001 queries**.

**Solution:** Eager loading resolves all relations with **1 + 6 = 7 queries** (one `IN` clause per relation).

#### Query Count Comparison (1000 parent rows × 6 relations)

| Framework    | Without Eager Load | With Eager Load | Detection/Warning | Mechanism |
|-------------|-------------------|-----------------|-------------------|-----------|
| **MythPHP** | 6,001 queries | 7 queries (or chunked if >threshold) | **Warning log when same SELECT pattern fires ≥30 times** (auto-enabled when `APP_DEBUG=true`; adjustable via `PerformanceMonitor::setN1WarnThreshold()`) | `with()`, `withOne()`, `withCount()`, `withSum()`, `withAvg()`, `withMin()`, `withMax()`; `EagerLoadOptimizer` adaptive batch sizing |
| Native PHP  | 6,001 queries | Manual JOIN or sub-loop | None | No framework support |
| Laravel     | 6,001 queries | 7 queries | `preventLazyLoading()` throws exception in dev | `with()`, `load()`, `withCount()`; lazy eager load after fetch |
| Yii2        | 6,001 queries | 7 queries | No N+1 detection | `with()`/`joinWith()` in ActiveQuery |
| CI3         | 6,001 queries | Manual JOINs only | None | No ORM; manual query per relation |
| CI4         | 6,001 queries | Manual only | None | No eager-load ORM; `Model::findAll()` has no relation support |
| CakePHP     | 6,001 queries | 7 queries | No N+1 detection | `contain()` in ORM |

#### MythPHP Eager Load Adaptive Batching

When `EagerLoadOptimizer::shouldUseBatching(count($primaryKeys))` is true (large result sets), MythPHP uses:
- **Adaptive chunk sizing** based on performance history (min 100, max 2000, default 1000)
- **APCu cross-request history** to tune chunk size across PHP workers
- **Per-chunk profiling** with `PerformanceMonitor`
- **Integer raw IN optimization** to skip parameterization overhead for int PK arrays

```
1000 parents × 6 relations = 7 queries (no batching needed, <2000 keys)
100,000 parents × 6 relations = ≥ (ceil(100,000/1000) × 6) + 1 = 601 queries (chunked IN)
```

**MythPHP Rating (N+1):** ⭐⭐⭐⭐½ (4.5/5)

**✅ IMPLEMENTED:** `PerformanceMonitor` N+1 detector added. Tracks SELECT query fingerprints (md5 of normalized SQL) per request. Warns via `logger()->log_warning()` when same pattern fires ≥30 times. Auto-enabled when `APP_DEBUG=true` (via `Database::__construct()`). Call `PerformanceMonitor::getN1Suspects()` to get a sorted list of suspect patterns. Use `PerformanceMonitor::setN1WarnThreshold(int)` to tune sensitivity.

---

### 3.4 Memory & Time Benchmarks (Theoretical Estimates)

> These numbers are derived from algorithm analysis, not live hardware runs. Use `php myth db:benchmark` or the benchmark script for actual measurements.

#### Scenario A: Paginate 1M users — Page 1 vs Deep Page (OFFSET vs Keyset)

| Framework    | Page 1 Time | Page 1 Mem | Deep Page (p.50000) Time | Deep Page Mem |
|-------------|-------------|------------|--------------------------|---------------|
| MythPHP (OFFSET paginate) | ~0.05s | ~8MB | ~5–8s | ~20–30MB |
| MythPHP (keyset chunk) | ~0.03s | ~5MB | ~0.03s | ~5MB |
| Laravel cursorPaginate | ~0.03s | ~5MB | ~0.03s | ~5MB |
| Laravel paginate | ~0.05s | ~8MB | ~5–8s | ~20–30MB |
| Yii2 paginate | ~0.05s | ~10MB | ~5–9s | ~25–35MB |
| CI3 paginate | ~0.06s | ~12MB | ~7–12s | ~30–50MB |
| CI4 paginate | ~0.05s | ~10MB | ~6–10s | ~25–40MB |
| CakePHP paginate | ~0.05s | ~10MB | ~5–8s | ~20–35MB |

#### Scenario B: Export 1M rows (streaming, no relation)

| Framework    | Time Estimate | Peak Memory | GC Support |
|-------------|--------------|-------------|------------|
| MythPHP (cursor) | ~20–40s | ~12–20MB | ✅ gc hint at ≥1000 rows/chunk |
| MythPHP (chunk) | ~25–50s | ~15–25MB | ✅ explicit gc_collect_cycles |
| Laravel (cursor + LazyCollection) | ~15–30s | ~8–15MB | ✅ generator-based |
| Yii2 (BatchQueryResult) | ~20–45s | ~12–25MB | ✅ generator-based |
| CI3 (manual) | ~35–80s | ~80–200MB | ❌ manual only |
| CI4 (manual chunk) | ~30–60s | ~50–120MB | ❌ manual only |
| CakePHP (iterator) | ~20–45s | ~15–35MB | ✅ iterator-based |

#### Scenario C: Load 10,000 users with 6 eager-loaded relations

| Framework    | Query Count | Time Estimate | Peak Memory |
|-------------|-------------|--------------|-------------|
| MythPHP (no eager) | 60,001 | ~60–120s | ~200–400MB |
| MythPHP (with eager, adaptive batch) | 7–61* | ~2–8s | ~40–80MB |
| Laravel (no eager) | 60,001 | ~60–120s | ~200–400MB |
| Laravel (with eager) | 7 | ~1–4s | ~30–60MB |
| Yii2 (with eager) | 7 | ~1–4s | ~35–65MB |
| CI3 (no eager, manual JOIN) | 1 (JOIN) | ~5–15s | ~80–150MB |
| CI4 (no eager, manual) | 60,001 | ~60–120s | ~200–400MB |
| CakePHP (contain) | 7 | ~1–5s | ~35–70MB |

\* *MythPHP chunked: for 10K parents (>threshold), relations are loaded in adaptive chunks (e.g., 10 chunks of 1000 → 1 + 10×6 = 61 queries)*

---

## 4. Aggregate Scorecard

### Security Scorecard (out of 5.0)

| Category                   | MythPHP | Native PHP | Laravel | Yii2 | CI3 | CI4 | CakePHP |
|---------------------------|---------|-----------|---------|------|-----|-----|---------|
| SQL Injection              | 4.0     | 2.0       | 5.0     | 5.0  | 3.0 | 4.0 | 4.5     |
| XSS Protection             | 4.0     | 1.5       | 5.0     | 4.5  | 2.5 | 3.5 | 4.0     |
| Null Byte Attacks          | **4.5**     | 1.5       | 4.0     | 3.5  | 3.0 | 3.5 | 3.5     |
| IDOR Protection            | **4.0**     | 1.0       | 5.0     | 4.5  | 1.5 | 3.5 | 4.5     |
| CSRF Protection            | 4.0     | 1.0       | 5.0     | 4.5  | 3.0 | 4.0 | 4.5     |
| Security Headers           | 4.5     | 1.0       | 3.0     | 3.0  | 1.5 | 2.0 | 3.5     |
| Rate Limiting / Brute Force| 4.0     | 1.0       | 5.0     | 4.0  | 1.0 | 3.0 | 2.5     |
| Auth Security              | 4.0     | 1.5       | 5.0     | 4.5  | 2.0 | 3.5 | 4.0     |
| File Upload Security       | 4.5     | 1.0       | 4.0     | 3.5  | 2.0 | 3.0 | 3.0     |
| Routing Safety             | 4.0     | 1.0       | 5.0     | 4.0  | 1.5 | 3.5 | 4.0     |
| **Security Average**       | **4.2** | **1.3**   | **4.6** | **4.1** | **2.1** | **3.4** | **3.8** |

### Performance Scorecard (out of 5.0)

| Category              | MythPHP | Native PHP | Laravel | Yii2 | CI3 | CI4 | CakePHP |
|----------------------|---------|-----------|---------|------|-----|-----|---------|
| Pagination (1M+ rows)| **4.0**     | 2.0       | 5.0     | 3.5  | 2.0 | 2.5 | 3.5     |
| Export / Report      | **4.0**     | 1.5       | 5.0     | 4.0  | 1.5 | 2.5 | 3.0     |
| N+1 Prevention       | **4.5**     | 1.0       | 5.0     | 4.5  | 1.5 | 2.0 | 4.5     |
| Memory Management    | 4.0     | 1.5       | 5.0     | 4.0  | 1.5 | 2.5 | 3.5     |
| Connection Pooling   | 4.0     | 2.0       | 4.0     | 4.0  | 2.0 | 3.0 | 3.5     |
| **Performance Average** | **4.1** | **1.6** | **4.8** | **4.0** | **1.7** | **2.5** | **3.6** |

### Overall Score

| Framework      | Security | Performance | **Overall** |
|---------------|----------|-------------|------------|
| **MythPHP**   | 4.2      | 4.1         | **4.2**    |
| Native PHP    | 1.3      | 1.6         | **1.4**    |
| **Laravel**   | 4.6      | 4.8         | **4.7**    |
| Yii2          | 4.1      | 4.0         | **4.1**    |
| CI3           | 2.1      | 1.7         | **1.9**    |
| CI4           | 3.4      | 2.5         | **2.9**    |
| CakePHP       | 3.8      | 3.6         | **3.7**    |

---

## 5. MythPHP Strengths & Weaknesses Summary

### Strengths

| Area | Detail |
|------|--------|
| **Security Headers** | Best-in-class for a custom framework — out-of-the-box HSTS, CSP, COOP, CORP, Permissions-Policy without 3rd-party packages |
| **Multi-Auth** | Session + Token + JWT + API Key + OAuth + Basic + Digest — more auth methods than most frameworks ship by default |
| **Rate Limiting** | 6-scope rate limiter + aggressive throttle + login attempt policy with lockout and auto-ban |
| **Eager Loading** | Adaptive batch sizing with APCu-backed cross-request history; memory-stable for large parent sets |
| **Streaming** | `cursor()`, `chunk()`, `lazy()`, keyset streaming with auto-delegation and GC hinting |
| **Request Hardening** | `ValidateRequestSafety` middleware covers URI length, header count, multipart parts, JSON field limit |
| **IDOR Tooling** | Encoded ID helpers and RBAC middleware reduce enumeration risk; fluent route-level `->permission()`, `->role()`, `->ability()` on every `RouteDefinition`; `->where()` param constraints enforce type safety before controller |
| **Routing Safety** | Fluent per-route authorization API with 9 chainable methods; parameter regex constraints; feature-flag gating at route level |
| **File Upload Security** | `finfo`-based MIME detection; extension derived from MIME not filename; random filenames; pixel bomb guard; streaming CSV/JSON/XML content scan; `ValidateUploadGuard` middleware |

### Weaknesses / Gaps

| Area | Gap |
|------|-----|
| **IDOR** | No automatic route-model binding with ownership assertion (Laravel-style) |
| ~~**Deep Pagination**~~ | ~~`paginate()` uses OFFSET — degrades at scale; no `cursorPaginate()` for UI pages~~ **RESOLVED:** `cursorPaginate()` added to `HasStreaming` |
| ~~**N+1 Detection**~~ | ~~No debug-mode N+1 detector or warning system~~ **RESOLVED:** `PerformanceMonitor` N+1 fingerprint tracker added; auto-enabled on `APP_DEBUG=true` |
| **XSS (GET)** | XSS middleware only runs on state-changing methods; GET query param scanning absent |
| ~~**Null Bytes (Global)**~~ | ~~No global input null byte stripping — only path/filename scope covered~~ **RESOLVED:** `Request::capture()` strips null bytes from GET, POST, JSON body, and PUT/PATCH/DELETE inputs |
| ~~**Export**~~ | ~~No built-in CSV/Excel/PDF export utility; streaming helpers exist but need wiring~~ **RESOLVED:** `exportCsv()` added to `HasStreaming` |
| ~~**CSP inline scripts**~~ | ~~Default CSP config includes `'unsafe-inline'` for CDN compatibility — weakens XSS defense~~ **RESOLVED:** `nonce_enabled: true` in `security.php` enables per-request CSP nonces, removes `unsafe-inline` |
| **Cache Drivers** | File + Array only — no Redis/Memcached (affects rate limiter state and session concurrency under load) |
| **View Auto-Escaping** | `{{ $var }}` compiles to `htmlspecialchars((string)($var), ENT_QUOTES, 'UTF-8')` — verified safe. `{!! $var !!}` intentionally bypasses escaping (raw output); developer must ensure only trusted/pre-sanitized values are passed to `{!! !!}` |

---

## 6. Improvement Recommendations for MythPHP

### Priority 1 — High Impact Security

#### 6.1 ~~Add Global Null Byte Stripping in Request Layer~~ ✅ IMPLEMENTED

`Request::capture()` now calls `stripNullBytes()` (via `array_walk_recursive`) on all input vectors:

```php
// systems/Core/Http/Request.php
private static function stripNullBytes(array $input): array
{
    array_walk_recursive($input, static function (&$value): void {
        if (is_string($value)) {
            $value = str_replace("\x00", '', $value);
        }
    });
    return $input;
}

// Applied to: $_GET, $_POST, JSON-decoded body, PUT/PATCH/DELETE form body
```

#### 6.2 ~~Add `cursorPaginate()` for Deep Pagination~~ ✅ IMPLEMENTED

`cursorPaginate()` is now a chainable method on the query builder (`HasStreaming` trait):

```php
// Keyset pagination — O(1) at any depth
$result = db()->table('users')
    ->where('status', 1)
    ->cursorPaginate(20, 'id', request()->input('cursor'));

// Returns:
// [
//   'data'        => [...],   // page rows
//   'per_page'    => 20,
//   'has_more'    => true,
//   'next_cursor' => 'eyJhZnRlciI6MTAwMH0',  // base64url encoded
//   'prev_cursor' => 'eyJiZWZvcmUiOjk4MX0',
// ]
```

#### 6.3 ~~Enforce `'unsafe-inline'` Removal from Default CSP~~ ✅ IMPLEMENTED

Per-request CSP nonces are now supported via `Core\Security\CspNonce`:

```php
// app/config/security.php
'csp' => [
    'enabled'       => true,
    'nonce_enabled' => true,  // ← set true to activate; removes 'unsafe-inline' automatically
    'script-src'    => ["'self'", "'unsafe-inline'", ...],  // 'unsafe-inline' stripped when nonce_enabled
    ...
],

// Blade templates
<script @nonce src="app.js"></script>         {{-- outputs: nonce="{value}" attribute --}}
<script nonce="{{ $csp_nonce }}">...</script>  {{-- $csp_nonce available in all views --}}
```

#### 6.4 Add FormRequest `authorize()` Ownership Pattern

*(Pending — code pattern not yet scaffolded)*

Document and scaffold a standard ownership-check pattern to prevent IDOR:

```php
// In SaveUserRequest::authorize()
public function authorize(): bool
{
    $userId = $this->route('id');
    if (!$userId) {
        return true; // create flow
    }
    // Ownership: only the record owner or admin can edit
    return (int) $userId === (int) authId()
        || auth()->user()['role_id'] === 1;
}
```

Add a `php myth make:request --with-ownership` generator flag.

---

### Priority 2 — Performance Improvements

#### 6.5 ~~N+1 Query Detector (Debug Mode)~~ ✅ IMPLEMENTED

`PerformanceMonitor` N+1 fingerprint tracking is now built in:

```php
// Auto-enabled when APP_DEBUG=true (set in Database::__construct)
// Also enabled when profiling is on: $db->setProfilingEnabled(true)

// Tune sensitivity (default: 30 repeats triggers warning)
PerformanceMonitor::setN1WarnThreshold(20);

// Inspect suspects at end of request (e.g., in debug bar)
$suspects = PerformanceMonitor::getN1Suspects();
// Returns: [['sql' => 'SELECT ...', 'count' => 45], ...]

// Warning logged automatically:
// [N+1 DETECTED] Query pattern executed 30 times in one request. SQL: SELECT * FROM orders WHERE user_id = ?...
```

#### 6.6 ~~Native CSV Streaming Export Helper~~ ✅ IMPLEMENTED

`exportCsv()` is now a chainable query builder method:

```php
// Stream 1M rows directly to browser download — no memory spike
db()->table('users')
    ->where('status', 1)
    ->exportCsv(
        filename: 'active-users.csv',
        columns: ['id', 'name', 'email', 'created_at'],
        chunkSize: 500  // rows per DB round-trip
    );
// Automatically uses chunkById() (keyset) when eligible, chunk() (offset) otherwise.
// Sends Content-Type, Content-Disposition, UTF-8 BOM headers.
// Sanitizes filename: /[^A-Za-z0-9_\.\-]/ → '_'
```

#### 6.7 Redis/Memcached Cache Driver

The rate limiter, session concurrency tracker, and eager-load performance history all use the file cache driver. Under concurrent load with multiple PHP workers:

- File locking becomes a bottleneck
- APCu-only for cross-worker state is per-server (not scalable across nodes)
- Session concurrency enforcement can race

**Recommendation:** Add a Redis cache driver (`storage/cache/redis`) using the native `redis` extension or `predis/predis`:

```php
// app/config/cache.php
$config['cache']['stores']['redis'] = [
    'driver' => 'redis',
    'host' => env('REDIS_HOST', '127.0.0.1'),
    'port' => env('REDIS_PORT', 6379),
    'password' => env('REDIS_PASSWORD'),
    'database' => env('REDIS_DB', 0),
];
```

#### 6.8 Query Result Caching Layer

For read-heavy paginated lists, add a transparent query result cache:

```php
// Proposed API
$users = db()->table('users')
    ->where('status', 1)
    ->remember(300, 'active-users-page-1')  // cache 5 minutes
    ->paginate(20);
```

Cache key should include query fingerprint + bindings to avoid stale data on `where` variations.

---

### Priority 3 — Developer Experience & Hardening

#### 6.9 Add XSS Scanning for GET Parameters

Extend `XssProtection` middleware to scan GET parameters for reflected XSS attacks:

```php
// XssProtectionTrait — add GET scanning option
public function isXssAttack($ignoreXss = null, bool $scanGet = false): bool
{
    if ($scanGet) {
        foreach ($_GET as $key => $value) {
            if ($this->security->containsXss($value)) {
                $this->logXssAttempt("GET param: {$key}");
                return true;
            }
        }
    }
    // existing POST/body check...
}
```

Enable via middleware parameter: `->middleware('xss:scan_get')`.

#### 6.10 Automatic IDOR Encoded ID Enforcement

Create a `SecureId` trait for controllers that automatically validates encoded IDs and checks resource ownership before proceeding:

```php
trait SecureResourceAccess
{
    protected function authorizeResource(string $table, $encodedId, callable $ownershipCheck = null): array
    {
        $record = $this->findOrFail($table, $encodedId); // uses restoreByEncodedId internally
        
        if ($ownershipCheck && !$ownershipCheck($record)) {
            abort(403, 'Forbidden');
        }
        
        return $record;
    }
}
```

#### 6.11 Security Audit: Add IDOR Check to `security:audit` Command

Extend `php myth security:audit` to scan route definitions for resource routes that lack an authorization middleware:

```
[WARN] Route GET /users/{id} has no permission/role/ability middleware.
       Consider adding ->middleware('permission:user-view') or FormRequest authorize().
```

#### 6.12 Benchmark Command with Large Dataset

Create `php myth db:perf-test` that spins up in-memory fixtures and measures:
- OFFSET pagination degradation curve
- Keyset vs OFFSET comparison
- Eager load query counts vs manual N+1
- Chunk/cursor memory delta

Output should include peak memory, wall time, and query count per scenario to give developers data to justify index investments.

---

#### 6.13 Default Route Parameter Type Constraints

Currently `->where()` is optional and must be added per-route. A small convenience improvement would be registering default patterns for well-known parameter names so routes automatically get type safety without extra boilerplate.

```php
// Proposed: in Router or RouteCollection bootstrap
Router::pattern('id',   '[0-9]+');
Router::pattern('uuid', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}');
Router::pattern('slug', '[a-z0-9\-]+');

// Then any route with {id}, {uuid}, or {slug} parameter inherits the constraint
// without the developer needing to add ->where('id', '[0-9]+') every time.
```

**Impact:** Eliminates an entire class of type-confusion bugs (e.g. `/users/../../config`) at zero runtime cost (constraint checked in routing layer before controller is instantiated).

---

#### 6.14 FormRequest Mass-Assignment Protection Documentation

MythPHP already has **two independent mass-assignment guards** that most comparisons miss:

1. **FormRequest whitelist**: After validation, `validateResolved()` drops every field not declared in `rules()` — only explicitly listed keys survive into `validatedData`.
2. **DB-layer column guard**: `sanitizeColumn()` fetches `SHOW COLUMNS` at insert/update time and silently drops any key whose column does not exist in the actual table.

**Recommendation:** Add explicit documentation for controllers that bypass FormRequest (direct `$request->all()` + `db()->insert()`) so developers know `sanitizeColumn()` is still protecting them, and add a warning in code review guidelines not to pass `$request->all()` through `validated()` data bypasses.

```php
// Safe — FormRequest whitelist applied before DB column guard
$data = $request->validated();              // only rule-declared keys
db()->table('users')->insert($data);        // sanitizeColumn() also strips non-columns

// Still safe — DB column guard fires even without FormRequest
db()->table('users')->insert($request->all()); // sanitizeColumn() drops unknown keys

// Risky — bypasses both guards (only use after explicit filtering)
db()->query("INSERT INTO users ...") ->execute();
```

---

#### 6.15 Automatic `->where()` Constraint Suggestion in Security Audit

Extend `php myth security:audit` to inspect registered routes and flag any route with a `{param}` placeholder that:
- Does not have a `->where()` constraint
- Has a suffix that looks like an identifier (`id`, `_id`, `userId`, etc.)

```
[WARN] Route GET /users/{id}    — no type constraint on {id}. Add ->where('id','[0-9]+').
[WARN] Route PUT /posts/{postId} — no type constraint on {postId}.
[OK]   Route GET /orders/{id}   — constrained to [0-9]+
```

**Impact:** Automated detection of missing parameter constraints across the entire app in one command, without manual route audit.

---

#### 6.16 `rules()` → DB Column Mapping Validator (DX)

A development-time helper that compares the keys declared in a FormRequest's `rules()` against the actual table columns, warning when a rule references a column that does not exist (typo detection) or when a table column is missing from `rules()` (potential unintentional exposure).

```php
// Proposed: php myth validate:formrequest App\\Http\\Requests\\SaveUserRequest --table=users
// Output:
// [OK]   name         → users.name
// [OK]   email        → users.email
// [WARN] pasword      → not found in users (typo? expected: password)
// [INFO] users.api_key → not in rules() (intentionally excluded — OK if guarded)
```

**Impact:** Catches rename-drift (column renamed in DB but not in FormRequest) before it causes data loss or silent insert failures.

---

#### 6.17 Template Raw Output Audit (`{!! !!}` Scanner)

The BladeEngine `{{ $var }}` is safe — it compiles to `htmlspecialchars((string)($var), ENT_QUOTES, 'UTF-8')`. The risk is `{!! $var !!}`, which emits raw unescaped output. There is no tooling today to locate every `{!! !!}` usage across templates.

**Recommendation:** Add a `php myth security:audit --views` scan that:
1. Finds all `*.php` files under `app/views/`
2. Extracts every `{!! ... !!}` expression
3. Classifies: safe (e.g. `{!! csrf_field() !!}`, `{!! $html_from_cms !!}`) vs risky (any `{!! $request_input !!}` or `{!! $user->... !!}`)

```
[WARN] views/users/edit.php:42  {!! $user['bio'] !!}  — raw user-supplied field
[OK]   views/_templates/form.php:8  {!! csrf_field() !!}  — framework helper, safe
[WARN] views/dashboard/index.php:17  {!! $title !!}  — variable origin unknown
```

**Impact:** Makes it impossible to accidentally ship an XSS via `{!! !!}` without it appearing in the audit output.

---

#### 6.18 Strict-Mode FormRequest Bypass Detection

Controllers can accidentally bypass FormRequest validation by reading `$request->all()` or `$request->input()` directly instead of `$request->validated()`, discarding all sanitization, casting, and whitelist protection.

**Recommendation:** Add a `php myth security:audit --requests` scanner that inspects controller files and flags calls to `$request->all()` / `$request->input()` that are passed directly to `db()->insert()`, `db()->update()`, or similar write operations without going through `->validated()`.

```
[WARN] app/http/controllers/UserController.php:78
       db()->table('users')->insert($request->all())
       → $request->all() bypasses FormRequest whitelist.
         Use $request->validated() or explicit key filtering.

[OK]   app/http/controllers/PostController.php:55
       db()->table('posts')->insert($request->validated())
```

**Note:** `db()->insert($request->all())` is still protected by `sanitizeColumn()` at the DB layer, but the FormRequest type casts, defaults, aliases, and computed fields are all skipped — a potential source of type confusion bugs as well as security gaps.

---

#### 6.19 Response Content-Type Enforcement

Currently, JSON API responses rely on the controller explicitly returning `response()->json()`. If a controller accidentally returns a plain string or array without the JSON response wrapper, the browser receives `text/html` for API endpoints — which can enable MIME sniffing attacks on older browsers and breaks the Content-Security-Policy boundary between web and API.

**Recommendation:** Add a `ResponseType` middleware for API route groups that:
- Forces `Content-Type: application/json` on all responses under `api/*` routes
- Strips any accidentally-set `text/html` content type
- Optionally adds `X-Content-Type-Options: nosniff` if not already set by `SetSecurityHeaders`

```php
// app/routes/api.php
Route::group(['prefix' => 'api/v1', 'middleware' => ['auth.api', 'response.json']], function () {
    // all routes in this group will always return application/json
});
```

**Impact:** Prevents MIME-type confusion on API endpoints; consistent Content-Type means CSP `connect-src` rules apply correctly.

--- Comparison Reference Notes

### Why Laravel scores 4.8–5.0

Laravel ships with 10+ years of security iteration, an enormous ecosystem (`spatie/*`, Sanctum, Passport, Telescope for N+1 detection), and first-class tooling for all the identified attack vectors. Its main gap is security headers (requires 3rd-party package).

### Why MythPHP scores 4.2 overall

MythPHP punches significantly above its weight as a custom framework. Security headers, multi-auth, adaptive eager loading, and streaming helpers match or exceed comparable major frameworks. After the improvements implemented in this report cycle — `cursorPaginate()`, `exportCsv()`, global null byte stripping, CSP nonce support, and the N+1 detector — plus the corrections in this update (FormRequest whitelist = `$fillable` equivalent, `sanitizeColumn()` = DB-layer mass-assignment guard, fluent route authorization API), the remaining gaps are: default route parameter constraints, Redis cache backend, GET-parameter XSS scanning, and automatic IDOR route-model binding.

### Why CI3 scores 2.0

CI3 is legacy software — its design predates modern security expectations. No ORM, no eager loading, no built-in rate limiting, no security header middleware, no JWT/token auth. It should not be chosen for new greenfield projects with large-scale data or security requirements.

### Native PHP at 1.5

Raw PHP without a framework provides all the building blocks but no defaults. Every security control requires explicit developer implementation. The ceiling is high (you can build anything), but the floor is very low (every developer must know to implement each control).

---

*Report prepared from MythPHP source analysis — `systems/`, `app/http/middleware/`, `docs/framework_knowledge/`. Live benchmark numbers should be collected with `tools/performance/database_benchmark.php` and compared against framework-specific benchmark suites on identical hardware.*
