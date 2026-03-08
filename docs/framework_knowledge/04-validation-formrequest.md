# 04. Validation & FormRequest

## FormRequest (`Core\Http\FormRequest`)

### Lifecycle

- Router auto-instantiates FormRequest when controller parameter type extends `FormRequest`.
- `setRequest(...)` injects the active request.
- `validateResolved()` runs in this order:
  1. `authorize()` (default true) — returns 403 on failure
  2. `prepareForValidation()` — mutate input before rules run
  3. `validator(...)->passed()` — run rules + messages
  4. throws `ValidationException` (422 or 403) on failure
- `validated()` only returns keys explicitly declared in `rules()`.

### FormRequest Public Methods

- `rules(): array` — **Abstract.** Define validation rules.
- `messages(): array` — Custom error messages (optional override).
- `authorize(): bool` — Authorization check (default: true).
- `prepareForValidation(): void` — Pre-process input (optional override).
- `validated(?string $key, $default)` — Get validated data (all or by key).
- `isUpdate(): bool` — True when input `id` is present and not empty/0.
- `isCreate(): bool` — Opposite of `isUpdate()`.
- `recordId(): ?string` — Returns current record ID string, or null on create.
- `all(): array` — All request input (including non-validated).
- `input(?string $key, $default)` — Get specific input.
- `route(?string $key, $default)` — Get route parameter.
- `has(string $key): bool` — Check if input key exists.
- `only(array $keys): array` — Subset of **validated** data.
- `except(array $keys): array` — Validated data excluding keys.
- `merge(array $data): static` — Merge data into underlying request.
- `method(): string` — HTTP method.
- `request(): Request` — Get underlying Request instance.
- `file(string $key): ?array` — Get uploaded file (`$_FILES` entry).
- `hasFile(string $key): bool` — Check if file was uploaded.
- `boolean(string $key, bool $default = false): bool` — Cast input to boolean.
- `integer(string $key, int $default = 0): int` — Cast validated value to int.

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
	$payload = $request->validated();
	// $payload only contains keys defined in rules()

	$isUpdate = $request->isUpdate();
	$id = $request->recordId(); // null on create, "123" on update
	// ...
}
```

### 3) Authorization in FormRequest

```php
public function authorize(): bool
{
	return permission('user-create');
}
```

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
2. Keep controller logic dependent on `$request->validated()` only.
3. Use `isCreate()` / `isUpdate()` to conditionally apply different rules.
4. Use `prepareForValidation()` for input normalization (trim, lowercase).
5. Use security rules (`xss`, `no_sql_injection`, `secure_filename`) for user-generated content.
6. Use `addRule()` for project-specific custom rules.

## What To Avoid

- Avoid reading raw input for validated fields — always use `validated()`.
- Avoid duplicating validation logic across controllers — centralize in FormRequest.
- Avoid overly permissive rules when exact formats are known.
- Avoid using `{!! !!}` in Blade for fields not validated with `xss` or `safe_html`.

## Benefits

- Consistent validation lifecycle and error shape across all endpoints.
- Comprehensive security rules built-in (XSS, SQL injection, filename safety).
- Clean controller code — validation is fully separated.
- Extensible through custom rules and hooks.

## Evidence

- `systems/Core/Http/FormRequest.php` (251 lines)
- `systems/Components/Validation.php` (2786 lines)
- `systems/Core/Routing/Router.php` (FormRequest auto-resolution in `invokeCallable`)
