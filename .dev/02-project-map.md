# 02 — Project Map

**Verified:** 2026-08-25

Where everything lives and who owns it. Sizes are lines of code.

---

## Top level

| Path | Role |
|---|---|
| `index.php` | HTTP front controller. 25 lines: bootstrap → `maintenance()->handleRequest()` → `Request::capture()` → `Kernel::handle()`. |
| `bootstrap.php` | 21 KB of procedural boot. Constants, Composer autoload, `hooks.php`, config load, environment presets, session ini + start, service providers, helpers, `systems/app.php`. |
| `myth` | CLI front controller → `Core\Console\Kernel`. |
| `systems/` | **The framework** (64,948 LOC). |
| `app/` | The demo application (18,917 LOC). |
| `tests/` | 693 PHPUnit unit tests. |
| `docs/` | **Stale.** See `01-agent-brief.md §4`. |
| `.github/skills/` | 9 agent skill files. Also stale. |
| `public/` | Web assets (Sneat admin theme, uploads). |
| `storage/` | Runtime: `cache/views`, `cache/app`, `cache/query`, `cache/config.cache.php`. Gitignored. |
| `logs/` | Application + slow-query logs. Gitignored. |
| `#db/` | Raw SQL dumps, served by `db:seed:sql`. Blocked in `.htaccess` (as `%23db`). |
| `.rr.yaml`, `Caddyfile` | RoadRunner and FrankenPHP worker configs. **Do not use yet** — see finding W-01. |
| `tools/php.ini.production` | Recommended production PHP settings. |

---

## `systems/Core/` — the framework proper

### Database (`systems/Core/Database/`, ~15 files + 4 subdirs)

| File | LOC | Role |
|---|---|---|
| `BaseDatabase.php` | **4,415** | The query builder god-class. SELECT/INSERT/UPDATE/DELETE, pagination, transactions, batching, safe-output sanitising, profiling. |
| `Model.php` | 1,868 | Active-record ORM: casts, fillable/guarded, dirty tracking, observers, soft deletes, bulk streaming. |
| `Concerns/HasWhereConditions.php` | 1,162 | All `where*` variants. |
| `Concerns/HasStreaming.php` | 822 | `chunk`, `chunkById`, `cursor`, `lazy`. |
| `Concerns/HasAggregates.php` | 796 | `count/sum/avg/min/max`, `withCount`, `withSum`, ORDER BY / HAVING sanitising. |
| `Drivers/MySQLDriver.php` / `MariaDBDriver.php` | 821 / 610 | Dialect specialisations. |
| `Schema/` | 7 files | `Blueprint` (829), `MigrationRunner` (638), `Schema` (618), `Grammars/MySQLGrammar` (815). |
| `Interface/` | 7 files | Builder contracts, incl. `BuilderStatementInterface` (853). |
| `ConnectionPool.php` | 615 | Static PDO pool with APCu metadata. |
| `QueryCache.php` | 626 | Tag- and table-version-aware result cache. |
| `PerformanceMonitor.php` / `SlowQueryLogger.php` | 837 / 217 | Query profiling. |
| `EagerLoadOptimizer.php` / `Concerns/HasEagerLoading.php` | 363 / 334 | N+1 avoidance. |
| `StatementCache.php` | 436 | Prepared-statement reuse. |
| `QueryAllowlist.php` | 107 | Optional raw-query allowlist. |

### HTTP (`systems/Core/Http/`)

| File | LOC | Role |
|---|---|---|
| `FormRequest.php` | 1,383 | Laravel-style validated request objects. |
| `Request.php` | 676 | **The** request object. IP resolution with trusted-proxy CIDR, XSS heuristics, attributes bag. |
| `Controller.php` | 452 | Base controller: `view()`, `jsonResponse()`, `successResponse()`, `findOrFail()`, `authorizeOrFail()`, encoded-ID helpers. |
| `Response.php` | 313 | Static emitter. Cache headers, ETag, redirects, Link preload. **Calls `exit`.** |
| `ResponseCache.php` | 206 | Full-page response cache. |
| `ResponseEmitted.php` | 33 | Exception carrying a controller response out through the middleware stack. |
| `Middleware/Pipeline.php` | 20 | `array_reduce` onion. |
| `Redirector.php`, `RedirectResponse.php`, `CookieFactory.php`, `BinaryFileResponse.php`, `StreamedResponse.php`, `HtmlResponse.php`, `ResponseFactory.php`, `SecureResourceAccess.php`, `ValidationException.php` | | Supporting response types. |

### Routing (`systems/Core/Routing/`)

| File | LOC | Role |
|---|---|---|
| `Router.php` | 1,134 | Registration, grouping, static+dynamic index, middleware resolution, dispatch, error handling, route cache serialisation, named-route URL generation. |
| `RouteDefinition.php` | 186 | Fluent per-route API: `->name()`, `->middleware()`, `->permission()`, `->webAuth()`, `->guestOnly()`, `->featureFlag()`, `->where()`. |
| `RouteServiceProvider`-side wiring lives in `app/providers/RoutingServiceProvider.php`. | | |

### Auth (`systems/Core/Auth/`)

`AuthManager`, `AuthGuard`, `AuthMethodResolver`, `TokenService` (501), `AuthorizationService` (420),
`AccessCredentialService` (420), `LoginPolicy` (219).
These are the extracted-service half of the auth system; the other half is still inside
`Components\Auth`.

### Security (`systems/Core/Security/`)

`IpBlocklist` (432), `AuditLogger` (273), `FileUploadGuard` (214), `Encryptor` (165, AES-256-GCM
via libsodium + blind index), `Hasher` (105, Argon2id), `RateLimiter` (124),
`CspViolationLogger` (123), `PwnedPasswordChecker` (107, k-anonymity HIBP), `CspNonce`.

### Everything else

| Dir | Contents |
|---|---|
| `Cache/` | `CacheManager` (274) + `FileStore`, `ApcuStore`, `ArrayStore`, `RedisDriver`. |
| `Session/` | `SessionBootstrapper` (ini + handler wiring), `RedisSessionHandler` (165, with locking). |
| `View/` | `BladeEngine` (1,198) — the whole template engine, one file. |
| `Console/` | `Kernel` (784), `Commands` (2,602 — every built-in command), `Schedule` (284), `ScheduleEvent` (823), `Command`, `Myth`, `Commands/{Config,Route,View}*Cache`. |
| `Queue/` | `Worker` (476), `Dispatcher` (232), `RedisQueue` (225), `Job` (172). |
| `Filesystem/` | `LocalFilesystemAdapter` (519), `StorageManager` (111). |
| `Events/` | 5 files. |
| `Exceptions/` | `ExceptionHandler` (1,254). |
| `Server/` | `RoadRunnerWorker` (115), `WorkerState`, `preload.php`. |
| `Support/` | `HttpClient` (535). |
| `Diagnostics/` | `MemoryProfiler` (117). |
| `Assets/` | `AssetIntegrity` (114, SRI hashes). |
| `Collection.php` / `LazyCollection.php` | 1,048 / 579. |

---

## `systems/Components/` — legacy layer

| File | LOC | Status |
|---|---|---|
| `Auth.php` | **3,998** | Session/token/JWT/OAuth2/API-key/Basic/Digest auth, RBAC, session registry, login policy, audit. Partially delegated to `Core\Auth\*`; the delegation is incomplete, so both halves must be read together. |
| `Validation.php` | 2,939 | 70 rules. No DB rules. |
| `Debug.php` | 2,724 | Dev-only dumper/profiler. |
| `Security.php` | 1,230 | XSS/SQLi pattern blocklists, sanitisers. |
| `Request.php` | 1,167 | **Duplicate** of `Core\Http\Request`. |
| `Files.php` | 1,403 | Upload handling. |
| `MenuManager.php` | 1,079 | Menu tree + access filtering. |
| `Backup.php` | 986 | mysqldump-or-PHP-fallback DB dump + file zip. |
| `CSRF.php` | 677 | Double-submit cookie CSRF. |
| `TaskRunner.php` | 509 | Background task helper. |
| `Logger.php`, `Maintenance.php`, `FeatureManager.php`, `Input.php`, `HTML.php` | 372–137 | |

---

## `app/` — the demo application

```
app/
├── config/          15 files — api, app, auth, cache, config, database, filesystems,
│                    framework, menu, queue, security, session, ...
├── console/
│   ├── commands/    13 app-specific commands (blocklist:*, db:*, csp:report,
│   │                security:audit, profile:memory, about, ...)
│   └── concerns/    InteractsWithIpBlocklist
├── database/
│   ├── migrations/  17
│   └── seeders/     4
├── helpers/         10 procedural helper files, auto-loaded and cached
├── http/
│   ├── Kernel.php   50 lines — builds Router, applies aliases/groups/global, dispatches
│   ├── controllers/ 8 + scopecontrollers/
│   ├── middleware/  38
│   └── requests/    6 FormRequests
├── models/          User.php (the only model)
├── providers/       14 service providers
├── routes/          web.php, api.php, console.php, api/*.php (6 files)
├── support/         DatabaseRuntime, EventDispatcher, SecurityAuditRunner (826),
│                    QueryAllowlistAudit, Filesystem/{S3,GoogleDrive,Scaffolded}Adapter
└── views/           Blade-ish templates (auth, dashboard, directory, rbac, errors, _templates)
```

### Service providers and when they load

Configured in `app/config/framework.php` under `framework.providers`, split by runtime
(`bootstrapRuntime()` returns `cli`, `api`, or `web`):

| Group | Providers |
|---|---|
| `always` | App, Log, Database, Cache, Security, Event, Maintenance, View |
| `web` | Filesystem, Response, Routing, Feature, **Auth** |
| `api` | **Auth**, Filesystem, Response, Routing, Feature |
| `cli` | Routing |

Note `ViewServiceProvider` is in `always`, so the Blade engine is constructed even for API and
CLI runs. Minor, but it is loaded work an API-first framework does not need.

---

## Config file inventory

| File | Owns |
|---|---|
| `framework.php` | Middleware aliases/groups/global stack, providers, route files, rate limiters, view paths, error views, upload guards, content-type profiles, maintenance, profiling. **The most important config file.** |
| `security.php` | CSRF, CSP, request hardening, trusted hosts/proxies, redirect allow-list, environment presets. |
| `auth.php` | Auth methods, table/column mapping, session security, login policy, JWT/OAuth2/API-key settings. |
| `database.php` | Per-environment connections with read/write splitting, sticky reads, SSL, profiling, slow-query, retry, query cache, pagination limits. |
| `api.php` | CORS, API auth methods, versioning (`/api/v1`). |
| `session.php` | Driver (file/redis), lifetime, Redis locking. |
| `cache.php` | Stores (file/array/redis), prefix. Default is **file**. |
| `queue.php`, `filesystems.php`, `menu.php`, `app.php`, `config.php` | |
