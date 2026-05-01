# 01. Runtime & Architecture

## Request Entry

- `bootstrap.php` runs before request capture and loads env, config, helpers, and database bootstrap.
- Web requests enter through `index.php`.
- `index.php` delegates pre-kernel maintenance handling to `maintenance()->handleRequest()` before request capture.
- If maintenance mode is active, `Components\Maintenance` can issue a bypass cookie, redirect to a configured URL, or render a 503 response before the kernel runs.
- Otherwise `index.php` creates `Core\Http\Request::capture()` then calls `App\Http\Kernel::handle()`.
- `App\Http\Kernel` creates `Core\Routing\Router`, applies middleware aliases/groups from `framework.php`, maps routes via `RouteServiceProvider`, then dispatches.

## Bootstrap Runtime Rules

- `BASE_URL` is resolved from `APP_URL` when present, otherwise from the current request host/proxy headers.
- In CLI or other non-HTTP contexts with no host header, bootstrap falls back to `http://localhost/{project}/`.
- Bootstrap exposes `BOOTSTRAP_RUNTIME` with `web`, `api`, or `cli`.
- Session startup is conditional via `framework.bootstrap.session`.
- Default behavior is stateful browser bootstrap, stateless API/mobile bootstrap, and no session for CLI.
- `BOOTSTRAP_SESSION_ENABLED` is defined so downstream code can detect whether session boot happened for the current runtime.
- `BOOTSTRAP_STATEFUL_REQUEST` mirrors whether the current request is running with bootstrap-managed session state.
- Internal startup phases are explicitly split into config load, environment configuration, runtime initialization, session initialization, helper load, and systems bootstrap.

## Response Shape Rules (Kernel)

- Controller/route return `array` => automatically sent as JSON via `Core\Http\Response::json`.
- Return `string` => echoed as HTML/text.
- Return `null` => assumed already handled (redirect/render/json already sent).

## Route Loading Model

- `RouteServiceProvider::map()` loads both web and API route files on every request.
- Web routes are wrapped by middleware group `framework.middleware_groups.web`.
- API routes are wrapped by middleware group `framework.middleware_groups.api`.
- Named routes are registered as soon as `->name(...)` is called, so helpers like `route()` and menu rendering can resolve route URLs before `Router::dispatch()` runs.

## Controller Base Pattern

- Generated controllers extend `Core\Http\Controller` (`make:controller` template).
- App routes can use callable closures, `Class@method`, or `[Class::class, 'method']` actions.

## Request Lifecycle Example

```text
Browser Request
	-> index.php
	-> Components\Maintenance (optional short-circuit)
	-> Core\Http\Request::capture()
	-> App\Http\Kernel::handle()
	-> Router + Middleware Pipeline
	-> Controller Action
	-> array|string|null response handling
```

## How To Use

1. Start all feature analysis from this flow.
2. Decide whether your endpoint should return array (JSON) or view/string.
3. Add middleware/groups in route definition, not in random controller code.
4. Keep business logic in controllers/services and let Kernel/Router handle transport.

## What To Avoid

- Avoid bypassing Kernel by manually including controllers.
- Avoid returning mixed inconsistent response shapes from the same endpoint.
- Avoid assuming API routes are loaded separately; web and API route files are both mapped.

## Benefits

- Predictable request handling path for debugging.
- Cleaner separation of concerns (entry, routing, middleware, action, response).
- Easier onboarding for new developers and AI agents.

## Evidence

- `bootstrap.php`
- `index.php`
- `systems/app.php`
- `app/http/Kernel.php`
- `systems/Core/Routing/RouteServiceProvider.php`
- `systems/Core/Routing/RouteDefinition.php`
- `systems/hooks.php`
- `systems/Core/Console/Commands.php` (`make:controller`)
