# 11. Controller Base Pattern

## Shared controllers for web and API (commit `0006837`)

A single controller class now serves both the browser page action and the matching API endpoint. The previous `app/http/controllers/Api/` split (e.g. `AuthApiController`, `UserApiController`) has been removed — do not recreate it.

- Expose API-specific actions as explicit methods on the same controller: `loginApi`, `me`, `logout`, etc.
- Routes attach the correct middleware (`auth.web` vs `auth.api`) per route rather than per controller class.
- See [03-auth-tokens-api.md](03-auth-tokens-api.md) for the credential-issuance pattern used by the shared `AuthController`.
- Keep controllers thin when practical, but preserve the current app structure while the upgrade is still in transition. Listing endpoints may stay inline if they are still being stabilized, as long as request input is normalized explicitly and pagination/sort allowlists stay enforced.

## Base Class

- All app controllers extend `Core\Http\Controller` (abstract class).
- Constructor auto-instantiates `BladeEngine` via `blade_engine()` helper.

## Complete Built-in Controller Methods (Verified from Source)

### View Rendering

- `view(string $view, array $params = [])` — Renders Blade view and **exits**. Uses dot-notation (e.g., `'dashboard.admin'`).

### Page State

- `setPageState(string $page, ?string $subpage, string $titlePage, string $titleSubPage = '')` — Sets global menu/breadcrumb state only. Authorization should be enforced in routes and middleware.

### JSON Response Helpers (All terminate the request)

- `jsonResponse(array $data, int $httpStatus = 0)` — Send raw JSON payload.
- `successResponse(string $message = 'Success', ?array $data = null, int $code = 200)` — `{'code': 200, 'message': '...', 'data': {...}}`.
- `errorResponse(string $message = 'Error', int $code = 422, array $errors = [])` — `{'code': 422, 'message': '...', 'errors': [...]}`.
- `paginateResponse(array $result)` — DataTables-compatible JSON from `paginate_ajax()`.

### Record Helpers

- `decodeIdOrFail(string $encodedId, string $message = 'ID is required'): int|string` — Decodes encoded (hashed) ID or returns 400 error.
- `findOrFail(string $table, int|string $id, ?string $select = null, bool $softDelete = true, string $message = 'Record not found'): array` — Fetch single record or 404. Respects soft-delete by default via `whereNull('deleted_at')`. Uses `safeOutput()`.
- `findByEncodedIdOrFail(string $encodedId, string $table, string $label = 'Record', ?string $select = null, bool $softDelete = true): array` — Combines `decodeIdOrFail()` + `findOrFail()` in one call.

### IDOR / Ownership Helpers (from `SecureResourceAccess` trait)

Mixed into `Controller` automatically — available on every controller without any extra `use` statement.

- `authorizeResource(string $table, string $encodedId, ?callable $ownershipCheck = null, string $label = 'Record', ?string $select = null, bool $softDelete = true): array`
  Decode encoded ID → fetch record (400/404 on failure) → run ownership check (403 if false). Returns the fetched record. Pass `null` for `$ownershipCheck` when route-level RBAC middleware is sufficient.

- `authorizeResourceById(string $table, int|string $id, ?callable $ownershipCheck = null, string $label = 'Record', string $select = '*', bool $softDelete = true): array`
  Same as `authorizeResource()` but accepts a plain (non-encoded) ID. Use on internal API routes.

- `assertOwnership(array $record, string $ownerColumn = 'user_id', ?callable $adminCheck = null, string $label = 'resource'): void`
  Standalone ownership assertion after a manual fetch. Compares `$record[$ownerColumn]` against `authId()`. Terminates 401 if unauthenticated, 403 if ownership fails and `$adminCheck` is null or returns false.

Usage pattern:

```php
// Decode + fetch + ownership — one call (encoded ID from route)
$post = $this->authorizeResource('posts', $id,
    fn($r) => (int) $r['user_id'] === $this->authId(),
    label: 'Post'
);

// Plain-ID variant (API routes where ID is not encoded)
$post = $this->authorizeResourceById('posts', $id, fn($r) => (int) $r['user_id'] === $this->authId());

// Separate ownership assertion after a manual fetch
$this->assertOwnership($record, 'user_id',
    adminBypass: fn($r) => $this->can('admin-override')
);
```

### Soft-Delete Helpers

- `softDeleteByEncodedId(string $encodedId, string $table, string $label = 'Record', array $extra = [])` — Decodes ID, sets `deleted_at` + optional extra columns, returns JSON success/error.
- `restoreByEncodedId(string $encodedId, string $table, string $label = 'Record', array $extra = [])` — Decodes ID, sets `deleted_at = null` + optional extra columns, returns JSON success/error.

### Authorization

- `authorizeOrFail(string $permissionSlug, string $message = 'Unauthorized')` — Checks RBAC permission and returns 403 JSON on failure. Use for action-level guards not covered by route middleware.
- `can(string $slug): bool` — Check if current user has permission.
- `cannot(string $slug): bool` — Inverse of `can()`.
- See **IDOR / Ownership Helpers** above for record-level authorization.

### Auth Utilities

- `authId(): ?int` — Current user ID from Auth component or session fallback.
- `authUser(?string $key = null): mixed` — Returns user data from session (id, name, email). Pass key to get single value.
- `can(string $slug): bool` — Check if current user has permission.
- `cannot(string $slug): bool` — Inverse of `can()`.

## Action Invocation (From Router)

Router supports these action styles:
- Closures: `function (Request $request) { ... }`
- `'Class@method'` string syntax
- `[Class::class, 'method']` array syntax

## Examples

### 1) Full CRUD controller pattern

```php
class UserController extends \Core\Http\Controller
{
	public function index(): void
	{
		$this->setPageState('directory', 'users', 'Users', 'User Management');
		$this->view('directory.users');
	}

	public function list(): void
	{
		$result = db()->table('users')
			->select('id, name, email, user_status, created_at')
			->whereNull('deleted_at')
			->setPaginateFilterColumn(['name', 'email'])
			->paginate_ajax(request()->all());
		$this->paginateResponse($result);
	}

	public function show(string $id): void
	{
			// authorizeResource: decode + fetch + ownership in one call
			$data = $this->authorizeResource('users', $id,
				ownershipCheck: fn($r) => (int) $r['id'] === $this->authId() || $this->can('user-view-any'),
				label: 'User',
				select: 'id, name, email'
			);

	public function store(\App\Http\Requests\StoreUserRequest $request): void
	{
		$payload = $request->validated();
		$result = db()->table('users')->insert($payload);
		if (isError($result['code'])) {
			$this->errorResponse('Failed to create user', 422);
		}
		$this->successResponse('User created', null, 201);
	}

	public function destroy(string $id): void
	{
		$this->authorizeOrFail('user-delete');
		$this->softDeleteByEncodedId($id, 'users', 'User', ['user_status' => 3]);
	}

	public function restore(string $id): void
	{
		$this->authorizeOrFail('user-restore');
		$this->restoreByEncodedId($id, 'users', 'User', ['user_status' => 1]);
	}
}
```

### 2) Page rendering with route-enforced access

```php
public function roles(): void
{
	$this->setPageState('rbac', 'roles', 'RBAC', 'Role Management');
	$this->view('rbac.roles');
}
```

Route enforcement should live in the route file:

```php
$router->get('/rbac/roles', [RoleController::class, 'index'])
	->webAuth()
	->can('rbac-roles-view')
	->name('rbac.roles');
```

### 3) Inline auth check

```php
public function profile(): void
{
	$userId = $this->authId();
	$user = $this->findOrFail('users', $userId, 'Profile', 'id, name, email, user_preferred_name');
	$this->successResponse('Profile loaded', $user);
}
```

## How To Use

1. Extend `Core\Http\Controller` for all app controllers.
2. Use `authorizeResource()` for single-record lookups with encoded IDs when ownership enforcement is needed — it replaces the manual decode + fetch + ownership check pattern in one call.
3. Use `findByEncodedIdOrFail()` directly when ownership is not required (e.g., admin-only routes already guarded by RBAC middleware).
4. Use `softDeleteByEncodedId()` / `restoreByEncodedId()` for standard soft-delete CRUD.
5. Use `setPageState()` for menu, breadcrumb, and title state; keep access control in route middleware.
6. Use `successResponse()` / `errorResponse()` for consistent JSON shape.

## What To Avoid

- Avoid returning raw mixed array structures when helper methods standardize response format.
- Avoid duplicating ID decode logic — use `decodeIdOrFail()` or `findByEncodedIdOrFail()`.
- Avoid relying on `setPageState()` for page authorization when the route already declares `->webAuth()`, `->can()`, or related middleware.
- Avoid using `$_SESSION` directly — use `authId()`, `authUser()`, `can()`.
- Avoid using `use \Core\Http\SecureResourceAccess` in subclasses — the trait is already mixed into the base `Controller`; adding it again causes a PHP fatal error.
- Avoid passing `null` as `$ownershipCheck` to `authorizeResource()` on user-owned resources; the ownership check should always be explicit.

## Benefits

- Consistent API and page action behavior across all controllers.
- Automatic error handling (400 for bad IDs, 403 for explicit controller authorization checks, 404 for missing records).
- Less repetitive boilerplate for CRUD operations.
- Built-in soft-delete and restore patterns.

## Evidence

- `systems/Core/Http/Controller.php`
- `systems/Core/Http/SecureResourceAccess.php` (trait — mixed into `Controller`)
- `systems/Core/Routing/Router.php`
- `systems/Core/Console/Commands.php` (`make:controller` scaffold)
