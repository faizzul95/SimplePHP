# 20 — MythPHP vs Laravel 12 / CI3 / CI4 / CakePHP 5 / Yii2

**Verified:** 2026-08-26 (fifth pass)

> **Refreshed 2026-08-26** (fifth pass). **1,560 tests**, PHPStan level 2 clean,
> still **2 runtime Composer dependencies**.
>
> This pass: a spoofable client address behind proxies, a rate limiter that
> resolved the user before rejecting and had no coarse ceiling, cache counters
> that never expired, `whereColumn` emitting a single identifier for a qualified
> name, per-driver DSN construction for SQL Server and Oracle, replay protection
> for token clients, and one negotiated response so a browser form post finally
> redirects.
>
> The fourth pass added driver-based statement timeouts, a token-first API
> default, the widened query-grammar seam, `whereExists` / `joinSub` /
> `insertOrIgnore` / `skipLocked`, cache stampede protection, request-correlated
> logging, and 24 output-escaping call sites that silently blanked any string
> containing an invalid UTF-8 byte.

## How to read this

MythPHP claims are **verified against this repository's source**, cited by file where it
matters. Competitor claims are from their documented, shipped behaviour.

**Performance numbers are not head-to-head benchmarks.** The only measured figures here are
MythPHP's own (`php myth perf:benchmark`, PHP 8.3.8 NTS x86, **no OPcache**, Windows).
Comparative performance is reasoned from architecture — request-path work, allocation count,
autoload surface — and labelled as such. Anyone quoting a speed multiple from this document
without running a benchmark on the target hardware is quoting a guess.

**And the local benchmark itself is too noisy to draw conclusions from.** Two consecutive
identical runs produced 0.0263 and 0.0139 ms/op for routing — a 2× swing on the same code.
Without OPcache and on Windows this harness measures scheduler noise as much as the
framework. No performance claim in this document rests on it. Anything that reports a
before/after improvement needs a quiet Linux box with OPcache enabled and enough iterations
for the variance to settle.

`docs/framework-security-performance-comparison.md` is the previous version of this analysis.
It is superseded: it cites file paths that no longer exist and predates the findings in
[10-audit-findings.md](10-audit-findings.md).

### Field

| | Version | Status |
|---|---|---|
| **MythPHP** | this repo, PHP 8.2+ | Bespoke, 2 runtime deps |
| **Laravel** | 12.x | The reference point |
| **CodeIgniter 3** | 3.1.13 | **EOL** — no security support. Included because it is the migration source for many MY/SG shops. |
| **CodeIgniter 4** | 4.6.x | Active |
| **CakePHP** | 5.1.x | Active |
| **Yii2** | 2.0.5x | Maintenance mode; Yii3 is still not GA |

---

## 1. Footprint and startup

| | Composer packages installed | `vendor/` size | Classes touched per plain request |
|---|---|---|---|
| **MythPHP** | **2** runtime (`phpmailer`, `google/apiclient`) | small; `google/apiclient` dominates | ~30–40 |
| Laravel 12 | ~110 (Symfony, Carbon, Monolog, …) | ~45–60 MB | 300–600 |
| CI4 | ~5 | ~5 MB | ~60–90 |
| CakePHP 5 | ~25 | ~20 MB | ~200 |
| Yii2 | ~15 | ~15 MB | ~150 |
| CI3 | 0 | ~3 MB | ~25 |

**Verdict: MythPHP wins decisively, and this is its real differentiator.** If `google/apiclient`
were moved to `suggest` (it is only used by the Google Drive backup adapter), the runtime
dependency count would be **one**. That is worth doing — see [30-roadmap.md](30-roadmap.md).

Where Laravel closes the gap: `config:cache` + `route:cache` + `optimize` + OPcache preloading
make its real-world cold-start far better than the raw class count suggests. MythPHP has
`config:cache`, `route:cache`, `view:cache` and a `deploy` command that runs all three, plus a
`systems/Core/Server/preload.php`. **The tooling parity is already there.**

---

## 2. Routing

| Capability | Myth | L12 | CI3 | CI4 | Cake5 | Yii2 |
|---|---|---|---|---|---|---|
| Static route O(1) lookup | ✅ | ✅ | ❌ regex list | ✅ | ✅ | ✅ |
| Dynamic matching | ⚠️ linear scan ([R-04](10-audit-findings.md#r-04)) | ✅ chunked regex | ❌ linear | ✅ | ✅ | ✅ |
| Route caching | ✅ but drops closures silently ([R-05](10-audit-findings.md#r-05)) | ✅ | ❌ | ✅ | ✅ | ✅ |
| Named routes + URL generation | ✅ | ✅ | ❌ | ✅ | ✅ | ✅ |
| Groups / prefixes / middleware groups | ✅ | ✅ | ❌ | ✅ | ✅ | ⚠️ modules |
| Route model binding | ⚠️ `findById` only | ✅ incl. scoped, custom key, `missing()` | ❌ | ❌ | ⚠️ | ❌ |
| `resource()` / `apiResource()` | ✅ | ✅ | ❌ | ✅ | ✅ | ⚠️ |
| Per-route fluent auth/permission/feature DSL | ✅ `->webAuth()->permission()->featureFlag()` | ❌ middleware strings only | ❌ | ❌ | ❌ | ❌ |
| Signed URLs | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ |
| Rate limiters defined in code | ❌ config only | ✅ `RateLimiter::for()` | ❌ | ⚠️ | ❌ | ❌ |
| Sub-domain routing | ❌ | ✅ | ❌ | ✅ | ✅ | ✅ |

**Verdict:** MythPHP's per-route DSL is genuinely nicer than Laravel's for RBAC-heavy apps —
`->permission('user-view')` reads better than `->middleware('can:user-view')`. It is behind on
matching algorithm, binding richness, signed URLs and sub-domains.

**Improve:** bucket dynamic routes ([R-04](10-audit-findings.md#r-04)); make `route:cache` fail
loudly on closures ([R-05](10-audit-findings.md#r-05)); add signed URLs (≈80 LOC using
`APP_KEY` + `hash_hmac`) — they are the cheapest way to do password resets, email
verification, and temporary file links without extra tables.

---

## 3. Database — can it handle large data?

This is where MythPHP is strongest and the answer is **yes, better than most of this field.**

| Capability | Myth | L12 | CI3 | CI4 | Cake5 | Yii2 |
|---|---|---|---|---|---|---|
| Read/write splitting | ✅ multi-replica, round-robin, sticky | ✅ | ❌ | ⚠️ | ✅ | ⚠️ |
| Keyset (`chunkById`) pagination | ✅ + auto-detects when it is safe | ✅ | ❌ | ❌ | ❌ | ❌ |
| `cursor()` / lazy generators | ✅ `LazyCollection` | ✅ | ❌ | ⚠️ | ⚠️ | ⚠️ |
| Bulk insert/update/**upsert** in batches from an `iterable` | ✅ auto-sized from a sample row + progress callback | ⚠️ `upsert()` only, no streaming | ❌ | ⚠️ | ⚠️ | ⚠️ |
| Prepared-statement cache | ✅ `StatementCache` | ❌ | ❌ | ❌ | ❌ | ❌ |
| Connection pool with APCu metadata | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Query result cache with **table-version** invalidation | ✅ `QueryCache` | ❌ manual | ⚠️ file, manual | ⚠️ | ⚠️ | ✅ dependency-based |
| Slow-query log + threshold alerting | ✅ built in, `db:slow` | ❌ (Telescope, dev) | ❌ | ❌ | ❌ | ⚠️ debug bar |
| Statement / lock-wait timeouts | ✅ configurable | ❌ | ❌ | ❌ | ❌ | ❌ |
| Deadlock retry with savepoints | ✅ | ✅ | ❌ | ❌ | ⚠️ | ⚠️ |
| Retryable-error coverage | ✅ 8 driver codes + SQLSTATE 40001, walks the cause chain | ⚠️ 6 codes, outermost only | ❌ | ❌ | ❌ | ❌ |
| Backoff strategy | ✅ full jitter, capped | ⚠️ fixed `sleep` | ❌ | ❌ | ❌ | ❌ |
| Deadlock *prevention* (key-ordered locking) | ✅ batch writes sort keys | ❌ | ❌ | ❌ | ❌ | ❌ |
| `SKIP LOCKED` with capability detection | ✅ falls back below MySQL 8.0.1 / MariaDB 10.6 | ⚠️ uses it unconditionally | ❌ | ❌ | ❌ | ❌ |
| Statement timeout, per engine | ✅ `max_execution_time` / `max_statement_time`, applied and verified | ❌ | ❌ | ❌ | ❌ | ❌ |
| Timeout errors explained, not raw | ✅ names the setting and the remedy | ❌ | ❌ | ❌ | ❌ | ❌ |
| Bounded-memory streaming | ✅ keyset chunking, GC swept every 50 chunks not every one | ✅ `cursor()` unbuffered | ❌ | ⚠️ | ⚠️ | ⚠️ |
| Eager loading / N+1 avoidance | ✅ `with`, `withCount/Sum/Avg/Min/Max`, `whereHas`, `EagerLoadOptimizer` | ✅ | ❌ | ⚠️ | ✅ | ✅ |
| Schema builder + migrations + rollback batches | ✅ | ✅ | ⚠️ | ✅ | ✅ | ✅ |
| DataTables server-side pagination with count cache | ✅ first-class | ❌ | ❌ | ❌ | ❌ | ❌ |
| **Drivers** | ⚠️ **MySQL/MariaDB only** | MySQL, Postgres, SQLite, SQL Server | MySQL, PG, SQLite, MSSQL, Oracle | MySQL, PG, SQLite, MSSQL, OCI | MySQL, PG, SQLite, MSSQL | MySQL, PG, SQLite, MSSQL, Oracle, CUBRID |
| Identifier validation on JOIN / ORDER BY | ✅ fixed [D-01](10-audit-findings.md#d-01)/[D-03](10-audit-findings.md#d-03) | ✅ | ⚠️ | ✅ | ✅ | ✅ |
| Identifier validation on `select()` | ⚠️ still passes expressions through ([D-02](10-audit-findings.md#d-02)) | ✅ | ⚠️ | ✅ | ✅ | ✅ |

**Verdict:** on *large-dataset MySQL workloads under concurrency* MythPHP is now the strongest
in this field. Statement cache, table-version query cache, statement timeouts, built-in
slow-query aggregation and streaming bulk upsert are things Laravel does not ship; key-ordered
lock acquisition and `SKIP LOCKED` capability detection are things nothing else in the field
does at all.

It still loses decisively on **driver breadth** — one database engine, where everyone else has
four or more.

### What "database portability" means, and why it is still 3/10

Portability is *how much has to change to run the same application on a different database
engine*. Everyone else in this field abstracts the SQL dialect behind a grammar layer, so
switching from MySQL to PostgreSQL is a config change plus a migration re-run. Here it is a
rewrite, and the score is 3 rather than 0 only because MariaDB works.

What is actually MySQL-specific, and why each one blocks a port:

| Coupling | Where | Why it does not port |
|---|---|---|
| Backtick identifier quoting | Every builder method | PostgreSQL and SQL Server use `"` and `[ ]`. Backticks are a syntax error. |
| Two drivers, both MySQL | `MySQLDriver`, `MariaDBDriver` | `DriverRegistry` is built for more, but no other engine is implemented. |
| `INSERT … ON DUPLICATE KEY UPDATE` | `HasBatchWrites::upsert()` | PostgreSQL uses `ON CONFLICT`, SQL Server `MERGE`. |
| `SHOW COLUMNS` / `SHOW INDEX` / `SHOW CREATE TABLE` | Schema introspection, migrations, backup | Not SQL standard. PostgreSQL uses `information_schema`. |
| `max_execution_time` / `max_statement_time` | `applyStatementTimeout()` | PostgreSQL uses `statement_timeout`, in a different unit again. |
| `innodb_lock_wait_timeout` | `applySessionPerformanceRules()` | InnoDB-specific. PostgreSQL has `lock_timeout`. |
| `FOR UPDATE SKIP LOCKED` | Queue job claim | PostgreSQL supports it; SQL Server needs `READPAST`. |
| `LIMIT n OFFSET m` | Pagination and streaming | SQL Server needs `OFFSET … FETCH NEXT`. |
| MySQL error codes (1213, 1205, 3024, …) | Deadlock retry, timeout detection | PostgreSQL uses SQLSTATE only. The SQLSTATE 40001 check already added is the portable half. |

**Why it has not moved:** nothing in this review touched it, because portability is not a bug
to fix — it is a feature to build, and a large one. A `SqliteDriver` alone
([D-05](10-audit-findings.md#d-05)) would need a grammar abstraction, an introspection
abstraction, and a dialect-aware upsert.

**Whether it should move is a strategy question, not a technical one.** The single-engine focus
is *why* the concurrency column scores 9: key-ordered locking, `SKIP LOCKED` capability
detection and engine-aware statement timeouts are all things you can only do well when you know
which engine you are talking to. A portability layer would dilute exactly that.

The pragmatic middle is a **SQLite driver scoped to tests only** — enough to run the suite
against real SQL without a MySQL server, not enough to claim production portability. That is
worth doing. Full multi-engine support is worth doing only if someone actually needs to deploy
on PostgreSQL.

**Two things found and fixed here on 2026-08-25 that the earlier revision of this document
missed entirely:**

- [D-08](10-audit-findings.md#d-08) — `MariaDBDriver::batchInsert()` and `batchUpdate()` were
  stubs returning `$this`, which the batch runner reads as success. Every bulk write on a
  MariaDB connection reported success and wrote nothing. That is worse than any gap in the
  comparison table above, and it was invisible because nothing failed.
- [D-07](10-audit-findings.md#d-07) — the queue's job-claim transaction, the most contended
  statement in the framework, was running with retries disabled.

**Still to improve:** [D-02](10-audit-findings.md#d-02) (`select()` passthrough); a SQLite
driver for tests ([D-05](10-audit-findings.md#d-05)); split `BaseDatabase`
([D-04](10-audit-findings.md#d-04)); tighten `iterableWriteBatchSucceeded()`, whose
permissiveness is what let D-08 stay silent.

---

## 4. Security

| Control | Myth | L12 | CI3 | CI4 | Cake5 | Yii2 |
|---|---|---|---|---|---|---|
| Password hashing | ✅ **Argon2id 64 MB/t=4/p=2** + real dummy-verify | ⚠️ bcrypt default | ❌ none | ❌ none | ⚠️ bcrypt | ⚠️ bcrypt |
| Timing-safe user-miss | ✅ genuine `DUMMY_HASH` constant | ⚠️ partial | ❌ | ❌ | ⚠️ | ⚠️ |
| CSRF | ⚠️ **double-submit cookie** ([S-01](10-audit-findings.md#s-01)) + Origin check | ✅ session-bound | ⚠️ | ✅ | ✅ | ✅ |
| CSP with nonces + violation reporting | ✅ built, **but `'unsafe-inline'` by default** ([S-03](10-audit-findings.md#s-03)) | ❌ (package) | ❌ | ⚠️ | ⚠️ | ❌ |
| Column encryption + blind index | ✅ AES-256-GCM (needs ext-sodium, [S-05](10-audit-findings.md#s-05)) | ⚠️ `encrypted` cast, no blind index | ❌ | ⚠️ | ⚠️ | ⚠️ |
| Rate limiting | ✅ 3-tier atomic (APCu/Redis/file) | ✅ | ❌ | ⚠️ | ⚠️ | ⚠️ |
| IP blocklist with expiry + CLI | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Trusted proxies / hosts with CIDR | ✅ | ✅ | ❌ | ✅ | ✅ | ✅ |
| Request hardening caps (URI/body/headers/input vars/JSON fields) | ✅ | ❌ | ❌ | ⚠️ | ❌ | ❌ |
| Session fingerprint binding + concurrency limits | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Login lockout + auto IP ban + audit log | ✅ | ⚠️ `ThrottlesLogins` | ❌ | ❌ | ⚠️ | ⚠️ |
| Pwned-password check (HIBP k-anonymity) | ✅ | ⚠️ `Password::uncompromised()` | ❌ | ❌ | ❌ | ❌ |
| SRI hashes for assets | ✅ `@sri` + `AssetIntegrity` | ❌ | ❌ | ❌ | ❌ | ❌ |
| Automated security audit command | ✅ `security:audit` (826 LOC) + CI workflow | ❌ | ❌ | ❌ | ❌ | ❌ |
| Upload guard profiles | ✅ | ⚠️ validation rules | ❌ | ⚠️ | ⚠️ | ⚠️ |
| Upload: MIME re-validation + decode proof | ✅ finfo **and** `getimagesize()` | ⚠️ MIME only | ❌ | ⚠️ | ⚠️ | ⚠️ |
| Upload: decompression-bomb guard | ✅ 24M pixel ceiling | ❌ | ❌ | ❌ | ❌ | ❌ |
| Upload: size ceiling per route | ✅ | ✅ validation rule | ❌ | ✅ | ✅ | ✅ |
| Device limit — browser sessions | ✅ 1 / N / unlimited, revoke-oldest or deny | ❌ | ❌ | ❌ | ❌ | ❌ |
| Device limit — API tokens | ✅ same policy on the token surface | ❌ | ❌ | ❌ | ❌ | ❌ |
| SQL identifier safety | ✅ JOIN/ORDER BY closed; `select()` still open ([D-02](10-audit-findings.md#d-02)) | ✅ | ⚠️ | ✅ | ✅ | ✅ |
| Worker-mode isolation | ✅ full state flush + session cycling, gated pending load testing | ✅ Octane, mature | n/a | n/a | n/a | n/a |
| Single response emission point | ✅ `Core\Http\Emitter`, no `exit` in the request path | ✅ | ❌ | ✅ | ✅ | ✅ |

**Verdict:** MythPHP's *deliberate* security work is genuinely ahead of Laravel in several
places — Argon2id defaults, blind-index encryption, session fingerprinting, IP blocklist,
request hardening, SRI, and an automated audit command are all things Laravel expects you to
add yourself. Password hashing in particular is best-in-field, and CI3/CI4 ship nothing.

The two holes that were worse than any competitor — identifier validation and worker-mode
isolation — are both closed as of 2026-08-25.

**What is left is a shorter and more ordinary list**, and none of it is a hole no other
framework has:

| Gap | Why it still matters |
|---|---|
| [S-01](10-audit-findings.md#s-01) CSRF is double-submit, not session-bound | A sibling subdomain that can set a cookie can forge. Mitigated by an Origin check that defaults to strict, which is why it is P1 not P0. Laravel, CI4, CakePHP and Yii2 all bind to the session. |
| [S-03](10-audit-findings.md#s-03) CSP ships `'unsafe-inline'` | That one directive value defeats the whole mechanism. The nonce infrastructure is already built; the blocker is the bundled views' inline handlers. |
| [D-02](10-audit-findings.md#d-02) `select()` passes expressions through | Only reachable if a column list is built from request input, which nothing currently does — but a framework should not permit it. |
| [S-05](10-audit-findings.md#s-05) `Encryptor` needs AES-NI | **Throws on every call on the current dev machine**, which has no ext-sodium. Any ARM VM or shared host without AES-NI is in the same position, and this framework explicitly targets shared hosting. |

On controls *shipped*, MythPHP remains ahead of Laravel: Argon2id defaults, blind-index
encryption, session fingerprinting, IP blocklist, request hardening, SRI, and an automated
audit command are all things Laravel expects you to add yourself. Password hashing is
best-in-field. CI3 and CI4 ship nothing comparable.

---

## 5. RBAC / authorization

| | Myth | L12 | CI3 | CI4 | Cake5 | Yii2 |
|---|---|---|---|---|---|---|
| Built-in roles + permissions | ✅ **in core** | ❌ needs `spatie/laravel-permission` | ❌ | ⚠️ Shield | ❌ | ✅ RBAC component |
| Permission caching per user | ✅ with invalidation | via package | ❌ | ⚠️ | ❌ | ✅ |
| Route-level `->permission()` / `->role()` | ✅ | via package + `can:` | ❌ | ⚠️ | ❌ | ⚠️ filters |
| Token abilities / scopes | ✅ | ✅ Sanctum | ❌ | ❌ | ❌ | ❌ |
| Menu-tree access filtering | ✅ `MenuManager` + `menu.access` middleware | ❌ | ❌ | ❌ | ❌ | ⚠️ |
| Policies / per-model gates | ❌ | ✅ `Policy` classes | ❌ | ❌ | ✅ | ⚠️ rules |
| Hierarchical roles / inheritance | ❌ | via package | ❌ | ❌ | ❌ | ✅ |
| Feature flags | ✅ `FeatureManager` + `->featureFlag()` | ✅ Pennant | ❌ | ❌ | ❌ | ❌ |

**Verdict: strongest area relative to the field.** RBAC + feature flags + menu filtering in
core, with route-level DSL, beats Laravel-without-packages outright and matches
Laravel-with-Spatie for less setup. Yii2 is the only competitor with comparable built-in RBAC,
and its API is clunkier.

**Improve:** add **policies** (per-model authorization: `$this->authorize('update', $report)`).
Right now every ownership check is hand-written in controllers, which is where IDOR bugs come
from — note the framework already ships a `DetectIdor` middleware, which is treating the
symptom. Also add hierarchical roles.

---

## 6. Developer experience

| | Myth | L12 | CI3 | CI4 | Cake5 | Yii2 |
|---|---|---|---|---|---|---|
| Scaffolding generators | ✅ 10 `make:*` | ✅ ~30 | ❌ | ✅ | ✅ bake (best in class) | ✅ Gii |
| REPL / tinker | ❌ | ✅ | ❌ | ❌ | ✅ | ❌ |
| Debug bar / profiler | ⚠️ `Components\Debug` + `perf:*` | ✅ Telescope/Ignition | ❌ | ✅ | ✅ | ✅ |
| Validation rules | 73 incl. `unique`/`unique_with`/`exists` ([V-01](10-audit-findings.md#v-01)) | ~90 | ~30 | ~40 | ~50 | ~30 |
| Blade / template engine | ✅ ~60 directives, nested parens fixed ([B-01](10-audit-findings.md#b-01)) | ✅ Blade | ❌ raw PHP | ⚠️ basic | ✅ | ⚠️ |
| Blade components (class-backed) | ❌ view-only | ✅ | ❌ | ❌ | ✅ Cells | ✅ Widgets |
| API resources / transformers | ❌ | ✅ | ❌ | ⚠️ | ❌ | ✅ Serializer |
| OpenAPI generation | ❌ | ⚠️ package | ❌ | ❌ | ⚠️ | ⚠️ |
| Static analysis level | ⚠️ **PHPStan 2/10** ([QA-01](10-audit-findings.md#qa-01)) | Larastan 5–8 typical | none | ~5 | ~8 | ~5 |
| Test tooling | ✅ unit + end-to-end, 1,204 tests; no factories or DB refresh | ✅ HTTP tests, factories, DB refresh | ⚠️ | ✅ | ✅ | ✅ |
| Model factories / seeders | ⚠️ seeders only | ✅ factories | ❌ | ⚠️ | ✅ fixtures | ✅ |
| Docs | ⚠️ **3 conflicting sets** ([QA-03](10-audit-findings.md#qa-03)) | ✅ excellent | ⚠️ | ✅ | ✅ | ✅ |
| IDE autocompletion | ❌ no stubs/`_ide_helper` | ✅ | ❌ | ✅ typed | ✅ typed | ✅ |

**Verdict: still the weakest column, and it is what "easy to use" actually means.**
B-01 and V-01 are fixed — Blade compiles nested expressions and the DB validation rules
exist — which removes the two things a new developer hit on day one. What remains is
infrastructure rather than correctness: an HTTP test suite (QA-02), a higher static-analysis
level (QA-01), coherent docs (QA-03), IDE stubs, and API resources.

---

## 7. Runtime / worker mode

| | Myth | L12 |
|---|---|---|
| Worker support | ✅ RoadRunner + FrankenPHP, gated behind `MYTH_EXPERIMENTAL_WORKER` pending load testing | ✅ Octane (Swoole/RoadRunner/FrankenPHP), mature |
| Static-state flushing | ✅ registered classes **plus** all resolved service singletons | ✅ comprehensive |
| Session isolation per request | ✅ `SessionCycle` closes and rebinds from the incoming cookie | ✅ |
| Superglobal isolation | ✅ `$_SERVER` restored to a boot-time baseline each cycle | ✅ |
| `$_FILES` bridging | ✅ PSR-7 uploads mapped to the native array shape | ✅ |
| `exit`-free request path | ✅ 0 call sites (was 45) | ✅ |
| Concurrency primitives (tables, ticks, atomics) | ❌ | ✅ |
| Battle-tested at scale | ❌ | ✅ |

CI3, CI4, CakePHP and Yii2 have no first-party worker story at all, so MythPHP is now
comfortably ahead of the rest of the field and behind only Laravel.

**The gate stays on deliberately.** The isolation is implemented and has 19 tests behind it,
but "no test proves a leak" is not the same as "proven under concurrent load". Octane earned
its reputation over years of production use; this has had one afternoon. Run it in a
throwaway environment with `MYTH_EXPERIMENTAL_WORKER=1`, hammer it with authenticated
concurrent traffic from several identities, and confirm no request ever sees another's user
before lifting the flag.

What Laravel still has that this does not is the layer *above* isolation: `Octane::table()`,
tick callbacks, concurrent task execution. Those are additive features, not correctness gaps.

---

## 8. Scorecard

Scores are relative to this field, weighted for the stated use case (API-first, mobile,
large MySQL datasets, shared-hosting-friendly).

Two columns for MythPHP: where it stood at the start of the 2026-08-25 work, and where it
stands now. Only dimensions with landed, tested changes moved.

| Dimension | Start | 3rd | 4th | **Now** | L12 | CI3 | CI4 | Cake5 | Yii2 |
|---|---|---|---|---|---|---|---|---|---|
| Footprint / startup cost | 9 | 9 | 9 | **9** | 5 | 9 | 8 | 6 | 7 |
| Routing | 7 | 8 | 8 | **8** | **10** | 3 | 8 | 8 | 7 |
| Database (large data) | 9 | 9 | 9 | **9** | 8 | 3 | 6 | 7 | 7 |
| Database (portability) | 3 | 3 | 6 | **7** | **10** | 8 | 9 | 9 | **10** |
| Database (feature parity vs L12) | 7 | 7 | 8 | **8** | **10** | 2 | 6 | 7 | 7 |
| Database (concurrency / deadlock) | 4 | 9 | 9 | **9** | 8 | 2 | 4 | 5 | 6 |
| Caching | 5 | 5 | 8 | **9** | **9** | 3 | 6 | 7 | 7 |
| Rate limiting / DDoS resistance | 5 | 5 | 5 | **8** | 7 | 1 | 4 | 4 | 4 |
| Views / templating | 6 | 7 | 8 | **8** | **10** | 3 | 7 | 8 | 7 |
| Uploads / file handling | 6 | 8 | 8 | **8** | 7 | 2 | 6 | 6 | 6 |
| Security (controls shipped) | 9 | 9 | 9 | **9** | 7 | 2 | 6 | 7 | 6 |
| Security (no known holes) | 4 | 8 | 8 | **9** | **9** | 3 | 8 | 8 | 8 |
| RBAC / authorization | 9 | 9 | 9 | **9** | 7 | 1 | 5 | 5 | 8 |
| API / mobile readiness | 5 | 7 | 8 | **9** | **9** | 2 | 6 | 6 | 6 |
| Background / parallel processing | 4 | 8 | 8 | **8** | **9** | 1 | 4 | 4 | 5 |
| Observability / debugging | 3 | 4 | 7 | **7** | **9** | 2 | 5 | 6 | 6 |
| Developer experience | 4 | 6 | 7 | **8** | **10** | 3 | 7 | 8 | 7 |
| Worker / concurrency | 2 | 8 | 8 | **9** | **9** | 1 | 1 | 1 | 1 |
| Testability | 5 | 8 | 8 | **8** | **10** | 2 | 8 | 9 | 8 |
| Ecosystem / hiring | 2 | 2 | 2 | **2** | **10** | 4 | 6 | 5 | 5 |

**What moved in the fifth pass (2026-08-26)**

| Dimension | Change |
|---|---|
| Security (no known holes) 8 → 9 | The client address behind a proxy came from the leftmost `X-Forwarded-For` entry — the one the caller writes — so rotating that header defeated the rate limiter, the IP blocklist and the audit trail at once. The chain is now walked from the right, past our own proxies. `whereColumn` stopped wrapping a qualified name as a single identifier, and the two disagreeing status normalisers became one that clamps an invalid status to 500 rather than 200. Remaining: `select()` passthrough (D-02), double-submit CSRF (S-01), `unsafe-inline` CSP default (S-03), Encryptor requiring AES-NI (S-05). |
| Rate limiting / DDoS 5 → 8 | Three separate problems. The limiter resolved the authenticated user *before* deciding to reject, so a flood of unauthenticated requests bought a token lookup each — the defence was the amplifier. The per-route key gave an attacker a fresh budget per URL, so a coarse per-IP ceiling now runs first and costs one counter. And the file fallback wrote one state file per key and deleted none, which is inode exhaustion caused by the thing meant to prevent it. |
| Caching 8 → 9 | `increment()` on a missing key created it with no expiry, on every driver. On Redis that is unbounded memory growth; for a rate-limit counter recreated after expiry it pinned the caller at the limit permanently. The TTL now applies on creation and never extends a live window. |
| API / mobile 8 → 9 | `RequestSignature` and the `signed` middleware: a timestamp, a one-time nonce, and an HMAC over method, path and body, keyed by the caller's own access token. This is the control a token client actually needs — CSRF defends against *ambient* cookies and has nothing to defend on a bearer request. |
| DB portability 6 → 7 | The DSN had one shape, which reaches MySQL and PostgreSQL and nothing else. `Core\Database\Dsn` builds per engine, including SQL Server's comma-separated port and Oracle's Easy Connect descriptor; the old identifier pattern also rejected the ordinary Oracle service name `orclpdb1.localdomain`. The builder traits' remaining inline backticks now route through the grammar. |
| DX 7 → 8 | `Core\Http\Reply`: one call that answers a browser with a redirect carrying a flash message and an API client with JSON. Every controller previously answered in JSON, so no form submit in this application had ever redirected. `Router`'s validation failure path uses the same object rather than a second copy of the negotiation. |
| Worker / concurrency 8 → 9 | `cache()` and `dispatch()` held their instances in function statics, unreachable by the flush — so the array store, which documents itself as request-scoped, was not. The flash bag tracked "already rotated" the same way, so the second request a worker served kept the first one's flash keys readable. |

**What moved in the fourth pass (2026-08-26)**

| Dimension | Change |
|---|---|
| DB portability 3 → 6 | `QueryGrammar` went from one method to fourteen: identifier quoting, LIMIT/OFFSET, random ordering, upsert, insert-ignore, RETURNING, lock clauses, case-insensitive LIKE, full-text, column listing. PostgreSQL, SQL Server and Oracle grammars are written, registered and tested; `DESCRIBE` (MySQL-only) is gone in favour of a prepared `information_schema` query. **Not higher because** there are no connection drivers for those engines yet, ~80 inline backticks remain in the `Concerns/` traits, and the *schema* grammar is still MySQL-only. |
| DB feature parity 7 → 8 | `whereExists` / `orWhereExists` / `whereNotExists` / `orWhereNotExists`, `joinSub` / `leftJoinSub` / `rightJoinSub`, `insertOrIgnore`, `skipLocked()`, `noWait()`. Each previously forced a drop to `query()`, which gives up binding, profiling and read/write routing for the whole statement. |
| Caching 5 → 8 | `remember()` ran the callback *before* the atomic `add()`, so an expiring hot key was recomputed by every concurrent request — the comment above it claimed the opposite. Now one caller locks and computes while the rest wait briefly, with a bounded fallback. A cached `null` was also indistinguishable from a miss, so `remember()` around a nullable lookup never cached anything. An absolute `path` in the store config was prefixed with `ROOT_DIR`, producing an unwritable path that the `@`-suppressed writes hid entirely. |
| Views 7 → 8 | Every `htmlspecialchars` call passed `ENT_QUOTES` without `ENT_SUBSTITUTE`, which on PHP 8.1+ returns an **empty string** for any input containing an invalid UTF-8 byte — a name pasted from a Latin-1 source rendered as nothing. 24 call sites fixed. The compiled-view memo also outlived the file it pointed at, so `view:clear` against a warm worker served blank pages until restart. |
| Observability 4 → 7 | The response already handed the client `X-Request-Id`; the log did not know it, so the one thing a user could quote matched nothing on disk. `LogContext` now stamps request id, method, path and user on every line; errors and warnings carry the call site; exceptions carry a summarised trace and up to three `caused by` links instead of a wall of escaped newlines. `dump()`/`dd()`/`d()` gained an environment guard, content negotiation, escaping, and a `JSON_HEX_TAG` fix for `d($v, true)` breaking out of its own `<script>`. |
| API / mobile 7 → 8 | `api.driver` makes a fresh clone token-first: no session, no CSRF token, no Origin header required — which is what a mobile client can actually satisfy. `session` and `hybrid` are one config value away, and `hybrid` decides CSRF from the credential that actually authenticated rather than from the presence of an `Authorization` header (which would be a bypass). Maintenance windows now answer API clients in JSON with `Retry-After`, and `allowed_paths` keeps health checks and webhook receivers reachable. |
| DX 6 → 7 | Follows observability; the debugger and logger are the parts a developer touches when something is wrong. |

**What moved in the third pass (2026-08-25)**

| Dimension | Change |
|---|---|
| Worker / concurrency 2 → 8 | Full per-request state isolation, native `$_FILES` bridging, `$_SERVER` baseline restore, and an `exit`-free HTTP path. Still 8 not 9: gated behind `MYTH_EXPERIMENTAL_WORKER` until load-proven, and there is no equivalent of Octane's concurrency primitives. |
| DB concurrency 4 → 9 | 8 retryable error codes + SQLSTATE 40001, cause-chain inspection, full-jitter backoff, key-ordered batch locking (prevention, not just recovery), `SKIP LOCKED` capability detection. Ahead of Laravel, which recognises deadlocks but has no lock-ordering and no capability probe. |
| Background / parallel 4 → 8 | `queue:restart`, unique jobs, `queue:supervise` with respawn and backoff, retry budget on the job claim. Behind Laravel on batching and chaining. |
| Security (no holes) 4 → 7 | JOIN identifier injection closed; MariaDB silent-write bug closed; response splitting centralised behind one sanitiser. Still behind on `select()` passthrough (D-02), double-submit CSRF (S-01), `unsafe-inline` CSP default (S-03), and Encryptor requiring AES-NI (S-05). |
| Routing 7 → 8 | Single emission point, content-negotiated model-binding 404, correct HEAD. Matching algorithm unchanged (R-04). |
| DX 4 → 6 | Blade nested-paren compilation fixed, `unique:`/`exists:` rules added. |
| Testability 5 → 8 | 693 → 1,204 tests, and the **end-to-end suite now exists** — it found 4 real bugs on its first two runs. Still no factories, no DB refresh, no SQLite driver (D-05). |
| Uploads 6 → 8 | Size ceiling, image-decode proof, pixel-bomb guard, explained errors, worker-compatible source check. |
| Security (no holes) 7 → 8 | MariaDB statement timeout actually applied; upload hardening; the middleware-abort gap closed. Remaining: D-02, S-01, S-03, S-05. |
| API / mobile 6 → 7 | Device limits now cover the token surface, not just browsers. Refresh tokens (T-04) still the headline gap. |

**Where MythPHP already wins:** footprint, large-dataset MySQL tooling, deadlock handling,
breadth of shipped security controls, RBAC.
**Where the gap is still real and closable:** database portability (drivers, not grammars),
refresh tokens, test factories, an interactive error page.
**Where it will never win, and should not try:** ecosystem and hiring pool. Portability is
no longer on this list — the grammar seam makes it a driver-writing exercise rather than an
architectural one.

---

## 8b. Closing the remaining gaps

Ordered by value per unit of work. Each entry says what it buys and what it costs.

### Cheap, high value

| Gap | What to do | Why it matters |
|---|---|---|
| **Refresh tokens (T-04)** | Issue a short-lived access token plus a long-lived rotating refresh token; revoke the family on reuse. | The largest remaining API gap. Without it a mobile app either re-prompts for a password or holds a long-lived bearer, and there is no way to revoke a stolen one before it expires. |
| **`select()` passthrough (D-02)** | Route `select()` column lists through `grammar()->wrap()` the way the insert and join paths now are. | The last identifier path that trusts its input. The grammar work made the fix a one-liner; it was not a one-liner before. |
| **Double-submit CSRF (S-01)** | Bind the token to the session rather than to a second cookie. | A subdomain that can set cookies on the parent domain can currently set both halves. |
| **`unsafe-inline` CSP default (S-03)** | Default `CSP_ALLOW_UNSAFE_INLINE` to false and finish the nonce rollout. | The nonce machinery already exists; the default undoes it. |
| **Test factories + SQLite driver (D-05)** | A `factory()` helper and a SQLite connection driver for tests. | The one thing keeping testability at 8. It also gives the new grammars a second engine to run against in CI. |

### Substantial, and the right next investment

| Gap | What to do | Why it matters |
|---|---|---|
| **PostgreSQL connection driver** | ~200 lines: DSN, `lastInsertId` via sequences, `RETURNING`, error-code mapping. The grammar is done. | Turns portability from a claim into a fact, and the same work validates the seam for SQL Server and Oracle. |
| **Remaining inline backticks** | ~80 sites in `Concerns/HasAggregates`, `HasWhereConditions`, `HasBatchWrites`, `HasJoins`. | Each is a place a second engine would emit invalid SQL. Mechanical, but has to happen before a driver is useful. |
| **Schema grammar per engine** | The query grammar has five implementations; the schema grammar has one. Migrations are still MySQL DDL. | Portability without migrations is portability you cannot deploy. |
| **Job batching and chaining** | Laravel's `Bus::batch()` / `Bus::chain()` shape. | The remaining queue gap; supervision, unique jobs and graceful restart already landed. |
| **An interactive error page** | Whoops-style: stack frames, source context, request state, and the request id already in the log. | The single biggest DX difference from Laravel that is not ecosystem. |

### Worth stealing, cheap

| From | Idea |
|---|---|
| Laravel | `simplePaginate()` — one extra row instead of a `COUNT(*)`, which on a large table is most of the query cost. |
| Laravel | `each()` / `eachById()` over the existing `chunk`/`chunkById`. |
| Laravel | `incrementEach()` / `decrementEach()` — one statement instead of N. |
| Laravel | `whereJsonLength`, `whereJsonContainsKey`, `whereJsonDoesntContain`. |
| Laravel | `orHaving`, `havingNull`, `havingNotNull`. |
| Laravel | A `Rule` object so validation rules compose instead of concatenating strings. |
| Symfony | A `dump()` that renders nested structures collapsibly rather than through `print_r`. |

---

## 9. Things worth stealing

Concrete, in rough value order.

**Done on 2026-08-25**

| From | Idea | Outcome |
|---|---|---|
| Laravel | `unique:` / `exists:` validation rules | Landed, plus `unique_with:`. |
| Laravel Octane | `flush()` covering all container bindings | Landed — this was the fix for [W-01](10-audit-findings.md#w-01). |
| Laravel Octane | Single response emission point, no `exit` | Landed as `Core\Http\Emitter`. |
| Laravel Horizon | `queue:restart` for graceful reload | Landed, checked between jobs. |
| Laravel | `ShouldBeUnique` jobs | Landed as `Job::uniqueId()`. |

**Still worth taking**

| From | Idea | Effort | Why |
|---|---|---|---|
| Laravel Sanctum | Refresh-token rotation + reuse detection | M | [T-04](10-audit-findings.md#t-04). Table stakes for mobile — the largest remaining gap. |
| Laravel | HTTP test suite (`$this->getJson(...)->assertStatus()`) | M | [QA-02](10-audit-findings.md#qa-02). Now unblocked: responses no longer `exit`, so the kernel can be driven from a test. Would have caught every regression found in today's review. |
| Laravel | `JsonResource` transformers | M | Decouples the API contract from column names ([M-8](11-api-mobile-readiness.md)). |
| Laravel | Policies (`authorize('update', $model)`) | M | Removes hand-written ownership checks — the root cause `DetectIdor` is patching. |
| Laravel | Signed URLs (`URL::temporarySignedRoute`) | S | Password resets, email verification, temp downloads — no new tables. |
| Laravel | `Bus::batch()` and job chaining | M | The rest of [Q-01](10-audit-findings.md#q-01). |
| Yii2 | Hierarchical RBAC with inheritance | M | Roles that extend roles; avoids permission-list duplication. |
| CakePHP | `bake` — schema-driven full CRUD scaffolding | L | Turns `make:controller` into a real accelerator. |
| CI4 | Typed config classes instead of arrays | M | Kills the two config styles and gives IDE autocompletion. |
| FastRoute | Chunked-alternation route compilation | S | [R-04](10-audit-findings.md#r-04). |
| Symfony | RFC 9457 problem-details error format | S | One parse path for mobile clients. Cheap now that all three abort shapes go through `Core\Http\Abort`. |
