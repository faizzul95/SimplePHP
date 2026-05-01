# 05. Views & Blade Engine

## Engine Behavior

- View resolution supports:
  - `app/views/<name>.blade.php`
  - `app/views/<name>.php`
- Direct file paths such as `app/views/errors/general_error.php`
- Direct-path resolution is constrained to the configured views directory; traversal (`../`) and null-byte paths are rejected.
- Compiled cache path is configured (`framework.view_cache_path`).
- Cache lookup reuses an in-memory source-to-compiled-path map during the request lifecycle.
- Cache signature includes source file path + file stat metadata (`mtime`, `size`).
- Compiled files are written with `LOCK_EX`.
- Compiled cache source can be compacted through `framework.view_compact_compiled_cache` using PHP's own whitespace stripper before the file is stored.
- OPcache invalidation runs when available.
- Shared template helpers now render through Blade, so `.php` view files can be migrated in place to Blade syntax without renaming them.
- Main app pages can use a shared layout with `@extends('_templates.layouts.app')`, `@section('content')`, and `@push('scripts')`.
- Rendered HTML can be whitespace-minified through `framework.view_minify_output`.
- Shared flash/error view data is cached once per top-level render to avoid repeated session reads across nested includes.

## Confirmed Directives

### Layout / Include

- `@extends`, `@section`, `@endsection`, `@show`, `@parent`, `@yield`, `@hasSection`
- `@include`, `@includeIf`, `@includeWhen`, `@includeUnless`

### Stacks

- `@push`, `@prepend`, `@endpush`, `@endprepend`, `@stack`

### Flow Control

- `@if`, `@elseif`, `@else`, `@endif`
- `@unless`, `@endunless`
- `@isset`, `@endisset`
- `@foreach`, `@endforeach`
- `@forelse`, `@empty`, `@endforelse`
- `@for`, `@endfor`
- `@while`, `@endwhile`
- `@switch`, `@case`, `@default`, `@endswitch`
- `@break(condition)`, `@continue(condition)`

### Auth / Env / Session

- `@auth`, `@endauth`
- `@guest`, `@endguest`
- `@can('permission')`, `@endcan`
- `@cannot('permission')`, `@endcannot`
- `@env('...')`, `@endenv`
- `@production`, `@endproduction`
- `@session('key')`, `@endsession`

### Output / Utility

- escaped `{{ ... }}` and raw `{!! ... !!}`
- `@json(...)`, `@csrf`, `@method('PUT')`
- `@error('field')`, `@enderror`
- `@class([...])`, `@style([...])`
- boolean attrs: `@checked`, `@selected`, `@disabled`, `@readonly`, `@required`
- `@once`, `@endonce`
- `@each(...)`
- `@dd(...)`, `@dump(...)` in debug mode only (`error_debug = true`)
- `@verbatim ... @endverbatim`

## Layouts

Shared layouts live under [app/views/_templates/layouts/](../../app/views/_templates/layouts/). The legacy `footer.php` / `header.php` partials have been removed — app pages now compose the shell through `@extends('_templates.layouts.app')` with `@section('content')` and `@push('scripts')`. Add new layouts as sibling files in the same directory and reference them by dot path.

## Examples

### Basic layout usage

```blade
@extends('_templates.layouts.app')

@section('content')
  <h1>{{ $title }}</h1>
@endsection

@push('scripts')
  <script>
    console.log('page ready');
  </script>
@endpush
```

### Conditional include

```blade
@includeWhen(auth()->check(), 'partials.user-menu')
```

### Permission-aware output

```blade
@can('user-create')
  <button class="btn btn-primary">Add User</button>
@endcan

@if(auth()->can('rbac-abilities-create') || auth()->can('rbac-abilities-update'))
  @include('rbac._permissionListView')
@endif
```

### Stack usage

```blade
@push('scripts')
  <script src="{{ url('public/js/page.js') }}"></script>
@endpush
```

### Field error output

```blade
@error('email')
  <div class="text-danger">{{ $message }}</div>
@enderror
```

### Old input after redirect-back validation

```blade
<input type="text" name="name" value="{{ old('name') }}">
```

## How To Use

1. Keep templates in `app/views` and render by dot notation.
2. Use escaped output (`{{ }}`) by default.
3. Reserve raw output (`{!! !!}`) only for trusted/sanitized HTML.
4. Use stacks/sections to avoid repeating script/style blocks.
5. Treat `@dd` and `@dump` as local debugging helpers, not production-safe rendering features.
6. When a Blade expression sits inside a JavaScript string in a `.php` view file, prefer `{{ route("name") }}` over nested single quotes.
7. Use `old('field')` for classic browser forms that redirect back after validation failure.

## What To Avoid

- Avoid rendering untrusted raw HTML with `{!! !!}`.
- Avoid putting heavy business logic in Blade templates.
- Avoid assuming unsupported directives; use only those listed here.
- Avoid custom permission directives that are not implemented, such as `@canany`.
- Avoid relying on `@dd` / `@dump` output in production; they no-op when debug is disabled.

## Benefits

- Fast template reuse via sections/includes.
- Safer default output escaping.
- Efficient rendering due to compiled cache + mtime invalidation.
- Smaller compiled cache files when compiled-cache compaction is enabled.
- Optional response-size reduction from safe inter-tag whitespace minification.

## Evidence

- `systems/Core/View/BladeEngine.php`
- `app/config/framework.php`
- `app/helpers/custom_template_helper.php`
- `app/helpers/custom_project_helper.php`
