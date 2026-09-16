# 04 — Subsystem Reference

**Verified:** 2026-08-25

One section per subsystem: what it does, the entry points you actually call, and what it
cannot do. Findings referenced as `X-nn` are detailed in [10-audit-findings.md](10-audit-findings.md).

---

## Database

**Entry:** `db($connection = 'default')` → `App\Support\DatabaseRuntime::connection()` →
a `MySQLDriver` or `MariaDBDriver` extending `BaseDatabase`.

**Shape:** one fluent builder, per-connection singleton. `table()` calls `reset()`, so state
never leaks between statements — but also means you cannot hold a partially built query
across a `table()` call.

### Capability inventory

| Area | Supported |
|---|---|
| Reads | `select`, `selectRaw`, `distinct`, `where*` (~40 variants incl. date/time/JSON/EXISTS), `join`/`leftJoin`/`rightJoin`/`innerJoin`/`outerJoin`/`crossJoin`, `groupBy(+Raw)`, `having`, `orderBy(+Raw)`, `limit`/`offset`/`forPage`, unions, index hints, `lockForUpdate`, `sharedLock` |
| Terminals | `get`, `fetch`, `first`, `find`, `findMany`, `findOrFail`, `firstOrFail`, `sole`, `value`, `pluck`, `exists`, `doesntExist`, `count/sum/avg/min/max` |
| Writes | `insert`, `insertGetId`, `insertUsing`, `update`, `updateOrInsert`, `insertOrUpdate`, `firstOrCreate`, `firstOrNew`, `updateOrCreate`, `increment`, `decrement`, `delete`, `softDelete`, `restore`, `forceDelete`, `truncate` |
| Bulk | `insertInBatches`, `updateInBatches`, `upsertInBatches`, `deleteInBatches` — all accept `iterable`, auto-size the batch from a sample row, and take a progress callback |
| Streaming | `chunk`, `chunkById` (keyset), `cursor`, `lazy` → `Core\LazyCollection` |
| Pagination | `paginate($start,$limit,$draw)` and `paginate_ajax($_POST)` (DataTables server-side shape) with `setAllowedSortColumns()` / `setPaginateFilterColumn()` allow-lists, LIKE-wildcard escaping, and a filtered/total count cache |
| Relations | `with()` eager loading + `EagerLoadOptimizer`, `withCount/withSum/withAvg/withMin/withMax`, `whereHas` |
| Read/write split | `default.write` + `default.read[]` with round-robin, `sticky` reads after a write, `isReadOnlyStatement()` routing |
| Transactions | `transaction(callable, $attempts)` with savepoints and deadlock-retry detection |
| Resilience | Statement/lock timeouts, retry with backoff, `StatementCache`, `ConnectionPool`, `QueryCache` (tag + table-version invalidation), `SlowQueryLogger`, `PerformanceMonitor` |
| Schema | `Schema::create/table/drop/dropIfExists`, `Blueprint` with the full Laravel-ish column vocabulary, `foreignId()->constrained()->cascadeOnDelete()`, `MigrationRunner` with batches and rollback |

### Where it is weak

- **Identifier validation is a stub.** `DatabaseHelper::validateColumn()` (`DatabaseHelper.php:353`)
  only asserts "non-empty string". Every JOIN builder then interpolates the *foreign key*
  straight between backticks. **D-01.**
- `select()` passes any column containing `.`, ` as `, or `fn(...)` through verbatim. **D-02.**
- `_escapeJoinColumn()` returns input unchanged if it already contains a backtick. **D-03.**
- No SQLite / PostgreSQL driver. `DriverRegistry` exists but only MySQL and MariaDB are
  implemented — which also means the test suite cannot use an in-memory DB.
- `BaseDatabase` at 4,415 LOC in one class is the main maintainability liability.

---

## Routing

**Entry:** `app/routes/web.php`, `app/routes/api.php` (+ `app/routes/api/*.php`), wired by
`App\Providers\RoutingServiceProvider`.

**Registration API:** `get/post/put/patch/delete/options/match/any/resource/apiResource/
redirect/view/fallback`, `group(['prefix'=>…, 'middleware'=>[…]], fn)`.

**Per-route fluent API** (`RouteDefinition`): `->name()`, `->middleware()`, `->where()`,
`->permission()`, `->role()`, `->ability()`, `->webAuth()`, `->guestOnly()`, `->featureFlag()`.

**Matching:** static routes go into a `METHOD:uri` hash (O(1)); parameterised routes are
pre-compiled to named-group regexes and scanned **linearly** (**R-04**). `HEAD` folds to `GET`.
Global `Router::pattern()` constraints are supported.

**Caching:** `php myth route:cache` serialises the compiled index to
`storage/cache/routes.cache.php`. Closure actions are skipped with a warning — so any route
defined as a closure silently disappears from the cached set. Both `web.php` (the
`/modal/content` route) and `Router::redirect()`/`view()` create closures. **R-05.**

**Named URLs:** `route('name', [...])` → `Router::urlFor()`.

**What is missing vs Laravel:** route model binding exists but is `findById`-only (no custom
keys, no scoped bindings, no `missing()` handler); no signed URLs; no rate-limiter definitions
in code (they are config-only); no per-route caching directives beyond the `cache.headers`
middleware.

---

## Auth

Split across `Components\Auth` (3,998 LOC) and `systems/Core/Auth/*`. The split is partial:
`Auth` still owns session handling, RBAC caching, login history, digest nonces, and JWT
decoding, while delegating tokens, authorization, credentials, and login policy.

**Entry:** `auth()`.

| Method family | Calls |
|---|---|
| Identity | `check($methods)`, `guest()`, `via()`, `id()`, `user()`, `checkAny()` |
| Login | `attempt(['email'=>…, 'password'=>…])`, `login($userId, $sessionData)`, `loginUsingId()`, `logout()`, `lastAttemptStatus()` |
| Sessions | `sessions()`, `revokeSession($sid)`, `logoutOtherDevices($password)` |
| Tokens | `createToken()`, `revokeToken()`, `revokeCurrentToken()`, `revokeAllTokens()`, `tokens()`, `currentToken()`, `rotateToken()` |
| Other credentials | `createApiKey()`, `createOAuth2Token()`, `issueApiCredential()`, `revokeCurrentApiCredential()` |
| RBAC | `roles()`, `hasRole/hasAnyRole/hasAllRoles`, `permissions()`, `hasPermission/hasAny/hasAll`, `can()`, `cannot()`, `hasAbility()`, `assignRole()`, `syncRoles()`, `revokeRole()`, `grantPermissionsToRole()`, `revokePermissionsFromRole()` |
| Social | `socialite($provider, $socialUser, $onCreate)` |
| Diagnostics | `debugAuthState()`, `schemaAudit()` |

**Eight auth methods** are implemented: `session`, `token`, `jwt`, `api_key`, `oauth`,
`oauth2`, `basic`, `digest`. `api.auth.methods` defaults to `['token']` only — correct
least-privilege default.

### Session hardening actually implemented

Client fingerprint binding (`validateSessionFingerprint()`, with a normalised-UA mode),
concurrency limits (`validateSessionConcurrency()`), a per-user session registry,
absolute lifetime, periodic ID regeneration, status gating (`isUserStatusAllowed`),
password-rotation policy, login-attempt buckets with lockout and automatic IP ban,
audit logging with redaction of sensitive context.

### Token model

`{tokenId}|{80-hex-char secret}`, stored as `sha256(secret)`. Lookup is by `(id, hash)` with a
legacy fallback to `sha256(fullToken)`. Abilities are a JSON array column.

**Gaps:** no refresh tokens, no device binding, no token-version/global revocation,
`ensureTokenTable()` DDL on every create (**T-02**), a `last_used_at` write on every
authenticated request (**T-01**), and no `hash_equals` on the final comparison — it relies on
SQL equality (**T-03**, low).

---

## Security

| Component | What it gives you |
|---|---|
| `Core\Security\Hasher` | Argon2id `m=65536, t=4, p=2`; `verify()` accepts legacy bcrypt; `needsRehash()`; a **real** `DUMMY_HASH` for constant-time user-miss; `hashToken()`; `equals()` = `hash_equals`. Best-in-class here. |
| `Core\Security\Encryptor` | AES-256-GCM via libsodium, BLAKE2b-derived per-context keys from `APP_KEY`, deterministic `blindIndex()` for searchable encryption. Requires ext-sodium + AES-NI, and **throws** otherwise (**S-05**). |
| `Core\Security\IpBlocklist` | DB-backed with expiry, plus `blocklist:*` console commands. |
| `Core\Security\FileUploadGuard` | Per-profile upload rules from `framework.upload_guards`. |
| `Core\Security\RateLimiter` + `App\Http\Middleware\RateLimit` | Three tiers: APCu atomic → cache driver (`add`+`increment`, Redis-atomic) → file `flock`. Named limiters in `framework.rate_limiters`. |
| `Core\Security\AuditLogger`, `CspViolationLogger`, `PwnedPasswordChecker` | Audit trail, CSP report ingestion, HIBP k-anonymity check. |
| `Components\CSRF` | Double-submit cookie + Origin/Referer verification, `csrf_allow_missing_origin` defaults to **false**. |
| `Components\Security` | XSS and SQLi pattern blocklists used by `XssProtectionTrait` and `ValidateRequestSafety`. |
| `SecurityHeadersTrait` | CSP (enforce/report modes, nonces, report-uri), HSTS, X-Frame-Options, Referrer-Policy, Permissions-Policy, COOP/COEP. |

**Request hardening** (`security.request_hardening`): max URI 2000, max body 10 MB,
max UA 1024, max 64 headers, max 200 input vars, max 200 JSON fields, max 50 multipart parts,
host allow-list, write content-type allow-list.

**Automated audit:** `php myth security:audit` runs `App\Support\SecurityAuditRunner` (826 LOC)
— passive config checks plus optional authenticated probes against a live URL. There is also
a `.github/workflows/security-performance.yml` CI job.

---

## Cache

**Entry:** `cache()` → `Core\Cache\CacheManager`.

`get/put/forever/remember/forget/flush/has/add/increment/decrement/getMetadata`, `store($name)`.

Drivers: `file` (default), `array`, `apcu` (degrades to file if absent), `redis` (degrades to
file if `ext-redis` absent). Prefix `MythPHP_`.

**Weakness:** the default store is `file` and there is no tiered store. On a busy API, every
`cache()->get()` is a `stat` + `file_get_contents` + `unserialize`. `FileStore` has no garbage
collection beyond `cache:clear` and no sharding, so `storage/cache/app` becomes a
single flat directory with unbounded file count. **C-01, C-02.**

Separately, `Core\Database\QueryCache` is its own static file+APCu cache with table-version
invalidation, not routed through `CacheManager` — two cache systems to reason about. **C-03.**

---

## Sessions

`Core\Session\SessionBootstrapper` configures PHP's native session before `session_start()`:
`gc_maxlifetime` from `session.lifetime`, optional `save_path`, and `session_set_save_handler()`
for the Redis driver. `RedisSessionHandler` implements per-session locking
(`lock_ttl`, `lock_wait_ms`, `lock_retry_us`) — good, this is the part most hand-rolled
handlers get wrong.

Cookie policy is set in `bootstrap.php`: `Secure` (when HTTPS or production), `HttpOnly`,
`SameSite` from config, `__Secure-`/`__Host-` prefixing, `use_strict_mode=1`,
`use_only_cookies=1`, 48-char SIDs on PHP < 8.4.

**Gaps:** file driver has no locking story of its own (PHP's native flock applies, which
serialises concurrent AJAX from one browser); there is no `session_write_close()` call
anywhere, so long controller actions hold the session lock for their whole duration — a real
throughput problem for the DataTables/AJAX-heavy front end. **SE-01.**

---

## Views / Blade

`Core\View\BladeEngine` (1,198 LOC), one file, no dependency on Laravel's compiler.

Directive coverage is genuinely close to Laravel's. Verified present in the compiler:

```
@extends @section @yield @parent @show @include @each @component @slot
@push @prepend @stack @once @endonce @verbatim @php @json
@if @elseif @else @unless @isset @empty @switch @case @default @break @continue
@for @foreach @forelse @while
@auth @guest @can @cannot @env @production @error @session @has
@csrf @method @nonce @sri @dump @dd
@class @style @checked @selected @disabled @readonly @required
```

Plus a filesystem group (`@file @mkdir @rename @unlink @stat`) that is unusual to expose in a
template layer and should probably be removed — templates writing to disk is a footgun.

Compilation: source → `storage/cache/views/{hash}.php`, written atomically
(`tmp` + `rename`) with `opcache_invalidate()`, keyed on an mtime/size signature with a
bounded in-process path cache. Optional `view_compact_compiled_cache` and
`view_minify_output`. `php myth view:cache` pre-compiles everything.

**Expression compilation goes through one scanner.** `replaceDirectiveCalls()` +
`extractBalancedExpression()` is quote-, escape- and depth-aware, respects a word boundary
(so `@for` cannot consume `@foreach`), and tolerates whitespace before the parenthesis.

Until 2026-08-25 the most-used directives bypassed it for a naive
`preg_replace('/@if\s*\((.*?)\)/', …)`, whose non-greedy `.*?` stopped at the first `)`. So
`@if(count($users) > 0)` compiled to `<?php if (count($users):` with `> 0)` left behind —
a parse error. That is [B-01](10-audit-findings.md#b-01), now fixed and covered by
`BladeNestedExpressionTest`. Bare `@break`/`@continue`, which were never compiled at all,
work too — which is what makes `@switch`/`@case` usable.

**Still a footgun:** `render()` uses `extract($vars, EXTR_SKIP)`, so a view variable whose
name collides with one of the method's own locals — `$data`, `$shared`, `$view`, `$vars`,
`$content`, `$compiled`, `$viewFile`, `$extends`, `$isTopLevel`, `$__blade` — is silently
dropped. `$data` in particular is a very common view-variable name. **B-05.**

**Other gaps:** no public directive-registration API (adding one means editing
`compileString()`); no class-backed components; HTML minification is regex-based on rendered
output — cheap, but unsafe inside `<pre>`/`<textarea>`. **B-02, B-03.**

---

## Console

`php myth <cmd>` → `Core\Console\Kernel`. 62 commands. Built-ins live in the 2,602-line
`Core\Console\Commands`; app commands are auto-discovered from `app/console/commands/*.php`.

Notable groups: `make:*` (10 generators), `migrate:*`, `db:*`, `queue:*`, `route:*`, `cache:*`,
`config:*`, `view:*`, `backup:*`, `blocklist:*`, `perf:*`, `security:audit`, `auth:security:test`,
`env:check`, `key:generate`, `deploy` (warms config+route+view caches), `down`/`up`, `serve`.

Scheduler: `app/routes/console.php` defines events; `schedule:run` (cron-driven) and
`schedule:work` (foreground loop with `--max-cycles` / `--max-memory`).

**Gaps:** no `tinker`/REPL, no `make:test`, no `make:policy|event|listener|factory`,
no `queue:restart`, no `optimize:clear`, no `db:wipe`, no `route:list --json`.
`Commands.php` at 2,602 LOC should be split into command classes. **CO-01.**

---

## Queue

`Core\Queue\{Dispatcher, Worker, Job, RedisQueue}`. Database-backed by default (priority
columns added in migration `20260516_015`), Redis optional. `dispatch(new Job)` helper.
`queue:work` supports `--sleep --tries --timeout --max-priority --once`; `queue:retry`,
`queue:failed`, `queue:flush`, `queue:clear`.

**Gaps:** no batching, no chaining, no unique jobs, no rate-limited/middleware-wrapped jobs,
no `queue:restart` signal, no horizon-style supervisor.

---

## Backup

`Components\Backup` (986 LOC). Tries `mysqldump` from an allow-listed set of binary paths;
falls back to a pure-PHP dump (`SHOW TABLES` → `SHOW CREATE TABLE` → batched `INSERT`).
Zips files with exclusion patterns, publishes to a managed disk (local / S3 / Google Drive
adapters in `app/support/Filesystem/`), and offers `cleanup($days)` / `listBackups()`.
Identifiers are backtick-escaped correctly in the PHP fallback path.

**Gaps:** no encryption of the resulting archive, no integrity checksum recorded, no restore
command (backup only), and the PHP fallback buffers each table's rows in memory per batch
rather than streaming with an unbuffered cursor. **BK-01, BK-02.**
