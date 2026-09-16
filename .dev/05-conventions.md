# 05 — Conventions & Recipes

**Verified:** 2026-08-25

How to add things without fighting the framework.

---

## Namespaces and autoload map

From `composer.json`:

| Prefix | Path |
|---|---|
| `Core\` | `systems/Core/` |
| `Components\` | `systems/Components/` |
| `Middleware\` | `systems/Middleware/` |
| `App\Http\Controllers\` | `app/http/controllers/` |
| `App\Http\Middleware\` | `app/http/middleware/` |
| `App\Http\Requests\` | `app/http/requests/` |
| `App\Console\Commands\` | `app/console/commands/` |
| `App\Models\` | `app/models/` |
| `App\Providers\`, `App\Support\`, `App\Services\`, `App\Repositories\`, `App\Jobs\`, `App\DTO\` | matching lowercase dir |

Directory names are lowercase; class names are `StudlyCase`. On Windows this is forgiving, on
Linux it is not — the recent `app/console/Commands` → `app/console/commands` and
`app/Models` → `app/models` renames in git history exist because of exactly that.

---

## Add a route

```php
// app/routes/web.php  — inside the ['middleware' => ['web']] group
$router->get('/reports', [ReportController::class, 'index'])
    ->webAuth()                    // session auth
    ->permission('report-view')    // RBAC slug
    ->featureFlag('reports')       // optional feature gate
    ->name('reports.index');
```

```php
// app/routes/api/reports.php  — required from api.php inside the api.app group
$router->get('/reports/data', [ReportController::class, 'data'])
    ->permission('report-view')
    ->name('api.reports.data');

$router->post('/reports', [ReportController::class, 'store'])
    ->permission('report-create')
    ->middleware('throttle:10,1,auth-route')
    ->name('api.reports.store');
```

Rules:
- **Never hardcode `/api/v1`.** It comes from `config('api.versioning')`. Use `route('name')`
  from PHP and the JS helpers from views.
- Web routes render HTML only. All data/AJAX goes through the API prefix.
- New API route files must be `require_once`d from `app/routes/api.php` inside the `api.app`
  group.
- **Avoid closure actions** — `route:cache` silently drops them (**R-05**).

## Add a controller

```php
namespace App\Http\Controllers;

use Core\Http\Controller;
use Core\Http\Request;

class ReportController extends Controller
{
    public function data(Request $request): void
    {
        $rows = db()->table('reports')
            ->select(['id', 'title', 'created_at'])
            ->setAllowedSortColumns(['id', 'title', 'created_at'])
            ->setPaginateFilterColumn(['title'])
            ->paginate_ajax($request->all());

        $this->paginateResponse($rows);   // wraps jsonResponse()
    }
}
```

Use the base-class helpers rather than raw `Response::` — they throw `ResponseEmitted` so the
middleware stack unwinds: `successResponse()`, `errorResponse()`, `jsonResponse()`,
`paginateResponse()`, `findOrFail()`, `findByEncodedIdOrFail()`, `authorizeOrFail()`,
`softDeleteByEncodedId()`, `restoreByEncodedId()`, `decodeIdOrFail()`.

`php myth make:controller Name --resource --api` scaffolds one.

## Add a FormRequest

```php
namespace App\Http\Requests;

use Core\Http\FormRequest;

class SaveReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->can('report-create');
    }

    public function rules(): array
    {
        return [
            'title' => 'required|string|min_length:3|max_length:200',
            'body'  => 'nullable|string|max_length:5000',
        ];
    }

    public function messages(): array
    {
        return ['title.required' => 'A report title is required.'];
    }
}
```

Type-hint it in the action; the router instantiates and validates it automatically.

> `unique:table[,column[,ignoreValue[,ignoreColumn]]]`, `unique_with:` and
> `exists:table[,column]` exist as of 2026-08-25. They are a UX affordance and a
> check-then-act race — **the DB UNIQUE index is what actually holds the invariant**. See
> `app/database/migrations/20260824_017_add_users_unique_constraints.php` for the pattern,
> including the pre-flight duplicate check. Soft-deleted rows are counted by `unique:`, to
> match what a plain UNIQUE index does.

Rule names use `snake_case` and are dispatched as `'validate' . ucfirst(str_replace('_','',$rule))`,
so `min_length` → `validateMinlength`. Parameters use `rule:arg1,arg2`.

## Add middleware

```php
namespace App\Http\Middleware;

use Core\Http\Middleware\MiddlewareInterface;
use Core\Http\Request;

class EnsureTenant implements MiddlewareInterface
{
    private array $params = [];

    public function setParameters(array $parameters): void   // optional, for `alias:arg`
    {
        $this->params = $parameters;
    }

    public function handle(Request $request, callable $next)
    {
        // pre
        $response = $next($request);
        // post — only runs if nothing downstream called exit()
        return $response;
    }
}
```

Then register the alias in `app/config/framework.php → framework.middleware_aliases`, and add
it to a group if it should apply broadly.

**Short-circuit correctly:** throw `new \Core\Http\ResponseEmitted($payload, $status)` rather
than `Response::json(...)`. `Response::json()` calls `exit` and skips every outer middleware's
post-`next()` block (**R-01**).

## Add a migration

```
php myth make:migration create_reports_table
```

```php
use Core\Database\Schema\{Schema, Blueprint, Migration};

return new class extends Migration
{
    protected string $table = 'reports';
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::create($this->table, function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_general_ci');

            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['deleted_at', 'title'], 'reports_deleted_at_title_index');
        });
    }

    public function down(): void { Schema::dropIfExists($this->table); }
};
```

Conventions in this repo: anonymous class, `YYYYMMDD_NNN_description.php`, explicit
engine/charset/collation, explicit named indexes, `deleted_at` leading every composite index
because soft deletes filter on it first.

## Add a console command

```
php myth make:command SyncReports
```

Drop the class in `app/console/commands/`. `Kernel::discoverClassCommands()` finds it by
scanning the directory and mapping filename → FQCN — no registration needed.

## Add a model

```php
namespace App\Models;

use Core\Database\Model;

class Report extends Model
{
    protected string $table = 'reports';
    protected array $fillable = ['user_id', 'title', 'body'];
    protected array $hidden = ['internal_notes'];
    protected array $casts = ['published_at' => 'datetime', 'meta' => 'array'];
    protected bool $softDeletes = true;
}
```

Models are optional — `db()->table()` is equally idiomatic here and is what most of `app/`
uses. Use a Model when you want casts, observers, dirty tracking, or route-model binding.

---

## Hard rules

1. **Never interpolate user input into a column or table name.** JOIN and ORDER BY are now
   guarded, but `select()` still passes expressions through (**D-02**). Use
   `setAllowedSortColumns()` / `setFilterableColumns()` allow-lists for anything
   client-driven.
2. **Never call `password_hash`/`password_verify` directly.** Use `Core\Security\Hasher`.
3. **Never call `exit`/`die` in request-path code.** Throw `ResponseEmitted`.
4. **Never add a Composer runtime dependency** without an explicit decision — the two-dep
   footprint is a design goal.
5. **`{{ }}` for output, `{!! !!}` only with a written justification.** The input XSS filter
   is log-only by default.
6. Prefer `Core\Http\Request` over `Components\Request`, `Core\` over `Components\`.

## Before you push

```
php vendor/bin/phpunit                 # 776 tests must stay green
php vendor/bin/phpstan analyse         # level 2 today; do not regress
php myth security:audit
php myth env:check --strict
php myth route:list                    # confirm your route is registered
```
