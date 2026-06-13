---
name: myth-develop
description: >
  Step-by-step development workflow for building features in the MythPHP framework.
  Use when: creating controllers, routes, FormRequest validation, views/Blade,
  middleware, service providers, feature flags, cache, file uploads, helpers, collections,
  or any new endpoint (web or API). Always uses db()->table() directly — no model
  classes. Covers the full feature checklist from route to response, naming conventions,
  response shapes, Blade templates, and shared-controller patterns.
argument-hint: 'Feature type, e.g. "CRUD controller", "API endpoint", "Blade view", "middleware"'
---

# MythPHP Development Workflow

## When to Use This Skill

Load this skill when implementing any new feature — route, controller, FormRequest,
view, or middleware. Always use `db()->table()` directly — there are no model classes.
Follow the conventions here to avoid re-implementing patterns the framework already provides.

---

## Quick Feature Generators

Run these CLI generators first; then fill in the generated stubs:

```bash
php myth make:controller UserController --resource   # CRUD controller stub
php myth make:request     SaveUserRequest            # FormRequest stub
php myth make:middleware  EnsureAccountActive        # Middleware stub
php myth make:job         SendWelcomeEmail           # Queue job stub
php myth make:command     SyncUsers                  # Console command stub
php myth make:repository  UserRepository             # Query-builder repository stub
php myth make:dto         UserDto                    # DTO/value object stub
```

After generating, verify routes are registered and run:

```bash
php myth route:list                     # Confirm your route appears
php myth route:list --path=/users       # Filter by path
```

---

## 1. Route → Controller → Response Flow

Reference: [01-runtime-architecture.md](../../../docs/framework_knowledge/01-runtime-architecture.md) · [02-routing-http-flow.md](../../../docs/framework_knowledge/02-routing-http-flow.md) · [11-controller-base-pattern.md](../../../docs/framework_knowledge/11-controller-base-pattern.md)

### Response shape rules (enforced by Kernel)

| Controller return | Sent as |
|-------------------|---------|
| `void` + `jsonResponse()` | `application/json` — terminates request immediately |
| `array` | `application/json` via `Response::json()` — returned from method |
| `string` | HTML/text echo |
| `null` | Already-handled (redirect, render, or JSON sent manually) |

**Preferred pattern**: Use `void` return type + `jsonResponse([...])` directly. This mirrors
the actual codebase (RoleController, PermissionController, UserController).

### jsonResponse() shape

```php
// Success
jsonResponse(['code' => 200, 'message' => 'Role saved', 'data' => $row]);

// Created
jsonResponse(['code' => 201, 'message' => 'Record created', 'data' => ['id' => $id]]);

// Bad request
jsonResponse(['code' => 400, 'message' => 'ID is required']);

// Validation failed
jsonResponse(['code' => 422, 'message' => 'Failed to save role']);

// Not found
jsonResponse(['code' => 404, 'message' => 'Role not found']);

// Forbidden
jsonResponse(['code' => 403, 'message' => 'Unauthorized']);

// DataTable — pass paginate_ajax result directly
jsonResponse($result);
```

---

### Full CRUD Controller Pattern

Key conventions used across the codebase:
- `jsonResponse([...])` everywhere — terminates request immediately
- Plain integer IDs — no encoded IDs
- `findOrFail()` for single-record lookups (auto-404)
- `insertOrUpdate()` for combined create + update via single `save()` endpoint
- `$request->validated()` to read FormRequest data
- `isError($result['code'])` to check DB operation results
- Private `map*DatatableRow()` to transform rows before returning

```php
class ProductController extends \Core\Http\Controller
{
    // ── Page (browser) ────────────────────────────────────────────────
    public function index(): void
    {
        $this->setPageState('products', 'list', 'Catalog', 'Products');
        $this->view('products.index');
    }

    // ── DataTable list (POST) ─────────────────────────────────────────
    public function list(Request $request): void
    {
        $result = db()->table('products')
            ->select('id, name, price, status, created_at')
            ->whereNull('deleted_at')
            ->setPaginateFilterColumn(['name'])
            ->setAllowedSortColumns(['products.name', 'products.price', 'products.status'])
            ->safeOutput()
            ->paginate_ajax($request->all());

        $result['data'] = array_map([$this, 'mapProductDatatableRow'], $result['data']);
        jsonResponse($result);
    }

    // ── Show single record (GET /show/{id}) ───────────────────────────
    public function show(int|string $id): void
    {
        $record = $this->findOrFail('products', $id, '*', false, 'Product not found');
        jsonResponse(['code' => 200, 'data' => $record]);
    }

    // ── Create + Update (POST /save) ──────────────────────────────────
    public function save(SaveProductRequest $request): void
    {
        $data      = $request->validated();
        $productId = $data['id'] ?? null;
        unset($data['id']);

        $result = db()->table('products')
            ->setFillable(['name', 'price', 'status', 'category_id'])
            ->insertOrUpdate(['id' => $productId], $data);

        if (isError($result['code'])) {
            jsonResponse(['code' => 422, 'message' => 'Failed to save product']);
        }

        $savedId  = $productId ?: ($result['id'] ?? null);
        $savedRow = $savedId
            ? db()->table('products')->select('id, name, price, status')->where('id', $savedId)->whereNull('deleted_at')->safeOutput()->fetch()
            : null;

        jsonResponse(['code' => 200, 'message' => 'Product saved', 'data' => $savedRow ? $this->mapProductDatatableRow($savedRow) : null]);
    }

    // ── Soft-delete (DELETE /delete/{id}) ────────────────────────────
    public function destroy(int|string $id): void
    {
        $result = db()->table('products')
            ->where('id', $id)
            ->softDelete(['status' => 0, 'deleted_at' => timestamp()]);

        if (isError($result['code'])) {
            jsonResponse(['code' => 422, 'message' => 'Failed to delete product']);
        }

        jsonResponse(['code' => 200, 'message' => 'Product deleted']);
    }

    // ── Private row mapper ────────────────────────────────────────────
    private function mapProductDatatableRow(array $row): array
    {
        return [
            'row_key' => 'product-row-' . $row['id'],
            'key'     => $row['id'],
            'name'    => $row['name'],
            'price'   => number_format((float) $row['price'], 2),
            'status'  => $row['status'] ? '<span class="badge bg-label-success">Active</span>' : '<span class="badge bg-label-warning">Inactive</span>',
            'action'  => "<i class='bx bx-edit-alt' onclick='editRecord({$row['id']})'></i>",
        ];
    }
}
```

---

### Route registration

Routes live in `app/routes/web.php` (browser pages) and `app/routes/API/` (JSON endpoints).
Use plain integer IDs — no encoded ID parameters.

```php
// web.php — browser page only
$router->get('/rbac/roles', [RoleController::class, 'index'])
    ->featureFlag('rbac.role')
    ->permission('rbac-roles-view')
    ->name('rbac.roles');

// API routes — app/routes/API/rbac_roles_permissions.php
$router->group(['prefix' => 'roles', 'middleware' => ['permission:rbac-roles-view', 'feature:rbac.role']], function ($router) {
    $router->post('/list',          [RoleController::class, 'listRolesDatatable'])->name('roles.list');
    $router->get('/show/{id}',      [RoleController::class, 'show'])->name('roles.show');
    $router->post('/save',          [RoleController::class, 'save'])->middleware('xss')->permissionAny(['rbac-roles-create', 'rbac-roles-update'])->name('roles.save');
    $router->delete('/delete/{id}', [RoleController::class, 'destroy'])->permission('rbac-roles-delete')->name('roles.delete');
    $router->post('/options',       [RoleController::class, 'listSelectOption'])->name('roles.options');
});
```

**Route conventions:**
- DataTable list → `POST /list` (sends DataTables params in request body)
- Show single → `GET /show/{id}`
- Create + Update → `POST /save` (id present = update, id absent = create)
- Delete → `DELETE /delete/{id}`
- Dropdown options → `POST /options`

---

## 2. FormRequest Validation

Reference: [04-validation-formrequest.md](../../../docs/framework_knowledge/04-validation-formrequest.md)

### Complete FormRequest template

```php
class SaveUserRequest extends \Core\Http\FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function sanitize(): array
    {
        return [
            'name'  => 'trim|strip_tags',
            'email' => 'trim|lowercase',
            'phone' => 'digits_only',
        ];
    }

    public function rules(): array
    {
        return [
            'name'  => 'required|string|max:100',
            'email' => 'required|email|max:150',
            'phone' => 'nullable|digits|min:10|max:15',
        ];
    }

    public function defaults(): array
    {
        return ['phone' => null];
    }

    public function casts(): array
    {
        return ['status' => 'integer'];
    }

    public function sensitiveFields(): array
    {
        return ['password', 'password_confirmation'];
    }

    // Normalize blank optional fields to null before validation
    public function prepareForValidation(): void
    {
        if ($this->input('phone') === '') {
            $this->merge(['phone' => null]);
        }
    }
}
```

### Key FormRequest rules

- Blank optional fields must be normalized to `null` in `prepareForValidation()` — browser posts `''`, not `null`.
- Use `toDTO()` (not `all()`) when passing data to the database layer.
- Declare `sensitiveFields()` for any password, token, or PII field.
- `isCreate()` / `isUpdate()` check for `id` presence — use in `rules()` for conditional validation.

---

## 3. Direct Query Builder — No Model Classes

Reference: [12-database-query-builder.md](../../../docs/framework_knowledge/12-database-query-builder.md)

MythPHP uses `db()->table()` directly everywhere — there are **no model classes**.
Do not generate or use a class extending `\Core\Database\BaseDatabase` for regular features.

```php
// READ — always add whereNull('deleted_at') for active records
$user = db()->table('users')
    ->where('id', $id)
    ->whereNull('deleted_at')
    ->safeOutput()
    ->fetch();

// INSERT — with inline column guard
$id = db()->table('users')
    ->setFillable(['name', 'email', 'status', 'phone'])
    ->insert($request->toDTO());

// UPDATE — always scope with where() before update
db()->table('users')
    ->setFillable(['name', 'email', 'status', 'phone'])
    ->where('id', $id)
    ->whereNull('deleted_at')
    ->update($request->toDTO());

// SOFT DELETE — sets deleted_at
db()->table('users')
    ->where('id', $id)
    ->softDelete(['user_status' => 3, 'deleted_at' => timestamp()]);
```

**Inline column guard** — use `setFillable()` at the call site instead of a model class:

```php
// Explicitly list which columns from toDTO() may reach the database
db()->table('users')
    ->setFillable(['name', 'email', 'phone', 'user_gender', 'user_status'])
    ->insert($request->toDTO());
```

This replaces the `$fillable` property on model classes and is the secure way to prevent
mass-assignment without any model scaffolding.

---

## 4. Blade View Template

Reference: [05-views-blade-engine.md](../../../docs/framework_knowledge/05-views-blade-engine.md)

```blade
{{-- app/views/directory/users.blade.php --}}
@extends('_templates.layouts.app')

@section('content')
<div class="container-xxl flex-grow-1 container-p-y">
    <h4 class="fw-bold py-3 mb-4">User Management</h4>

    @if (session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    <div class="card">
        <div class="card-body">
            {{-- DataTable rendered by JavaScript --}}
            <table id="users-table" class="table"></table>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    // Initialize using BootstrapDataTable
    BootstrapDataTable('#users-table', '/api/v1/users', columns);
</script>
@endpush
```

**Confirmed directives:** `@extends`, `@section/@endsection`, `@yield`, `@include`, `@push/@stack`, `@if/@foreach/@for/@while/@switch`, `@auth/@guest`, `@can`, `@csrf`, `@method`, `@error`, `@json`, `@class`, `@checked/@selected/@disabled`.  
**No custom directive registration API exists** — use `@php` blocks for one-off logic.

---

## 5. Middleware Development

Reference: [06-middleware-security.md](../../../docs/framework_knowledge/06-middleware-security.md)

```php
// app/http/middleware/EnsureAccountActive.php
class EnsureAccountActive
{
    public function handle(Request $request, Closure $next): mixed
    {
        if (auth()->check() && auth()->user()['status'] !== 'active') {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Account suspended'], 403);
            }
            return redirect()->to('/suspended');
        }
        return $next($request);
    }
}
```

Register alias in `app/config/framework.php`:

```php
'middleware_aliases' => [
    'account.active' => \App\Http\Middleware\EnsureAccountActive::class,
],
```

Apply to route:

```php
$router->get('/dashboard', [DashboardController::class, 'index'])
    ->webAuth()
    ->middleware('account.active');
```

---

## 6. Cache Usage

Reference: [07-cache-queue-console.md](../../../docs/framework_knowledge/07-cache-queue-console.md)

```php
// Remember (atomic — safe for concurrent requests)
$users = cache()->remember('users.all', 300, fn() =>
    db()->table('users')->whereNull('deleted_at')->get()
);

// Explicit put/get
cache()->put('key', $value, 600);
$value = cache()->get('key', 'default');

// Pull (get + delete)
$token = cache()->pull('pending_token_' . $userId);

// Flush on write
cache()->forget('users.all');
```

---

## 7. Feature Flags

Reference: [24-global-helpers-hooks.md](../../../docs/framework_knowledge/24-global-helpers-hooks.md)

```php
// Route-level gate
$router->get('/beta-feature', [BetaController::class, 'index'])
    ->webAuth()
    ->featureFlag('beta.new-ui');

// Controller-level check
if (feature('payments.stripe')) {
    // Stripe logic
}

// Value variant
$limit = feature_value('uploads.max_size', 4);
```

---

## 8. Collection Usage

Reference: [21-collection-core-collection.md](../../../docs/framework_knowledge/21-collection-core-collection.md)

```php
$users = collect(db()->table('users')->get());
$active = $users->filter(fn($u) => $u['status'] === 'active');
$names  = $active->pluck('name')->sort()->values();
$groups = $active->groupBy('department');
```

---

## 9. File Upload

Reference: [17-file-upload-system.md](../../../docs/framework_knowledge/17-file-upload-system.md)

```php
// In controller — with MIME + size validation
files()->setUploadDir('public/upload/avatars');
files()->setMaxFileSize(5);
files()->setAllowedMimeTypes('image/jpeg,image/png,image/webp');

$result = files()->upload($_FILES['avatar']);
if (!$result['success']) {
    jsonResponse(['code' => 422, 'message' => $result['message']]);
}
// $result['path'], $result['filename'], $result['size']
```

---

## 10. Development Checklist

Before submitting any feature:

- [ ] Route registered and visible in `php myth route:list`
- [ ] `FormRequest::authorize()` checks operation-specific permissions (`can('resource.create')` vs `can('resource.update')`), not just `auth()->check()`
- [ ] `FormRequest::sanitize()` applied on all text fields (`trim|strip_tags|normalize_spaces`)
- [ ] `FormRequest::sensitiveFields()` declared for password, token, or PII fields
- [ ] Every `insert()` / `update()` uses `setFillable([...])` or `setGuarded([...])` — never pass raw `$request->all()` to DB
- [ ] Controller uses `toDTO()` — not `all()` — for DB writes
- [ ] All active-record queries include `->whereNull('deleted_at')`
- [ ] No `whereRaw()` with unsanitized user input
- [ ] All JSON responses use `jsonResponse([...])` directly — never `$this->successResponse/errorResponse`
- [ ] CSRF middleware active on all browser `POST/PUT/PATCH/DELETE` routes (web group handles this)
- [ ] Ownership verified on record-access endpoints: check `$record['user_id'] === $this->authId()` or use `authorizeOrFail()`
- [ ] `php myth security:audit` passes
