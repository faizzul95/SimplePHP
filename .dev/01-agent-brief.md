# 01 — Agent Brief

**Verified:** 2026-08-25 · branch `database-update`
**Read this before touching any code in this repository.**

---

## 1. What this framework actually is

MythPHP is a **single-repository, zero-dependency-by-design PHP 8.2+ framework** that borrows
Laravel's *vocabulary* (`Kernel`, middleware groups, service providers, Blade, `myth` ≈ `artisan`,
FormRequest, migrations, Collection) while implementing everything itself in `systems/`.

Two Composer packages ship at runtime — `phpmailer/phpmailer` and `google/apiclient`. Nothing else.
There is no Symfony HttpFoundation, no PSR-7, no Illuminate, no DI container library.

**The design goal is small deployable size and low per-request cost, not framework purity.**
Judge changes against that: a change that adds a dependency or a layer of indirection needs to
buy a measurable win.

### The three namespaces in `systems/`

| Namespace | Path | Role | Verdict |
|---|---|---|---|
| `Core\` | `systems/Core/` | The real framework. Modern, typed, mostly `declare(strict_types=1)`. | **Where new code goes.** |
| `Components\` | `systems/Components/` | Older god-objects: `Auth` (3,998 LOC), `Validation` (2,939), `Debug` (2,724), `Security` (1,230), `Request` (1,167). Loose typing, few type hints. | **Legacy. Strangle, don't extend.** |
| `Middleware\` | `systems/Middleware/` | Two shipped middleware + four shared traits. | Fine. |

`Core\` is roughly 2/3 of `systems/`; `Components\` is where nearly every open architectural
problem lives.

---

## 2. Non-negotiable invariants

Break one of these and something is quietly wrong.

1. **`db()` returns a per-connection singleton query builder, not a fresh one.**
   `db()->table('x')` calls `reset()` internally. If you hold a `db()` reference across a
   `table()` call, your `where`/`binds`/`joins` are gone. Always chain from `db()->table(...)`.

2. **`Response::json()` and `Response::redirect()` call `exit`.** They do not return.
   Code after them is unreachable, and middleware post-`next()` blocks never run.
   In controllers, use `jsonResponse()` (throws `Core\Http\ResponseEmitted`) instead —
   it unwinds the middleware stack properly. See finding **R-01**.

3. **`config()` is read-only at runtime and is flattened dot-notation over `$config`.**
   Config files in `app/config/` either `return [...]` (keyed by filename) *or* assign
   `$config['key'] = [...]` directly. Both styles are in use. `loadConfig()` handles both.

4. **Service resolution is `framework_service('name')` — a hand-rolled singleton registry
   in `systems/hooks.php:286`.** There is no autowiring container. Constructor injection
   only works for `Core\Http\Request`, `FormRequest` subclasses, and `Core\Database\Model`
   subclasses in controller actions (see `Router::invokeCallable()`).

5. **Route params, `Request`, and `FormRequest` are resolved by Reflection on every request.**
   No compiled action cache. Adding heavy reflection to the hot path is expensive.

6. **Migrations are anonymous classes returned from a file** (`return new class extends Migration`),
   never named classes. Filename pattern: `YYYYMMDD_NNN_snake_description.php`.

7. **Two `Request` classes exist.** `Core\Http\Request` (the real one, passed to middleware and
   actions) and `Components\Request` (legacy, reachable via the `request()` helper). They are
   unrelated. New code uses `Core\Http\Request`. See finding **A-07**.

8. **`app/` is a demo application, not framework code.** Anything an application would replace
   (controllers, views, routes, models, most middleware) lives there. But note the boundary
   leaks: 38 middleware and the `Kernel` live in `app/`, so `app/` is not actually disposable
   today. See finding **A-01**.

---

## 3. Traps that have already bitten

| Trap | Why it bites |
|---|---|
| Worker mode (RoadRunner / FrankenPHP) is **disabled**. | Both entry points require `MYTH_EXPERIMENTAL_WORKER=1`. `WorkerState::flush()` still misses the `auth`, `csrf`, `logger` and `security` singletons and never touches the session, so identity leaks between requests. Do not lift the gate. **W-01.** |
| `validateColumn()` only checks "is a non-empty string". | It reads like a validator; it is not. JOIN builders now go through `quoteIdentifier()` instead (**D-01 fixed**), but `select()` still passes expressions through — **D-02** is open. |
| The XSS middleware **logs but does not block** by default. | `security.xss_input_blocking` defaults to `false`. This is deliberate and documented in the source, but people assume it blocks. |
| CSRF uses **double-submit cookie**, not session-bound tokens. | A token stolen or planted via a sibling subdomain is accepted. **Finding S-01.** |
| `unique:` / `exists:` are a UX affordance, not the constraint. | They are a check-then-act race: two concurrent requests can both pass. The DB UNIQUE index is what holds the invariant — see migration `20260824_017`. Soft-deleted rows are counted, to match what the index does. |
| Runtime DDL is now off by default. | `auth.token.auto_migrate` gates `ensureTokenTable()`; the queue and credential tables use per-process guards. Turn it on only for scratch dev databases. |
| `last_used_at` is written at most once per `auth.token.last_used_precision` (60s). | Writing it per request turned every GET into a write and defeated read-replica routing. Set the precision to 0 to restore the old behaviour. |

---

## 4. Known stale documentation

`docs/` and `.github/skills/` were accurate when written and are not now.

| Source | Problem | Evidence |
|---|---|---|
| `.github/skills/myth-framework/SKILL.md` | States **"No model classes — always use `db()->table()`"**. Wrong: `Core\Database\Model` is a 1,868-line active-record ORM with observers, casts, soft deletes, bulk streaming, and route-model binding wired into the router. `app/models/User.php` uses it. | `systems/Core/Database/Model.php`, `Router.php:801` |
| `docs/framework-security-performance-comparison.md` | References `app/support/Auth/LoginPolicy.php` and `app/support/Auth/AccessCredentialService.php`. Both moved to `systems/Core/Auth/`. | `ls systems/Core/Auth` |
| `docs/framework_knowledge/*` | Numbering skips 16; predates the `app/console/Commands` → `app/console/commands` rename and the `app/Models` → `app/models` move visible in `git status`. | `git status` |
| `README.md` (114 KB) | Too large to be maintained as a single file and overlaps `docs/`. | |

**Recommendation:** treat `docs/` and the `myth-*` skills as deprecated. Either delete them or
rewrite them as thin pointers into `.dev/`. Keeping three parallel knowledge bases is how the
model-vs-no-model contradiction happened.

---

## 5. Quality baseline as of this audit

Good news first — this is a well-tested codebase for a bespoke framework:

- **776 unit tests pass** (693 before the 2026-08-25 fixes). Coverage is real, not smoke
  tests: 40+ files under `tests/Unit/Support`, 32 under `Database`, 24 under `Http`,
  15 under `Security`.
- **PHPStan is clean** — but at **level 2 of 10**. Level 2 catches unknown methods and basic
  type errors. It does not check `iterable` value types, missing return types, or nullability
  flow. Raising to level 5 is the cheapest quality win available (**Finding QA-01**).
- Security posture is genuinely above average for a custom framework: Argon2id at OWASP-2024
  parameters with a real dummy-hash timing defence, AEAD column encryption with blind indexes,
  CSP with nonce support and violation reporting, IP blocklisting, trusted-proxy CIDR matching,
  request fingerprinting, session concurrency limits, and an automated `security:audit` command.

The problems are concentrated in three places: **`Components\` god-objects**, **the
`exit`-based response model**, and **worker mode**.
