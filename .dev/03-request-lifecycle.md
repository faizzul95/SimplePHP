# 03 — Request Lifecycle

**Verified:** 2026-08-25

---

## 1. HTTP request, classic SAPI (mod_php / php-fpm)

```
Apache .htaccess  ─── rewrites everything not a real file/dir to
                      index.php?__route=$1
                      (also hard-denies dotfiles, .sql/.bak/.log/…, and
                       app|systems|storage|logs|db|tests|docs|tools|vendor/)
        │
        ▼
index.php
  require bootstrap.php                      ← see §2
  maintenance()->handleRequest()             ← 503 + bypass-secret cookie
  $request = Core\Http\Request::capture()
  dispatch_event('request.captured')
  (new App\Http\Kernel)->handle($request)
        │
        ▼
App\Http\Kernel::handle()                    app/http/Kernel.php
  MemoryProfiler::begin()
  $router = new Core\Routing\Router()
  $router->aliasMiddleware(config framework.middleware_aliases)
  $router->middlewareGroup(config framework.middleware_groups)
  $router->globalMiddleware(config framework.middleware_global)
  framework_service('route.provider')->map($request, $router)
        │  loads app/routes/web.php + api.php (or the route cache)
        ▼
  $router->dispatch($request)                systems/Core/Routing/Router.php:431
        │
        ├─ buildRouteIndex()   static routes → hash map; dynamic → compiled regex
        ├─ findRoute()         O(1) static hit, else LINEAR regex scan  ← finding R-04
        ├─ no match → 405 (with Allow:) / 404 / fallback route / redirect
        ├─ setRouteParams + setAttributes(route.uri, route.name, route.middleware)
        ├─ resolveMiddleware(global + route)
        │     expandMiddlewareGroups()  → recursive, cycle-guarded, array_unique
        │     normalizeOverridableMiddleware()  → last-one-wins for xss/content.type/origin.policy
        │     instantiate each, call setParameters() for `alias:arg,arg`
        ▼
  Pipeline::process()   array_reduce onion, systems/Core/Http/Middleware/Pipeline.php
        │
        ▼
  invokeAction() → invokeCallable()          Router.php:754
        Reflection over the action signature, resolving:
          Core\Http\Request        → the request
          FormRequest subclass     → new + setRequest + validateResolved()
          Core\Database\Model sub. → Model::findById($routeParam)  (404 + exit if null)
          matching route param name→ the raw string
          default value            → the default
          otherwise                → null
        ▼
  Controller action
        │
        ├─ jsonResponse()  → throws ResponseEmitted → caught in dispatch → returned to Kernel ✅
        ├─ Response::json()→ echoes + exit                                                    ⚠️
        ├─ $this->view()   → BladeEngine render → echo
        └─ return array    → Kernel does Response::json($result, $result['code'])
        ▼
  MemoryProfiler::end()  (finally block)
```

### Exception paths inside `dispatch()`

| Thrown | Handling |
|---|---|
| `Core\Http\ResponseEmitted` (inside the action) | Caught at the innermost point so every middleware's post-`next()` code still runs. |
| `Core\Http\ResponseEmitted` (from a middleware short-circuit) | Caught at the outer `try`. |
| `Core\Http\ValidationException` | JSON → `{code, message, errors}`; HTML 422 non-GET → `redirect()->back()->withErrors()->withInput()` with `password*`/`_token` stripped; else general error view. |
| `Throwable` | `logger()->logException()`, then 500 JSON or general error view. |

---

## 2. `bootstrap.php` in order

```
 1  define ROOT_DIR, APP_NAME, REDIRECT_LOGIN, REDIRECT_403, REDIRECT_404
 2  bootstrapLoadComposerAutoload()        vendor/autoload.php
 3  bootstrapLoadCoreHooks()               systems/hooks.php  (all global helpers)
 4  dispatch_event('boot.starting')
 5  bootstrapRegisterConsoleAlias()        class_alias(Core\Console\Myth, 'Myth')
 6  loadConfig()
       ├─ FAST PATH: storage/cache/config.cache.php if present  (php myth config:cache)
       └─ else glob app/config/*.php, natural-sort, include each,
          merging both `return [...]` and `$config[...] = [...]` styles
 7  dispatch_event('config.loaded')
 8  configureEnvironment()
       ├─ define ENVIRONMENT
       ├─ bootstrapApplyEnvironmentPresets()   security.presets[ENVIRONMENT] deep-merge
       ├─ bootstrapNormalizeSecurityConfig()   unify trusted.hosts / trusted.proxies
       └─ error_reporting + display_errors per environment
 9  initializeRuntime()
       ├─ bootstrapRuntime() → 'cli' | 'api' | 'web'
       │     api when the path matches #(?:^|/)api(?:/|$)#i
       │        OR any of Authorization / X-API-Key / PHP_AUTH_* is present   ← finding A-04
       ├─ initializeSession()
       │     shouldBootstrapSession(): true if a session cookie already exists,
       │     else per-runtime flags framework.bootstrap.session.{cli,api}
       │     → SessionBootstrapper::configure()  (gc_maxlifetime, save_path, redis handler)
       │     → cookie params: Secure, HttpOnly, SameSite, __Secure-/__Host- prefixing,
       │        use_strict_mode=1, use_only_cookies=1, sid_length 48 (PHP < 8.4)
       │     → session_start()
       │     → absolute lifetime enforcement (framework.session.absolute_lifetime, 8h default):
       │        wipe $_SESSION + session_regenerate_id(true)
       │     → periodic regeneration if sess_regenerate_destroy
       └─ define BASE_URL, APP_DIR, APP_ENV, TEMPLATE_DIR
10  bootstrapRegisterServiceProviders()    always + per-runtime group; register() then boot()
11  bootstrapInitializeHelpers()           loadHelperFiles() — app/helpers/*.php, cached list
12  bootstrapInitializeSystems()           systems/app.php — defines db()
```

**Cost note.** Steps 6–12 run on every request. `config:cache` collapses step 6 to one
`require`. There is no equivalent for step 11 beyond a discovered-file list cache, and no
compiled service-provider manifest.

---

## 3. Middleware groups

Declared in `app/config/framework.php`. Groups expand recursively.

| Group | Expands to |
|---|---|
| `web` | `session.stateful`, `headers`, `preload.assets`, `trusted.hosts`, `trusted.proxies`, `ip.blocklist`, `throttle:web`, `request.fingerprint`, `request.safety`, `origin.policy`, `menu.access`, `csrf` |
| `api` | `headers`, `trusted.hosts`, `trusted.proxies`, `ip.blocklist`, `throttle:api`, `content.type`, `request.fingerprint`, `request.safety`, `xss`, `api.log` |
| `api.public.submit` | `api` + `throttle:auth` |
| `api.external.auth` | `api` + `auth.api` |
| `api.app` | `api` + `origin.policy:strict`, `session.stateful:force`, `auth.web`, `csrf:force` |
| `api.upload.image` | `api.app` + `content.type:multipart`, `upload.guard:image-cropper` |
| `api.upload.action` | `api.app` + `upload.guard:delete` |

Global (prepended to every route regardless of group): `headers`, `trusted.hosts`,
`trusted.proxies`, `ip.blocklist`. These also appear inside `web`/`api`; `array_unique` in
`expandMiddlewareGroups()` collapses the duplicates.

**The two API surfaces are deliberately separate:**

- **External / machine API** (`api.external.auth`) — bearer token, no cookies, no CSRF.
  Routes: `/api/v1/auth/{login,me,logout,tokens/current,tokens/rotate}`.
- **Application API** (`api.app`) — cookie session + forced CSRF + strict origin. Used by the
  Sneat front-end's AJAX. Routes: `app/routes/api/{auth,dashboard,users,rbac_roles_permissions,
  email_templates,uploads}.php`.

CSRF is globally excluded for `api/*` in `security.csrf.csrf_exclude_uris` and re-enabled for
the app API via the `csrf:force` parameter. That is a correct design; just know both halves exist.

---

## 4. CLI lifecycle

```
php myth <cmd>
  └─ require bootstrap.php   (runtime = 'cli', session off, only RoutingServiceProvider)
  └─ Core\Console\Kernel::handle($argv)
       ├─ bootstrap(): Commands::register($this) + discoverClassCommands()
       │    scans app/console/commands/*.php, maps file → FQCN, instantiates, ->register()
       ├─ parseArguments()
       └─ run(): dispatch to built-in (Commands.php) or registered class command
```

`schedule:run` / `schedule:work` read `app/routes/console.php` via `Kernel::schedule()`.
`ScheduleEvent` spawns background processes with `popen('start /B ...')` on Windows and
`exec('... > /dev/null 2>&1 &')` on POSIX.

---

## 5. Worker lifecycle (RoadRunner / FrankenPHP) — **currently unsafe**

`systems/Core/Server/RoadRunnerWorker.php` bootstraps once, then loops:

```
WorkerState::flush()          resets CspNonce, AuditLogger, ConnectionPool,
                              PerformanceMonitor, QueryCache, BladeEngine,
                              and framework_service('database.runtime')
bridge PSR-7 → superglobals
ob_start()
Request::capture() + Kernel::handle()
collect headers_list(), header_remove()
respond
```

What is missing makes this **not production-safe**. See finding **W-01** for the full list;
the headline items are:

- `framework_service('auth')`, `'csrf'`, `'security'`, `'logger'`, `'feature'`,
  `'route.provider'` are **never reset** → the previous request's resolved user can be served
  to the next caller.
- Sessions are started once at boot and never rotated per request.
- `Core\Http\Request::$current` and `Response::$pendingLinkHeaders` statics are not reset.
- `$_FILES = $psrRequest->getUploadedFiles()` yields PSR-7 objects, not the `$_FILES` array
  shape the upload code expects.
- ~45 `exit`/`die` calls in the HTTP path terminate the worker process outright — every 404,
  405, CSRF failure, rate-limit rejection, and unauthenticated request costs a worker respawn.

**Do not enable `.rr.yaml` or the `Caddyfile` worker block until W-01 is closed.**
