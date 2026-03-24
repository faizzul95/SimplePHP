# 05. Views & Blade Engine

## Engine Behavior

- View resolution supports:
  - `app/views/<name>.blade.php`
  - `app/views/<name>.php`
- Direct file paths such as `app/views/errors/general_error.php`
- Compiled cache path is configured (`framework.view_cache_path`).
- Cache key includes source file path + mtime.
- Compiled files are written with `LOCK_EX`.
- OPcache invalidation runs when available.

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
- `@dd(...)`, `@dump(...)`
- `@verbatim ... @endverbatim`

## Examples

### Basic layout usage

```blade
@extends('layouts.main')

@section('content')
  <h1>{{ $title }}</h1>
@endsection
```

### Conditional include

```blade
@includeWhen(auth()->check(), 'partials.user-menu')
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

## How To Use

1. Keep templates in `app/views` and render by dot notation.
2. Use escaped output (`{{ }}`) by default.
3. Reserve raw output (`{!! !!}`) only for trusted/sanitized HTML.
4. Use stacks/sections to avoid repeating script/style blocks.

## What To Avoid

- Avoid rendering untrusted raw HTML with `{!! !!}`.
- Avoid putting heavy business logic in Blade templates.
- Avoid assuming unsupported directives; use only those listed here.

## Benefits

- Fast template reuse via sections/includes.
- Safer default output escaping.
- Efficient rendering due to compiled cache + mtime invalidation.

## Evidence

- `systems/Core/View/BladeEngine.php`
- `app/config/framework.php`
