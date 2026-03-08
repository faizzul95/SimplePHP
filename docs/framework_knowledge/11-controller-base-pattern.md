# 11. Controller Base Pattern

## Base Class

- All app controllers extend `Core\Http\Controller` (abstract class).
- Constructor auto-instantiates `BladeEngine` via `blade_engine()` helper.

## Complete Built-in Controller Methods (Verified from Source)

### View Rendering

- `view(string $view, array $params = [])` — Renders Blade view and **exits**. Uses dot-notation (e.g., `'dashboard.admin'`).

### Page State

- `setPageState(string $page, ?string $subpage, ?string $permission, string $titlePage, string $titleSubPage = '')` — Sets global menu/breadcrumb state. When `$permission` is not null, checks via `permission()` helper and calls `show_403()` + exit on failure.

### JSON Response Helpers (All terminate the request)

- `jsonResponse(array $data, int $httpStatus = 0)` — Send raw JSON payload.
- `successResponse(string $message = 'Success', ?array $data = null, int $code = 200)` — `{'code': 200, 'message': '...', 'data': {...}}`.
- `errorResponse(string $message = 'Error', int $code = 422, array $errors = [])` — `{'code': 422, 'message': '...', 'errors': [...]}`.
- `paginateResponse(array $result)` — DataTables-compatible JSON from `paginate_ajax()`.

### Record Helpers

- `decodeIdOrFail(string $encodedId, string $label = 'ID'): int|string` — Decodes encoded (hashed) ID or returns 400 error.
- `findOrFail(string $table, int|string $id, string $label = 'Record', ?string $select = null, bool $softDelete = true): array` — Fetch single record or 404. Respects soft-delete by default via `whereNull('deleted_at')`. Uses `safeOutput()`.
- `findByEncodedIdOrFail(string $encodedId, string $table, string $label, ?string $select = null, bool $softDelete = true): array` — Combines `decodeIdOrFail()` + `findOrFail()` in one call.

### Soft-Delete Helpers

- `softDeleteByEncodedId(string $encodedId, string $table, string $label = 'Record', array $extra = [])` — Decodes ID, sets `deleted_at` + optional extra columns, returns JSON success/error.
- `restoreByEncodedId(string $encodedId, string $table, string $label = 'Record', array $extra = [])` — Decodes ID, sets `deleted_at = null` + optional extra columns, returns JSON success/error.

### Authorization

- `authorizeOrFail(string $permissionSlug, string $message = 'Unauthorized')` — Checks permission and returns 403 JSON on failure.

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
		$this->setPageState('directory', 'users', 'user-view', 'Users', 'User Management');
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
		$data = $this->findByEncodedIdOrFail($id, 'users', 'User', 'id, name, email');
		$this->successResponse('User found', $data);
	}

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

### 2) Permission-gated page rendering

```php
public function roles(): void
{
	$this->setPageState('rbac', 'roles', 'role-view', 'RBAC', 'Role Management');
	$this->view('rbac.roles');
}
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
2. Use `findByEncodedIdOrFail()` for single-record lookups with encoded IDs.
3. Use `softDeleteByEncodedId()` / `restoreByEncodedId()` for standard soft-delete CRUD.
4. Use `setPageState()` with permission slug for automatic page-level access control.
5. Use `successResponse()` / `errorResponse()` for consistent JSON shape.

## What To Avoid

- Avoid returning raw mixed array structures when helper methods standardize response format.
- Avoid duplicating ID decode logic — use `decodeIdOrFail()` or `findByEncodedIdOrFail()`.
- Avoid calling `permission()` manually when `setPageState()` already does it.
- Avoid using `$_SESSION` directly — use `authId()`, `authUser()`, `can()`.

## Benefits

- Consistent API and page action behavior across all controllers.
- Automatic error handling (400 for bad IDs, 403 for permissions, 404 for missing records).
- Less repetitive boilerplate for CRUD operations.
- Built-in soft-delete and restore patterns.

## Evidence

- `systems/Core/Http/Controller.php` (337 lines)
- `systems/Core/Routing/Router.php`
- `systems/Core/Console/Commands.php` (`make:controller` scaffold)
