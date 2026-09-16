# 30 — What To Do Next

**Verified:** 2026-09-17 · **1,693 tests** passing (file order and random order) ·
PHPStan level 2 clean · 2 runtime Composer dependencies

This replaces the five-phase plan written on 2026-08-25. That plan is done or
overtaken: Phase 0 was decided (worker disabled by default behind
`MYTH_EXPERIMENTAL_WORKER`), Phase 1's safety holes are closed — including 1.3,
`select()` escaping, which was the last one — and most of Phases 2–4 landed
across six subsequent passes. What follows is only what is genuinely still open,
checked against the source on the date above.

**Ordering principle, unchanged:** safety → correctness → the enablers that make
everything after cheaper → capability → polish.

---

## Front-end validation — added 2026-09-17

`public/general/js/validation.js` now has full rule parity with the PHP validator
in the PHP → JS direction (94 rules). **The reverse direction is still open and
matters more:** 13 rules run only in the browser, so the user is stopped but a
mobile client, curl or a replayed request is not checked at all. The list is in
[06-validation.md](06-validation.md#the-parity-table); each is an afternoon on
the PHP side.

**Reviewed 2026-09-17** (the remaining ~7,750 lines): `helper.js`,
`classes/ModalManager.js`, `classes/BootstrapDataTable.js`, `notification.js`.
One reflected-XSS sink, one always-throwing `destroy()`, one pair of colliding
panel ids and eight unescaped interpolations, all fixed — see
[10-audit-findings.md](10-audit-findings.md#fixed-on-2026-09-17-ninth).
`BootstrapDataTable.js` was already careful and was left alone.

---

## The one-line summary

CSRF session binding (**S-01**) closed on 2026-09-17. What is left, in order:

1. **Write the PostgreSQL connection driver** — the grammars are done and tested;
   this is what turns portability from a claim into a fact, and it is the same
   work that validates the seam for SQL Server and Oracle.
2. **Refresh tokens** — the largest remaining gap for the mobile clients this
   framework exists to serve.
3. **Retire the CSP `unsafe-inline` default** — the nonce machinery works; the
   shipped defaults undo it.

Everything else can wait behind those.

---

## 1. Security — what is actually still open

Three items, all hardening. **[S-01]**, the one with a realistic attack path, was
closed on 2026-09-17: the expected CSRF token now comes from the session, and
`CsrfSessionBindingTest` proves a forged cookie pair fails.

### 1.2 CSP ships with `unsafe-inline` · **[S-03]** · high value, ~a day

`app/config/security.php:6` — `CSP_ALLOW_UNSAFE_INLINE` defaults to `true`, and
`CSP_NONCE_ENABLED` defaults to `false`. The nonce machinery exists, works, and
is tested; the defaults undo it, which makes the shipped CSP decorative against
the attack it is for.

**Do:** flip both defaults, then fix what breaks. The blocker is the bundled
views: inline `<script>` blocks, inline styles, and `onclick=` attributes in the
datatable action columns (`mapUserDatatableRow()` and its three siblings each
emit `onclick='editRecord(...)'`). Inline event handlers cannot be nonced —
they have to become delegated listeners bound by class.

**Sequence:** convert the four `map*DatatableRow()` methods to emit
`data-action` / `data-id` attributes with a single delegated handler per page,
*then* flip the defaults. Doing it the other way round breaks every table.

### 1.3 Encryptor requires AES-NI · **[S-05]** · medium value, ~half a day

`Core\Security\Encryptor` derives its key with BLAKE2b and encrypts with
AES-256-GCM. On a host without AES-NI that is both slow and, in principle,
timing-variable. XChaCha20-Poly1305 is the constant-time fallback.

**Do:** detect AES-NI, prefer AES-GCM where it is present, fall back to
XChaCha20-Poly1305 where it is not, and version the ciphertext envelope so
existing values keep decrypting.

**Do not:** change the envelope without a version byte. Every encrypted column
in every deployment has to keep opening.

### 1.4 No CSP on the API surface · low value, ~an hour

`SetSecurityHeaders` is in the global middleware list, so API responses do carry
the headers. A JSON response does not execute script, so the practical value is
limited to defence in depth — but `Content-Security-Policy: default-src 'none'`
on API routes costs nothing and closes the "attacker gets the browser to render
an API response as HTML" path.

---

## 2. Database portability — from grammars to drivers

The query grammar carries fourteen dialect decisions across five engines, all
tested. What is missing is the plumbing on either side of it.

### 2.1 PostgreSQL connection driver · high value, ~2 days

`DriverRegistry` registers `pgsql` with a grammar and an empty driver class, so
`resolveClass('pgsql')` refuses with a message saying exactly that.
`Core\Database\Dsn` already builds the connection string.

**Do:** a `PostgresDriver` alongside `MySQLDriver`. The real work is four things:
`lastInsertId()` via sequences rather than a magic value, `RETURNING` support
(the grammar already advertises it), SQLSTATE-based error classification instead
of MySQL's numeric codes, and `SET search_path` where a schema is configured.

**Why first:** it is the shortest path from "portable in principle" to "runs on a
second engine", and everything it exposes about the seam applies to SQL Server
and Oracle too.

### 2.2 The write paths still emit backticks · high value, ~a day

The `SELECT`, `INSERT`, `JOIN` and `WHERE` builders route through
`wrapIdentifier()`. The write and maintenance paths do not. **~35 sites remain**,
concentrated in:

| Location | What |
|---|---|
| `BaseDatabase.php:3608–3696` | `UPDATE` — `SET` list and table name |
| `BaseDatabase.php:3828–3837` | soft delete |
| `BaseDatabase.php:4072–4104` | `DELETE` and `TRUNCATE` |
| `BaseDatabase.php:1882` | index hints (`USE INDEX` is MySQL-only syntax anyway) |
| `BaseDatabase.php:4581` | wildcard expansion for eager loads |
| `BaseDatabase.php:4683` | `ANALYZE TABLE` |
| `HasAggregates.php` | ~10 sites in the aggregate and ordering builders |

Mechanical, and it has to land before a second driver is useful — each one is a
place PostgreSQL emits a syntax error.

### 2.3 The schema grammar is still MySQL-only · high value, ~3 days

`Query/Grammars/` has five implementations. `Schema/Grammars/` has one. So
migrations emit MySQL DDL regardless of the connection, and portability you
cannot migrate is portability you cannot deploy.

**Do:** the same treatment the query grammar got — column types, index syntax,
foreign keys, and `ALTER` forms behind per-engine methods.

### 2.4 SQL Server and Oracle drivers · medium value, ~2 days each

After 2.1–2.3 these are largely mechanical. Both need `MERGE` for upsert (their
grammars already decline the trailing-clause form so the builder takes the
portable path), and Oracle needs `RETURNING … INTO` with an out-bound bind.

---

## 3. API and mobile

### 3.1 Refresh tokens · **[T-04]** · highest-value capability gap, ~3 days

The largest remaining gap for the clients this framework is built for. Today a
mobile app either holds a long-lived bearer token or re-prompts for a password,
and a stolen token cannot be revoked before it expires.

**Do:** a short-lived access token plus a long-lived rotating refresh token.
Rotate on every use, and **revoke the whole family on reuse detection** — a
refresh token presented twice means one of the two holders is an attacker, and
the only safe response is to invalidate both.

`TokenService` already has the device-limit machinery (`max_active_per_user`,
`revoke_oldest`) that the family model needs.

### 3.2 Signed URLs · medium value, ~half a day

There is no `signedUrl()` / `signedRoute()`. `APP_KEY` plus `hash_hmac` is about
80 lines, and it is the cheapest way to do password resets, email verification
and temporary file links without a table for each.

### 3.3 Job batching and chaining · medium value, ~3 days

The remaining queue gap. Supervision, unique jobs, graceful restart and retry
budgets all landed; `Bus::batch()` / `Bus::chain()` did not.

---

## 4. Testability and developer experience

### 4.1 SQLite driver and test factories · **[D-05]** · high value, ~2 days

The one thing keeping testability at 8 rather than 10, and it pays for itself
twice: a `factory()` helper removes the fixture boilerplate, and a SQLite
connection driver gives the five new grammars **a second engine to run against
in CI** — which is how the portability work stops being theoretical.

### 4.2 An interactive error page · medium value, ~2 days

The single biggest remaining DX difference from Laravel that is not ecosystem.
Stack frames, source context, request state, and the request id that
`LogContext` already puts in every log line.

### 4.3 Dynamic route matching is a linear scan · **[R-04]** · medium value, ~a day

Static routes are an O(1) hash lookup; dynamic ones are scanned in order. Fine at
this app's ~60 routes, a real cost at 500. Bucket by first segment and method
before the scan.

---

## 5. Laravel builder parity — small, self-contained

Each is an afternoon. None blocks anything.

| Method | Why |
|---|---|
| `simplePaginate()` | One extra row instead of `COUNT(*)`, which on a large table is most of the query cost |
| `each()` / `eachById()` | Thin wrappers over the `chunk`/`chunkById` that already exist (`Model::each()` exists; the builder has no equivalent) |
| `incrementEach()` / `decrementEach()` | One statement instead of N |
| `whereJsonLength`, `whereJsonContainsKey`, `whereJsonDoesntContain` | Only `whereJsonContains` ships |
| `orHaving`, `havingNull`, `havingNotNull` | The `having` family is thinner than the `where` family for no reason |
| A `Rule` object | So validation rules compose instead of concatenating strings |

---

## 6. Worker mode — the standing decision

Still gated behind `MYTH_EXPERIMENTAL_WORKER=1`, and it should stay there until
somebody load-proves it. The isolation work is done and tested (state flush,
`$_FILES` bridging, `$_SERVER` baseline, `exit`-free response path, and the
`csrf()` / `cache()` / `dispatch()` / flash-bag statics that six passes turned
up). What is missing is not code, it is evidence: a sustained multi-worker run
against a real workload, watching for cross-request leakage.

**Before ungating it:** run the full suite under the worker SAPI, not just under
PHP-FPM. Every leak found so far was a `static` that no unit test could see.

---

## What I would do first if there were only one week

| Day | Work |
|---|---|
| 1 | **2.2** — the ~35 remaining backtick sites. Mechanical, and it unblocks day 2. |
| 2–3 | **2.1** — the PostgreSQL driver. Portability stops being a claim. |
| 4 | **4.1** — SQLite driver, so CI runs the grammars against a second engine and days 1–3 stay honest. |
| 5 | **1.2** — convert the four `map*DatatableRow()` methods to delegated handlers, then flip the CSP defaults. |

That order is deliberate: the three that compound come first — backticks unblock
the driver, the driver proves the grammars, SQLite keeps them proven — and the
CSP work is self-contained enough to sit at the end.

---

## Definition of done, for anything on this page

- `composer test` green (file order **and** `--order-by=random`)
- `composer stan` clean at the configured level
- New behaviour covered by a test that would have failed before the change
- If it fixes a defect, the test names the defect, not the feature
- [10-audit-findings.md](10-audit-findings.md) updated in the same commit
