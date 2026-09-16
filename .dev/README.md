# `.dev` — MythPHP Engineering Knowledge Base

Ground truth for humans and AI agents working on this framework.
Everything here was verified by reading the source on **2026-08-26** against branch `database-update`,
at **1,662 tests** passing and PHPStan level 2 clean.

**Start at [30-roadmap.md](30-roadmap.md)** if you are picking up work — it lists only what is
still open, in priority order, with the file and line numbers checked on that date.

> **Precedence rule.** When `.dev/` disagrees with `docs/`, `README.md`, or `.github/skills/`,
> `.dev/` wins. Those older sets contain stale paths and at least one factually wrong
> statement (see [01-agent-brief.md](01-agent-brief.md#known-stale-documentation)).

---

## Read in this order

| # | File | Read it when |
|---|------|--------------|
| 01 | [agent-brief.md](01-agent-brief.md) | **Always first.** What this framework is, its invariants, and the traps. |
| 02 | [project-map.md](02-project-map.md) | You need to find where something lives. |
| 03 | [request-lifecycle.md](03-request-lifecycle.md) | You are touching boot, routing, middleware, or response emission. |
| 04 | [subsystems.md](04-subsystems.md) | You are working inside DB / auth / cache / session / security / views / console / queue / backup. |
| 05 | [conventions.md](05-conventions.md) | You are adding a route, controller, middleware, migration, command, or test. |
| 06 | [validation.md](06-validation.md) | You are adding or changing a validation rule. Covers both validators, how they dispatch, and where they disagree. |
| 07 | [telemetry.md](07-telemetry.md) | You are debugging a request, or adding a source to the debug bar. Covers the gate, redaction, the caps, and the storage trap. |
| 10 | [audit-findings.md](10-audit-findings.md) | **The bug register.** Severity-ranked, with file:line and fix. |
| 11 | [api-mobile-readiness.md](11-api-mobile-readiness.md) | You are building or hardening the mobile/API surface. |
| 20 | [framework-comparison.md](20-framework-comparison.md) | You need to justify a design decision against Laravel 12 / CI3 / CI4 / CakePHP 5 / Yii2. |
| 30 | [roadmap.md](30-roadmap.md) | You are planning work. What is still open, in priority order, verified against source. |

**Already fixed (2026-08-25) — 27 findings + 1 partial, all three P0s.** Headlines:

- **HTTP** — one emission point (`Core\Http\Emitter`); `exit` gone from the request path; all
  21 middleware share three `Abort` helpers instead of hand-rolling content negotiation.
- **Worker** — full per-request state isolation, native `$_FILES` bridging, `$_SERVER` baseline.
- **Database** — JOIN identifier injection closed; MariaDB bulk writes were **silently
  discarding every row**; MariaDB had **no statement timeout at all**; deadlock retry widened
  with key-ordered locking; per-chunk GC removed (33% faster streaming, same memory).
- **Auth** — device limits now cover API tokens, not just browser sessions.
- **Uploads** — size ceiling, image-decode proof, decompression-bomb guard, explained errors.
- **Queue** — `queue:restart`, unique jobs, `queue:supervise` for parallel workers.
- **Testing** — an end-to-end suite that drives the real kernel; it found 4 real bugs in its
  first two runs.

Details in [audit-findings.md § Fixed](10-audit-findings.md#fixed-on-2026-08-25).

> **Running the checks.** `composer test` and `composer stan`. PHPStan needs
> `--memory-limit=2G` — the 512M default crashes mid-analysis on a cold cache and prints
> dozens of phantom "return statement is missing" errors.

---

## Repository facts (measured, not estimated)

| Metric | Value | How measured |
|---|---|---|
| PHP files (excl. `vendor/`) | 512 | `find . -name '*.php' -not -path './vendor/*'` |
| Total LOC | 112,268 | `cat` + `wc -l` over the above |
| `systems/` (the framework) | 67,854 LOC | 3 namespaces: `Core\`, `Components\`, `Middleware\` |
| `app/` (the demo application) | 18,917 LOC | |
| Runtime Composer deps | **2** (`phpmailer`, `google/apiclient`) | `composer.json` |
| PHP requirement | `>=8.2` (tested on 8.3.8) | `composer.json` |
| Tests | **1,204 passing** (1,182 unit + 22 end-to-end), 2422 assertions, 17 skipped (was 693 at the start) | `composer test` |
| PHPStan | **level 2, zero errors** — needs `--memory-limit=2G`, see below | `composer stan` |
| Console commands | 66 | `php myth list` |
| Application middleware | 38 in `app/http/middleware/` + 2 in `systems/Middleware/` | |
| Migrations | 17 | `app/database/migrations/` |

### Measured baseline performance — treat as unusable

`php myth perf:benchmark --iterations=100 --routes=200` on PHP 8.3.8 NTS x86, no OPcache,
Windows. Two consecutive **identical** runs:

| Workload | Run 1 | Run 2 |
|---|---|---|
| Routing (200 routes) | 0.0263 ms/op | 0.0139 ms/op |
| Validation | 0.0372 ms/op | 0.0276 ms/op |
| Query | skipped | no DB configured locally |

**A 2× swing on unchanged code.** Without OPcache and on Windows this harness measures
scheduler noise as much as the framework, so no before/after claim should rest on it.
To get numbers worth quoting: a quiet Linux box, OPcache enabled, and enough iterations
for the variance to settle. Peak CLI memory was 32 MB in every run.

---

## Environment gaps on the current dev machine

`php -m` on `C:\laragon\bin\php\php-8.3.8-nts-Win32-vs16-x86` shows these are **missing**:

| Extension | What silently degrades without it |
|---|---|
| `opcache` | Every request re-parses ~100k LOC. This is the single biggest local slowdown. |
| `sodium` | `Core\Security\Encryptor` **throws on every call** — column encryption and blind indexes are unusable. |
| `apcu` | Rate limiter drops to the file-lock path; `ConnectionPool` and `QueryCache` lose their shared-memory tier. |
| `redis` | Cache and session fall back to file stores. |

Fix locally before benchmarking anything, or the numbers are meaningless.

---

## Regenerating this knowledge base

These docs are hand-verified, not generated. When the framework changes materially:

1. Re-run `php vendor/bin/phpunit` and `php vendor/bin/phpstan analyse` and update the counts above.
2. Re-run `php myth list` and update the command count.
3. Update the affected section file **and** the finding status in `10-audit-findings.md`.
4. Bump the "verified on" date at the top of the file you edited.
