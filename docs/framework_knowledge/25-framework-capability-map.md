# 25. Framework Capability Map (Verified)

## End-to-End Capabilities

### Routing & HTTP
- Web + API routing with middleware groups, alias params, and regex constraints
- Explicit `OPTIONS` routes plus `any()` multi-verb registration including `OPTIONS`
- Automatic `OPTIONS` preflight fallback (`204` + `Allow`) when path exists on other methods
- FormRequest auto-validation and typed controller injection via Router reflection
- Request capture with JSON body merge, trusted proxy IP resolution, browser/platform detection
- Response with JSON output and redirect (header injection prevention)

### Authentication & Authorization
- Session auth (attempt, login, logout) + Bearer token auth (create, revoke, abilities)
- Unified `RequireAuth` middleware with guard modes: `session`, `token`, `web`, `api`
- Social login (OAuth) via `socialite()` with auto-create callback
- RBAC permission middleware plus controller-level `can()` / `cannot()` helpers for conditional UI and explicit controller checks

### Middleware & Security
- Security headers (CSP, Permissions-Policy) via config
- Request hardening middleware for URI/body/host/content-type constraints
- Standard rate limiting with named profiles, numeric syntax, 6 scope modes
- Aggressive IP-based throttling with temporary/permanent blocking
- XSS pattern detection on state-changing requests with field exclusion
- API request/response logging with sensitive field masking
- CSRF protection with include/exclude URI patterns and optional Origin/Referer checks
- Route-level response cache-control middleware support

### Validation (40+ built-in rules)
- Type, format, size, comparison, date, security, file, array, and conditional rules
- Custom rules via `addRule()`, validation hooks, batch validation
- XSS detection, SQL injection detection, filename/extension security rules

### Database Query Builder (100+ methods)
- Full CRUD with fluent chainable API
- **Two-layer mass-assignment guard:** schema-level column filter (always on) + `$fillable` allowlist + `$guarded` denylist; runtime setters `setFillable()`/`setGuarded()` for ad-hoc scoping
- Where clauses: basic, or, not, between, in, null, date, JSON, fulltext, raw, nested
- Large `IN` / `NOT IN` lists are chunked automatically; integer raw variants exist for trusted ID lists
- Joins: inner, left, right, cross, lateral, subquery
- Eager loading: `with`, `withOne`, `withCount`, `withSum`, `withAvg`, `withMin`, `withMax`
- Aggregates, pagination, cursor iteration, adaptive chunked streaming, streamed JSON responses, union, subselect
- Soft deletes, transactions, connection management
- Iterable batch writes for inserts, updates, upserts, and deletes with progress callbacks
- Debug inspection via `toSql()`, `toRawSql()`, `toDebugSql()`, and `toDebugSnapshot()` in CLI/debug mode
- Performance tracking via `getPerformanceReport()` plus adaptive chunk-decision reporting and redacted slow-query logging

### Controller Base
- Abstract base with CRUD helpers: `findOrFail`, `restoreByEncodedId`, `destroyByEncodedId`
- Page-state helper via `setPageState()` for menu and breadcrumb context
- Auth shortcuts: `authId()`, `authUser()`, `can()`, `cannot()`
- View rendering, JSON response, redirect helpers

### View Engine
- Blade-like compiled templates with caching
- Directives: `@if`, `@foreach`, `@extends`, `@section`, `@yield`, `@include`, `@csrf`, `@method`, `@auth`, `@guest`, `@can`, `@switch`, `@php`, `@json`, `@push`, `@stack`
- Raw PHP helper: `view()` for compiled, `view_raw()` for direct include

### Cache System
- Drivers: `file` (persistent, 0750 dirs), `array` (request-scoped), `apcu` (shared memory, degrades to file), `redis` (via ext-redis, degrades to file)
- **Atomic operations** — APCu: `apcu_fetch($k, $success)` out-param eliminates TOCTOU + correctly stores `false` values; Redis: `INCRBY`/`SET NX`/`SETEX` (safe on TTL=0 via `SET`); File: flock + temp+rename
- `remember()` uses atomic `add()` (SET NX) — two concurrent callers cannot both compute the expensive callback
- Operations: get, put, forever, remember, rememberForever, pull, add, many, putMany, increment, decrement, forget, flush, has, missing, getMetadata
- Store selection: `cache()->store('name')`

### Queue System
- Drivers: `database` (SELECT…FOR UPDATE SKIP LOCKED), `redis` (sorted-set delays + Lua atomic migration), `sync`
- Job model with `handle()`, per-job queue name and delay; `fromPayload()` has `allowed_classes` guard + class-mismatch check
- Worker: **signal-aware sleep** — `usleep(100 ms)` loop with `pcntl_signal_dispatch()` — SIGTERM handled within 100 ms
- Operations: work, retry, failed, flush, clear; Redis: RPOPLPUSH reserve

### Scheduler (30+ frequency methods)
- Fluent cron scheduling: every minute to yearly, with day/time/timezone constraints
- Overlap prevention, conditional execution, environment filtering
- Lifecycle hooks: before, after, onSuccess, onFailure
- Output capture: sendOutputTo, appendOutputTo

### Console CLI (`php myth`)
- 35 built-in commands: cache, routes, generators, security/performance, backup, app runtime, queue, scheduler (full list in [19-console-built-in-commands.md](19-console-built-in-commands.md))
- Custom command registration via `make:command` + `console.php`

### File Upload
- Upload with MIME validation, size limits (megabytes), blocked extension enforcement
- Auto-generated unique filenames, organized response structure

### TaskRunner
- Parallel shell execution with concurrency control
- Process timeout (default 10 hours), deadlock resolution, logging

### Backup System
- Database backup with mysqldump search/fallback
- File backup with cleanup and retention management

### Frontend JavaScript (90+ functions)
- API wrappers: login, submit, delete, call, upload with token management
- PHP-style helpers: isset, empty, in_array, array_merge, implode, explode
- Date/time formatting, currency formatting
- DataTable generators plus `BootstrapDataTable` as the standard CRUD table abstraction with local row sync/remove helpers, multi-row local delete support, and server-side footer-count tracking from the last API response
- File preview (PDF, image, video, audio, Office docs)
- UI utilities: modals, loading states, skeletons, notifications

### Schema Builder & Migration
- Fluent API: `Schema::create()`, `Schema::table()`, `Schema::drop()`, `Schema::rename()`, `Schema::truncate()`
- 30+ column types: integer, string, text, date, boolean, enum, set, json, uuid, binary, blob
- Column modifiers: nullable, unsigned, default, useCurrent, after, first, comment, generated columns
- Index management: primary, unique, index, fulltext, spatial + drop variants
- Foreign keys: explicit `foreign()->references()->on()` and shorthand `foreignId()->constrained()`
- Stored procedures, functions, triggers, views (create + drop)
- Introspection: hasTable, hasColumn, getColumnListing
- Preview/dry-run DDL without executing
- Multi-driver grammar system (MySQL/MariaDB implemented, extensible to PostgreSQL/SQLite)
- Migration base class with up()/down() contract

### Global Helper Functions (20)
- `getProjectBaseUrl`, `config`, `debug`, `logger`, `request`, `view`, `view_raw`, `auth`, `validator`, `csrf`, `csrf_field`, `csrf_value`, `collect`, `cache`, `dispatch`, and more

## Documentation Policy

- This folder documents only code-implemented behavior.
- Non-features and limits are tracked in `10-known-limits.md`.
- When code changes, update docs in the same PR.

## How To Use This Capability Map

1. Start here for architecture planning.
2. Jump to section-specific docs for implementation details/examples.
3. Check `10-known-limits.md` before finalizing technical approach.

## What To Avoid

- Avoid treating this map as a replacement for section-level details.
- Avoid adding capabilities to this list without source-code verification.

## Benefits

- Quick decision support for architects and implementers.
- Shared baseline for AI agent prompts and junior onboarding.
- Reduces mismatch between "documented" and "implemented" features.

## Evidence Root

- `systems/` (core framework)
- `app/config/` (configuration)
- `app/routes/` (web, api, console)
- `app/http/middleware/` (10 middleware classes)
- `public/general/js/helper.js` (3418 lines)
