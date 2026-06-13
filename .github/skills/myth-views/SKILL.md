---
name: myth-views
description: >
  Views, Blade engine, layouts, partials, and frontend JavaScript guide for MythPHP.
  Use when: creating Blade templates, extending layouts, using Blade directives, working
  with @section/@yield/@push/@stack, conditional rendering, form helpers (@csrf @method),
  using BootstrapDataTable for CRUD lists, calling API from JavaScript (callApi, submitApi,
  deleteApi, uploadApi, confirmDeleteAction), displaying modals/offcanvas, showing
  notifications, managing loading states, or wiring up DataTable columns with JS.
argument-hint: 'Area, e.g. "Blade layout", "DataTable columns", "modal", "callApi"'
---

# MythPHP Views & Frontend Guide

## When to Use This Skill

Load this skill for Blade template work or JavaScript API integration. The view engine
is Blade-compatible but **has no custom directive registration API** — use `@php` blocks
for one-off logic. Never add custom directives at runtime.

---

## 1. Blade View Basics

Reference: [05-views-blade-engine.md](../../../docs/framework_knowledge/05-views-blade-engine.md)

### File resolution

```
app/views/<name>.blade.php    — preferred (Blade-compiled)
app/views/<name>.php          — raw PHP include (view_raw())
```

Dot notation maps to directory slashes: `'directory.users'` → `app/views/directory/users.blade.php`

### Render from controller

```php
// Render and exit (page response)
$this->view('directory.users');
$this->view('directory.users', ['title' => 'Users', 'data' => $data]);

// Render to string (emails, partials)
$html = view_raw('emails.welcome', ['user' => $user]);
```

---

## 2. Standard Page Layout

Every app page extends the shared layout:

```blade
{{-- app/views/directory/users.blade.php --}}
@extends('_templates.layouts.app')

@section('content')
<div class="container-xxl flex-grow-1 container-p-y">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="fw-bold py-3 mb-0">User Management</h4>
        @can('user-create')
        <button class="btn btn-primary" onclick="openCreateModal()">
            <i class="bx bx-plus me-1"></i> Add User
        </button>
        @endcan
    </div>

    <div class="card">
        <div class="card-body">
            <table id="users-table" class="table w-100"></table>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
// --- page init ---
document.addEventListener('DOMContentLoaded', function () {
    initUsersTable();
});
</script>
@endpush
```

Layout lives at `app/views/_templates/layouts/app.blade.php`. Add new layouts as siblings
and reference them by dot path: `@extends('_templates.layouts.minimal')`.

---

## 3. All Confirmed Blade Directives

### Layout & Include
```blade
@extends('layout.name')
@section('name') ... @endsection
@yield('name')
@parent          {{-- merge parent section --}}
@hasSection('name')
@include('partial.name', ['key' => $val])
@includeIf('partial.name')
@includeWhen($condition, 'partial.name')
@includeUnless($condition, 'partial.name')
```

### Stacks (JS/CSS injection)
```blade
@push('scripts') <script src="..."></script> @endpush
@prepend('scripts') ... @endprepend
@stack('scripts')   {{-- in layout --}}
```

### Flow Control
```blade
@if($x) ... @elseif($y) ... @else ... @endif
@unless($x) ... @endunless
@isset($var) ... @endisset
@foreach($items as $item) ... @endforeach
@forelse($items as $item) ... @empty No items @endforelse
@for($i = 0; $i < 10; $i++) ... @endfor
@while($cond) ... @endwhile
@switch($val)
    @case('a') ... @break
    @default ...
@endswitch
@break($condition)
@continue($condition)
```

### Auth / Permissions / Environment
```blade
@auth ... @endauth
@guest ... @endguest
@can('permission-slug') ... @endcan
@cannot('permission-slug') ... @endcannot
@env('local') ... @endenv
@production ... @endproduction
@session('flash_key') {{ $value }} @endsession
```

### Output & Utility
```blade
{{ $escaped }}          {{-- htmlspecialchars output --}}
{!! $raw !!}            {{-- raw output — only for trusted/sanitized HTML --}}
@json($array)           {{-- safe JSON encode for inline JS --}}
@csrf                   {{-- hidden CSRF token input --}}
@method('PUT')          {{-- method spoofing for forms --}}
@error('field') {{ $message }} @enderror   {{-- validation error --}}
@class(['active' => $isActive, 'disabled' => $isDisabled])
@style(['display: none' => $hidden])
@checked($isChecked)
@selected($isSelected)
@disabled($isDisabled)
@readonly($isReadonly)
@required($isRequired)
@once ... @endonce      {{-- render block only once per page --}}
@each('partial', $items, 'item')
@verbatim ... @endverbatim   {{-- skip Blade parsing --}}
@php ... @endphp        {{-- raw PHP — use for one-off logic instead of custom directives --}}
@dd($var)               {{-- debug dump + halt (debug mode only) --}}
@dump($var)             {{-- debug dump continue (debug mode only) --}}
```

### Security Notes for Views
- Always use `{{ }}` for user-supplied output — never `{!! !!}` unless data has been sanitized.
- `{!! $htmlBody !!}` is safe only for content passed through `safe_html` validation rule.
- Do not trust `@json($userInput)` without prior sanitization.

---

## 4. BootstrapDataTable (Standard CRUD List)

Reference: [23-javascript-api-helpers.md](../../../docs/framework_knowledge/23-javascript-api-helpers.md)

`BootstrapDataTable` is the standard pattern for all CRUD list pages. It handles
create/reload/mutation flows and server-side footer count tracking.

```javascript
// Minimal setup
const table = new BootstrapDataTable('#users-table', '/api/v1/users', [
    { data: 'name',   title: 'Name' },
    { data: 'email',  title: 'Email' },
    { data: 'status', title: 'Status' },
    { data: 'action', title: 'Action', orderable: false, searchable: false },
]);

// After a successful save (add new row or reload)
table.addRow(newRowData);     // Prepend row without full reload
table.reloadTable();          // Full server-side reload

// Remove a row (after delete confirmation)
table.removeRow(rowId);       // Remove row by id without reload
```

---

## 5. Core JavaScript API Functions

Reference: [23-javascript-api-helpers.md](../../../docs/framework_knowledge/23-javascript-api-helpers.md)

All functions are globally available from `public/general/js/helper.js`.

### callApi — general purpose

```javascript
// GET
const res = await callApi('get', '/api/v1/users?id=' + id);

// POST
const res = await callApi('post', '/api/v1/users/save', { name: 'Alice', email: 'a@x.com' });

// DELETE
const res = await callApi('delete', '/api/v1/users/delete/' + id);

if (isSuccess(res)) {
    noti(res.code, res.message);
} else {
    noti(res.code, res.message || 'An error occurred');
}
```

### submitApi — form submission with validation display

```javascript
// Submits a form, shows validation errors, optionally closes modal and reloads table
submitApi(
    '/api/v1/users/save',
    formData,
    '#user-form',           // form ID for error display
    () => table.reloadTable(),  // reload callback
    '#user-modal'           // modal to close on success
);
```

### confirmDeleteAction — safe delete with confirmation

```javascript
confirmDeleteAction({
    url: '/api/v1/users/delete/' + userId,
    onSuccess: () => table.removeRow(userId),
});
```

### uploadApi — file upload with progress

```javascript
uploadApi(
    '/api/v1/uploads/image-cropper',
    '#upload-form',
    '#progress-bar',
    () => table.reloadTable()
);
```

### Response checkers

```javascript
isSuccess(res)       // code 200
isError(res)         // code >= 400
isUnauthorized(res)  // code 401 or 403
```

### Notifications

```javascript
noti(200, 'User saved');      // green toast
noti(422, 'Validation error'); // red toast
showToast('Custom message', 'success'); // direct toast
```

---

## 6. Modal / Offcanvas Patterns

```javascript
// Load a partial Blade view into a shared modal
loadFileContent('users/form', '#shared-modal', 'md', 'Edit User', { id: userId });

// Open / close programmatically
showModal('#user-modal');
closeModal('#user-modal');
closeOffcanvas('#user-offcanvas');
```

---

## 7. Loading & Button States

```javascript
loading('#user-card', true);       // show BlockUI overlay
loading('#user-card', false);      // hide overlay
loadingBtn('#save-btn', true, 'Saving...');   // disable + spinner
loadingBtn('#save-btn', false, 'Save');       // re-enable
```

---

## 8. View Cache Commands

```bash
php myth view:cache    # Pre-compile all Blade templates
php myth view:clear    # Delete compiled view cache
```

---

## 9. View Security Checklist

- [ ] All user output uses `{{ }}` — never `{!! !!}` for untrusted data.
- [ ] `{!! $emailBody !!}` only where content passed through `safe_html` validation rule.
- [ ] `@can('permission')` gates used to hide privileged UI elements (defence-in-depth — route middleware is the real gate).
- [ ] `@csrf` in every browser form that submits `POST/PUT/PATCH/DELETE`.
- [ ] No custom Blade directives added at runtime — use `@php` blocks for one-off logic.
- [ ] Inline `@json($data)` only with sanitized/validated data, never raw request input.
