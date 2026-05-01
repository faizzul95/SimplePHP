# 04. Validation & FormRequest

## FormRequest (`Core\Http\FormRequest`)

### Lifecycle

- Router auto-instantiates FormRequest when controller parameter type extends `FormRequest`.
- `setRequest(...)` injects the active request.
- `validateResolved()` runs in this order:
	1. `authorize()` — returns 403 on failure
	2. `applyMaxInputLength()` — truncates oversized string input when configured
	3. `applySanitize()` — runs `sanitize()` chain before validation
	4. `prepareForValidation()` — custom normalization hook
	5. `validator(...)->passed()` — run rules + messages
	6. whitelist — keep only keys declared in `rules()`
	7. `applyDefaults()` — fill absent/null values from `defaults()`
	8. `applyCasts()` — coerce and normalize values from `casts()`
	9. `applyAliases()` — rename payload keys from `aliases()`
	10. `applyComputed()` — derive fields from processed payload
	11. `afterValidation()` — final payload mutation hook
- `all()` returns raw request input.
- `toDTO()`, `toArray()`, and `validated()` can all return the clean processed payload.
- Missing fields are always skipped by validation. A rule such as `required` is only evaluated when the key exists in the incoming payload.
- For browser requests, router-handled `422` validation failures redirect back to the previous page, flash validation errors, and flash old input while excluding password-style fields. JSON and AJAX requests still receive the structured `422` response body.

### FormRequest Public Methods

- `rules(): array` — **Abstract.** Define validation rules.
- `messages(): array` — Custom error messages (optional override).
- `authorize(): bool` — Authorization check (default: true).
- `maxInputLength(): int` — Optional per-field string length guard before validation.
- `sanitize(): array` — Optional pre-validation sanitizers using input field names.
- `prepareForValidation(): void` — Pre-process input (optional override).
- `defaults(): array` — Fill absent/null values before casts.
- `casts(): array` — Cast or normalize values after validation.
- `aliases(): array` — Rename validated keys after casts.
- `computed(): array` — Add derived fields after aliasing.
- `sensitiveFields(): array` — Mark keys that should be masked in `redacted()`.
- `validated(?string $key, $default)` — Get validated data (all or by key).
- `toDTO(): array` — Get the clean processed payload for service/repository calls.
- `toArray(): array` — Alias of `toDTO()`.
- `redacted(): array` — Get the processed payload with sensitive keys masked.
- `isUpdate(): bool` — True when input `id` is present and not empty/0.
- `isCreate(): bool` — Opposite of `isUpdate()`.
- `recordId(): string|array|null` — Returns current record ID, composite keys included.
- `all(): array` — Raw request input, including non-whitelisted fields.
- `input(?string $key, $default)` — Get specific input.
- `route(?string $key, $default)` — Get route parameter.
- `has(string $key): bool` — Check if input key exists.
- `only(array $keys): array` — Subset of **validated** data.
- `except(array $keys): array` — Validated data excluding keys.
- `withoutNull(): array` — Processed payload with `null` values removed.
- `withoutEmpty(): array` — Processed payload with `null`, `''`, and `[]` removed.
- `fill(array $data): static` — Inject trusted server-side values into the processed payload.
- `forgetValidated(string ...$keys): static` — Remove keys from the processed payload.
- `whenCreate(callable $callback): static` — Run callback only on create flow.
- `whenUpdate(callable $callback): static` — Run callback only on update flow.
- `pipe(callable $callback): static` — Replace processed payload using a callback.
- `tap(callable $callback): static` — Inspect processed payload without mutating it.
- `merge(array $data): static` — Merge data into underlying request.
- `method(): string` — HTTP method.
- `request(): ?Request` — Get underlying Request instance.
- `file(string $key): ?array` — Get uploaded file (`$_FILES` entry).
- `hasFile(string $key): bool` — Check if file was uploaded.
- `boolean(string $key, bool $default = false): bool` — Cast processed value to boolean.
- `integer(string $key, int $default = 0): int` — Cast validated value to int.

### Raw Input vs DTO Output

- Use `all()` when you need untouched request input.
- Use `request()` when you need low-level `Request` methods not exposed on FormRequest.
- Use `toDTO()` when you want an explicit DTO-style method name.
- Use `validated()` when you prefer the existing FormRequest API, including field-level reads.
- Use `toArray()` when existing code already expects that method name.
- Mark optional hidden IDs and optional inputs as `nullable|...` and normalize browser-submitted `''` to `null` in `prepareForValidation()` when you need nullable/default behavior to kick in.
- Prefer shaping create/update defaults in the request class using `defaults()`, `casts()`, `computed()`, or `afterValidation()` instead of rebuilding the payload in the controller.

### Built-in Sanitizers

- `trim` — Remove leading and trailing whitespace.
- `strip_tags` — Remove HTML and PHP tags.
- `html_encode` — Encode HTML entities for safe output storage.
- `html_decode` — Decode HTML entities back to characters.
- `no_null_bytes` — Remove `\0` bytes.
- `control_chars` — Remove ASCII control characters except tab/newline.
- `normalize_spaces` — Collapse repeated whitespace into single spaces.
- `normalize_newlines` — Convert CRLF/CR line endings to LF.
- `lowercase` — Convert to lowercase.
- `uppercase` — Convert to uppercase.
- `ucfirst` — Lowercase the string, then uppercase the first character.
- `ucwords` — Lowercase the string, then uppercase each word.
- `digits_only` — Strip everything except digits `0-9`.
- `alpha_num` — Strip everything except letters and digits.
- `filename_safe` — Keep only letters, digits, `.`, `_`, and `-`.
- `slug` — Lowercase, replace non-alphanumeric runs with `-`, and trim leading/trailing hyphens.

Sanitizers are string-only and run left to right. Unknown sanitizer names are ignored.

### Sanitizer Notes

- `sanitize()` runs before `prepareForValidation()`, so use `sanitize()` for reusable field-level cleanup and `prepareForValidation()` for conditional normalization.
- `sanitize()` only touches string values. Arrays, integers, booleans, uploaded files, and `null` pass through unchanged.
- `slug` in `sanitize()` and `slug` in `casts()` now both trim leading and trailing hyphens after normalization.
- `filename_safe` is useful for user-provided labels or generated export names, but it is not a substitute for `secure_filename` validation on uploaded files.
- `digits_only` is useful for phone numbers, OTP codes, and national IDs when you want to keep formatting characters out of persistence fields.
- `alpha_num` removes separators entirely. Use it only when dropping spaces, dashes, and punctuation is intentional.

### Nullable Input Caveat

The validation engine treats `nullable` as “skip validation when the value is actually `null`”. Browsers commonly submit blank optional inputs as `''`, not `null`. That means this pattern is still unsafe on its own:

```php
'id' => 'nullable|numeric'
```

If the browser posts `id=''`, validation can still hit the `numeric` rule and fail, or the field can bypass `defaults()` behavior you expected. Normalize blank optional fields in `prepareForValidation()` when the field may be posted as an empty string.

If the field is completely absent from the request payload, it is ignored by validation. `required` only fails when the key exists but its value is empty, blank, or null.

```php
protected function prepareForValidation(): void
{
	$nullableFields = ['id', 'username', 'notes'];
	$mutations = [];

	foreach ($nullableFields as $field) {
		if ($this->input($field) === '') {
			$mutations[$field] = null;
		}
	}

	if (!empty($mutations)) {
		$this->merge($mutations);
	}
}
```

### How To Use DTO Support

1. Put payload shaping in the FormRequest class, not in a separate DTO class.
2. Inject the FormRequest into the controller as usual.
3. Use `all()` for untouched request data.
4. Use `validated()`, `toArray()`, or `toDTO()` for the processed payload.
5. Choose `toDTO()` only when you want the method name itself to make the DTO role obvious.

### Redirect-Back Validation UX

For classic browser form posts, validation failures now behave closer to Laravel:

```blade
<input type="email" name="email" value="{{ old('email') }}">

@error('email')
	<div class="text-danger">{{ $message }}</div>
@enderror
```

This redirect-back flow is for normal browser submissions. It does not automatically repopulate forms that are posted and re-rendered entirely through JavaScript/XHR; those still need frontend-side handling of the returned JSON error payload.

```php
public function store(StoreUserRequest $request): array
{
	$rawInput = $request->all();      // untouched request payload
	$dto = $request->toDTO();         // same processed payload as validated()
	$email = $request->validated('email');

	$this->userService->create($dto);

	return ['success' => true];
}
```

### Lifecycle Decision Guide

Use each hook for a specific job so the request pipeline stays predictable:

- `sanitize()` for deterministic string cleanup that is safe to apply on every request.
- `prepareForValidation()` for conditional input normalization before rules run.
- `defaults()` for optional values that should exist in the processed payload when omitted or normalized to `null`.
- `casts()` for type coercion and normalized output formatting after validation succeeds.
- `aliases()` when controller/service code should receive different key names than the frontend posts.
- `computed()` for derived fields that depend on already-valid processed input.
- `afterValidation()` for final mutations such as hashing, removing fields, or create/update-specific shaping.

Good rule of thumb:

- If the change must happen before validation rules see the value, use `sanitize()` or `prepareForValidation()`.
- If the change should only happen after the value is known-valid, use `casts()`, `computed()`, or `afterValidation()`.

## Validation Engine (`Components\Validation`)

### Complete Rule List (Verified from Source)

**Type rules:**
`required`, `string`, `numeric`, `integer`, `boolean`, `array`, `json`, `uuid`, `date`, `file`, `image`, `nullable`, `sometimes`, `accepted`

**Format rules:**
`email`, `url`, `ip`, `alpha`, `alpha_num`, `alpha_dash`, `regex`, `date_format`, `base64`

**Size / range rules:**
`min`, `max`, `min_length`, `max_length`, `between`, `size`, `digits`, `digits_between`

**Comparison rules:**
`same`, `different`, `confirmed`, `gt`, `gte`, `lt`, `lte`, `in`, `not_in`, `distinct`, `starts_with`, `ends_with`, `before`, `after`, `date_equals`

**Conditional rules:**
`required_if`, `required_unless`, `required_with`, `required_without`, `prohibited_if`

**Security rules:**
`xss`, `safe_html`, `no_sql_injection`, `secure_value`, `secure_filename`, `file_extension`, `max_file_size`, `password`

**Array deep rules:**
`deep_array`, `array_keys`, `array_values`

**Image dimension rules:**
`min_image_width`, `max_image_width`, `min_image_height`, `max_image_height`

**File rules:**
`mimes`

### Validation API

- `addRule(string $name, callable $callback, string $message)` — Register custom rule.
- `beforeValidation(callable $cb)` — Hook before validation starts.
- `afterValidation(callable $cb)` — Hook after validation finishes.
- `passed(): bool` — True if validation passed.
- `status(): array` — Full status with passed flag + errors.
- `getErrors(): array` — All field errors.
- `getFirstError(): string` — First error message.
- `getLastError(): string` — Last error message.
- `validateBatch(array $datasets): array` — Validate multiple datasets at once.

### XSS Detection

Built-in XSS patterns detect: script/iframe/svg tags, event handlers (`onclick`, `onload`, etc.), javascript/vbscript protocols, data URIs with executable types, CSS @import, Base64 encoded scripts, URL-encoded bypasses.

### Trusted HTML

Use `safe_html` only for fields that are intentionally allowed to store trusted HTML, such as email templates, receipt or invoice layouts, print templates, or curated CMS fragments.

- `safe_html` is for trusted rich-text storage, not arbitrary user comments.
- It allows a constrained tag set suitable for rendered template markup.
- It rejects executable tags, event-handler attributes, unsafe `href` and `src` schemes, PHP tags, and dangerous inline CSS.
- Keep generic text fields on `secure_value` or `xss` instead of switching them to `safe_html`.
- For static templates stored in config files, validate or review the template at definition time and treat the config source as trusted application code, not user input.

Recommended pattern for trusted template fields:

```php
'template_body' => 'required|string|min_length:5|max_length:200000|safe_html'
```

If you need to preserve markup, do not add `strip_tags` or `html_encode` sanitizers to that field.

Common template sources:

- Database-backed templates edited by admins.
- Config-backed templates for receipts, invoices, letters, or print layouts.
- Seeded default templates later overridden through admin tooling.

### SQL Injection Detection

`no_sql_injection` rule blocks: UNION SELECT, DROP TABLE, INSERT INTO, DELETE FROM, admin'-- patterns, OR 1=1, xp_cmdshell, BENCHMARK, SLEEP patterns.

## Examples

### 1) FormRequest with conditional create/update rules

```php
class StoreUserRequest extends \Core\Http\FormRequest
{
	public function rules(): array
	{
		$rules = [
			'name'  => 'required|string|max:255',
			'email' => 'required|email|max:255',
			'role_id' => 'required|integer',
		];

		if ($this->isCreate()) {
			$rules['password'] = 'required|string|min:8|confirmed';
		}

		if ($this->isUpdate()) {
			$rules['password'] = 'nullable|string|min:8|confirmed';
		}

		return $rules;
	}

	public function messages(): array
	{
		return [
			'email.required' => 'Email address is required.',
			'password.confirmed' => 'Password confirmation does not match.',
		];
	}
}
```

### 2) Using FormRequest in controller

```php
public function store(StoreUserRequest $request): void
{
	$payload = $request->toDTO();
	// $payload is the clean processed DTO after validation, defaults, casts, aliases, and computed fields

	$isUpdate = $request->isUpdate();
	$id = $request->recordId(); // null on create, "123" on update
	// ...
}
```

### 2a) FormRequest as DTO replacement

```php
class SaveRoleRequest extends \Core\Http\FormRequest
{
	public function rules(): array
	{
		return [
			'id' => 'nullable|numeric',
			'role_name' => 'required|string|min_length:3|max_length:64',
			'role_rank' => 'required|numeric|min:1',
			'role_status' => 'required|integer|in:0,1',
			'contact_email' => 'nullable|email',
		];
	}

	public function sanitize(): array
	{
		return [
			'role_name' => 'trim|strip_tags|normalize_spaces',
			'contact_email' => 'trim|lowercase',
			'contact_slug' => 'trim|slug',
		];
	}

	public function defaults(): array
	{
		return [
			'id' => null,
			'role_status' => 1,
		];
	}

	public function casts(): array
	{
		return [
			'id' => 'int',
			'role_name' => 'trim|ucwords',
			'role_rank' => 'int',
			'role_status' => 'int',
			'contact_email' => 'trim|lowercase',
		];
	}

	public function aliases(): array
	{
		return [
			'role_name' => 'name',
			'role_rank' => 'rank',
			'role_status' => 'status',
			'contact_email' => 'email',
		];
	}

	public function computed(): array
	{
		return [
			'updated_at' => fn(array $data) => date('Y-m-d H:i:s'),
		];
	}
}

public function save(SaveRoleRequest $request): array
{
	$dto = $request->toDTO();
	$this->roleService->save($dto);

	return ['success' => true];
}
```

### 3) Authorization in FormRequest

```php
public function authorize(): bool
{
	return auth()->can('user-create');
}
```

You can also branch authorization by request mode:

```php
public function authorize(): bool
{
	if ($this->isCreate()) {
		return auth()->can('user-create');
	}

	if ($this->isUpdate()) {
		return auth()->can('user-update');
	}

	return false;
}
```

This is useful when create and update share one endpoint and the permission requirement changes based on whether a primary key is present. Keep the permission slugs aligned with the route middleware that protects the same endpoint.

### 3a) Custom primary key and composite key detection

```php
class SaveTenantUserRequest extends \Core\Http\FormRequest
{
	public function primaryKey(): string|array
	{
		return ['tenant_id', 'user_id'];
	}

	public function rules(): array
	{
		return [
			'tenant_id' => 'required|integer|min:1',
			'user_id' => 'required|integer|min:1',
			'role_id' => 'required|integer|min:1',
		];
	}
}

$request->isUpdate();
// true only when both tenant_id and user_id are present and non-empty

$request->recordId();
// ['tenant_id' => '12', 'user_id' => '98']
```

Use `primaryKey()` when the request should identify an existing record by something other than a single `id` field.

### 4) Input mutation before validation

```php
protected function prepareForValidation(): void
{
	$this->merge([
		'email' => strtolower(trim($this->input('email'))),
		'name'  => trim($this->input('name')),
	]);
}
```

### 4c) Aliases and computed fields for service-ready DTOs

```php
class SaveInvoiceRequest extends \Core\Http\FormRequest
{
	public function rules(): array
	{
		return [
			'customer_name' => 'required|string|max_length:255',
			'customer_email' => 'required|email|max_length:255',
			'invoice_total' => 'required|numeric|min:0',
			'invoice_status' => 'required|string|in:draft,sent,paid',
		];
	}

	public function casts(): array
	{
		return [
			'customer_name' => 'trim|ucwords',
			'customer_email' => 'trim|lowercase',
		];
	}

	public function aliases(): array
	{
		return [
			'customer_name' => 'name',
			'customer_email' => 'email',
			'invoice_total' => 'total',
			'invoice_status' => 'status',
		];
	}

	public function computed(): array
	{
		return [
			'issued_at' => fn(array $data) => date('Y-m-d H:i:s'),
			'is_finalized' => fn(array $data) => $data['status'] !== 'draft',
		];
	}
}
```

After the full pipeline, `toDTO()` returns a service-friendly shape:

```php
[
	'name' => 'Acme Sdn Bhd',
	'email' => 'billing@acme.test',
	'total' => '199.90',
	'status' => 'sent',
	'issued_at' => '2026-04-11 08:30:00',
	'is_finalized' => true,
]
```

This is useful when the browser payload should stay frontend-oriented, but the service layer wants clean domain-oriented field names.

### 4d) Using computed fields with aliases safely

Computed fields run after aliases, so read alias target names from the `$data` array:

```php
public function aliases(): array
{
	return [
		'role_name' => 'name',
		'role_status' => 'status',
	];
}

public function computed(): array
{
	return [
		'display_label' => fn(array $data) => $data['name'] . ' [' . $data['status'] . ']',
	];
}
```

If you try to read `role_name` inside `computed()`, that key no longer exists because aliasing has already run.

### 4a) Conditional create/update shaping

```php
class SaveUserRequest extends \Core\Http\FormRequest
{
	public function rules(): array
	{
		return [
			'id' => 'nullable|numeric',
			'name' => 'required|string|min_length:3|max_length:255',
			'email' => 'required|email|max_length:255',
			'username' => 'nullable|string|min_length:3|max_length:255',
			'password' => 'nullable|string|min_length:8|max_length:255',
		];
	}

	protected function prepareForValidation(): void
	{
		$mutations = [];

		foreach (['id', 'username', 'password'] as $field) {
			if ($this->input($field) === '') {
				$mutations[$field] = null;
			}
		}

		if (!empty($mutations)) {
			$this->merge($mutations);
		}
	}

	public function defaults(): array
	{
		return [
			'id' => null,
			'username' => null,
			'password' => null,
		];
	}

	public function casts(): array
	{
		return [
			'id' => 'int',
			'email' => 'trim|lowercase',
			'username' => 'nullable_string',
			'password' => 'nullable_string',
		];
	}

	protected function afterValidation(): void
	{
		if ($this->isCreate() && empty($this->validatedData['username'])) {
			$this->validatedData['username'] = $this->validatedData['email'];
		}

		if ($this->isUpdate()) {
			unset($this->validatedData['password']);
		}
	}
}
```

This pattern is useful when create and update share the same endpoint but still need different payload shaping after validation.

### 4b) Sanitizer chaining for contact fields

```php
public function sanitize(): array
{
	return [
		'full_name' => 'trim|strip_tags|normalize_spaces|ucwords',
		'email' => 'trim|lowercase',
		'phone' => 'trim|digits_only',
		'export_name' => 'trim|filename_safe',
		'profile_slug' => 'trim|slug',
		'bio' => 'trim|strip_tags|normalize_newlines',
	];
}
```

This keeps request-specific normalization close to the rules instead of scattering string cleanup in controllers or services.

### 5) Standalone validator with custom rule

```php
$v = validator($data, [
	'username' => 'required|alpha_dash|min:3|max:30',
	'bio' => 'nullable|string|xss|max:500',
	'avatar' => 'file|image|max_file_size:2048|mimes:jpg,png,webp',
]);

$v->addRule('no_profanity', function ($field, $value, $params, $data) {
	$banned = ['badword1', 'badword2'];
	return !in_array(strtolower($value), $banned);
}, 'The :field contains inappropriate language.');

if (!$v->passed()) {
	$errors = $v->getErrors();
	$firstError = $v->getFirstError();
}
```

### 6) Validation hooks

```php
validator($data, $rules)
	->beforeValidation(function () use (&$data) {
		$data['email'] = strtolower($data['email']);
	})
	->afterValidation(function () {
		// Log validation completion
	});
```

	### 6a) Sensitive field masking for logs

	```php
	class ResetPasswordRequest extends \Core\Http\FormRequest
	{
		public function rules(): array
		{
			return [
				'email' => 'required|email',
				'password' => 'required|string|min_length:8',
				'token' => 'required|string',
			];
		}

		public function sensitiveFields(): array
		{
			return ['password', 'token'];
		}
	}

	$payloadForAudit = $request->redacted();
	// ['email' => 'user@example.com', 'password' => '***', 'token' => '***']
	```

	Use `redacted()` when logging, auditing, or debugging processed payloads. Use `validated()` or `toDTO()` for actual persistence logic.

	### 6b) Choosing the right payload helper

	```php
	$allInput = $request->all();          // raw request input
	$dto = $request->toDTO();             // full processed payload
	$insertData = $request->withoutNull(); // omit nulls, keep empty strings if intentional
	$patchData = $request->withoutEmpty(); // omit null, '', and []
	```

	Use `withoutNull()` when DB defaults should fill in absent optional values. Use `withoutEmpty()` for patch-style updates when blank values should not overwrite existing records.

	### 6c) Insert vs patch example

	```php
	public function save(ProfileRequest $request): array
	{
		if ($request->isCreate()) {
			$data = $request->withoutNull();
			$this->profileService->create($data);
			return ['success' => true, 'mode' => 'create'];
		}

		$data = $request->withoutEmpty();
		$this->profileService->update((int) $request->validated('id'), $data);

		return ['success' => true, 'mode' => 'update'];
	}
	```

	This prevents accidental overwrites during partial updates while still allowing DB defaults to work on inserts.

	### 6d) Inspecting cast and computed failures

	```php
	$dto = $request->toDTO();

	if ($request->hasCastFailures()) {
		logger()->warning('Request cast failures', $request->getCastFailures());
	}

	if ($request->hasComputedFailures()) {
		logger()->warning('Request computed failures', $request->getComputedFailures());
	}
	```

	This is mainly useful for debugging unexpected payload shaping behavior. The request still completes unless your own application logic treats those failures as fatal.

### 7) Batch validation

```php
$results = validator([], [])->validateBatch([
	['data' => $row1, 'rules' => ['name' => 'required', 'email' => 'required|email']],
	['data' => $row2, 'rules' => ['name' => 'required', 'email' => 'required|email']],
]);
// Each result has: passed, errors
```

### 8) Security-focused validation

```php
$rules = [
	'comment'  => 'required|string|xss|no_sql_injection|max:1000',
	'filename' => 'required|secure_filename|file_extension:jpg,png,pdf',
	'upload'   => 'file|max_file_size:5120|mimes:jpg,png,pdf',
	'html_bio' => 'nullable|safe_html',
];
```

## How To Use

1. Define one FormRequest per endpoint intent (store, update, reset, etc.).
2. Use `validated()`, `toArray()`, or `toDTO()` based on which API reads best in that controller.
3. Use `all()` only when you intentionally need raw request data.
4. Use `isCreate()` / `isUpdate()` to conditionally apply different rules.
5. Use `sanitize()`, `defaults()`, `casts()`, `aliases()`, and `computed()` to shape the payload inside the request class.
6. Use `prepareForValidation()` for custom normalization that must happen before rules run.
7. Use security rules (`xss`, `no_sql_injection`, `secure_filename`) for user-generated content.
8. Use `addRule()` for project-specific custom rules.
9. Keep controllers from mixing processed payload access with raw `input()` for persistence fields unless a field is intentionally excluded from the request rules.
10. When the browser may submit blank optional inputs, normalize `''` to `null` before validation instead of relying on `nullable` alone.
11. Use `sensitiveFields()` and `redacted()` when request payloads may be logged or inspected outside the persistence path.

## What To Avoid

- Avoid reading raw input for persistence fields when the processed payload is already available through `validated()`, `toArray()`, or `toDTO()`.
- Avoid duplicating validation logic across controllers — centralize in FormRequest.
- Avoid creating a separate DTO class for the same endpoint when FormRequest already shapes the payload you need.
- Avoid overly permissive rules when exact formats are known.
- Avoid using `{!! !!}` in Blade for fields not validated with `xss` or `safe_html`.
- Avoid putting conditional business rules in controllers when the condition depends only on request input shape or request mode.
- Avoid using `sanitize()` as if it were validation; sanitizers normalize values, they do not guarantee those values are acceptable.

## Troubleshooting

- Validation failing on optional numeric or ID fields: the browser probably posted `''`; normalize blank strings to `null` in `prepareForValidation()`.
- Defaults not showing up in `validated()`: the key may still be a non-null empty string, so `defaults()` will not replace it.
- `redacted()` not masking a field: `sensitiveFields()` must use post-alias field names, not raw input names.
- `computed()` reading the wrong key: computed fields run after aliases, so read alias target names from `$data`.
- Unexpected original value preserved after casting: check `getCastFailures()` to see which cast step failed and why.

## Benefits

- Consistent validation lifecycle and error shape across all endpoints.
- Raw input and processed payload access are clearly separated.
- Comprehensive security rules built-in (XSS, SQL injection, filename safety).
- Clean controller code — validation, normalization, and DTO shaping live in one place.
- Extensible through custom rules and hooks.

## Evidence

- `systems/Core/Http/FormRequest.php`
- `systems/Components/Validation.php` (2786 lines)
- `systems/Core/Routing/Router.php` (FormRequest auto-resolution in `invokeCallable`)
