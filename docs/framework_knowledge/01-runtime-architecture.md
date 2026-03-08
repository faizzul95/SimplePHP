# 01. Runtime & Architecture

## Request Entry

- Web requests enter through `index.php`.
- `index.php` creates `Core\Http\Request::capture()` then calls `App\Http\Kernel::handle()`.
- `App\Http\Kernel` creates `Core\Routing\Router`, applies middleware aliases/groups from `framework.php`, maps routes via `RouteServiceProvider`, then dispatches.

## Response Shape Rules (Kernel)

- Controller/route return `array` => automatically sent as JSON via `Core\Http\Response::json`.
- Return `string` => echoed as HTML/text.
- Return `null` => assumed already handled (redirect/render/json already sent).

## Route Loading Model

- `RouteServiceProvider::map()` loads both web and API route files on every request.
- Web routes are wrapped by middleware group `framework.middleware_groups.web`.
- API routes are wrapped by middleware group `framework.middleware_groups.api`.

## Controller Base Pattern

- Generated controllers extend `Core\Http\Controller` (`make:controller` template).
- App routes can use callable closures, `Class@method`, or `[Class::class, 'method']` actions.

## Request Lifecycle Example

```text
Browser Request
	-> index.php
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

- `index.php`
- `app/http/Kernel.php`
- `systems/Core/Routing/RouteServiceProvider.php`
- `systems/Core/Console/Commands.php` (`make:controller`)
