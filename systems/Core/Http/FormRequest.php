<?php

namespace Core\Http;

abstract class FormRequest
{
    protected Request $request;
    protected array $validatedData = [];

    public function setRequest(Request $request): static
    {
        $this->request = $request;
        return $this;
    }

    /**
     * Check if this is an update request (ID is present and not empty).
     * Useful for conditional validation rules.
     *
     * Usage in rules():
     *   'email' => $this->isUpdate() ? 'required|email' : 'required|email|unique:users,email'
     */
    public function isUpdate(): bool
    {
        $id = $this->request->input('id');
        return !empty($id) && $id !== '0';
    }

    /**
     * Check if this is a create (insert) request (no ID present).
     * Opposite of isUpdate().
     */
    public function isCreate(): bool
    {
        return !$this->isUpdate();
    }

    /**
     * Get the current record ID when updating, or null when creating.
     */
    public function recordId(): ?string
    {
        $id = $this->request->input('id');
        return (!empty($id) && $id !== '0') ? (string) $id : null;
    }

    /**
     * Determine if the user is authorized to make this request.
     * Override in subclasses to add authorization logic.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Prepare the data for validation.
     * Override to modify input before validation runs.
     */
    protected function prepareForValidation(): void
    {
        // Override in subclass if needed
    }

    /**
     * Validate the request and populate validatedData.
     * Called automatically by the Router when a FormRequest is type-hinted.
     */
    public function validateResolved(): void
    {
        // Check authorization first
        if (!$this->authorize()) {
            throw new ValidationException('This action is unauthorized.', [], 403);
        }

        // Allow subclasses to mutate input before validation
        $this->prepareForValidation();

        $rules = $this->rules();

        // Guard: if rules() returns empty, skip validation but still populate data
        if (empty($rules)) {
            $this->validatedData = [];
            return;
        }

        $validator = validator($this->request->all(), $rules, $this->messages());

        if (!$validator->passed()) {
            $errors = $validator->getErrors();
            $message = $validator->getFirstError();

            if ($message === '') {
                $message = 'Validation failed.';
            }

            throw new ValidationException($message, $errors, 422);
        }

        // Only keep fields that are defined in rules() (like Laravel)
        $ruleKeys = array_keys($rules);
        $allData = $this->request->all();
        $this->validatedData = [];

        foreach ($ruleKeys as $key) {
            if (array_key_exists($key, $allData)) {
                $this->validatedData[$key] = $allData[$key];
            }
        }
    }

    /**
     * Get validated data, optionally by key.
     */
    public function validated(?string $key = null, $default = null)
    {
        if ($key === null) {
            return $this->validatedData;
        }

        return $this->validatedData[$key] ?? $default;
    }

    /**
     * Get all request input (including non-validated fields).
     */
    public function all(): array
    {
        return $this->request->all();
    }

    /**
     * Get a specific input value from the request.
     */
    public function input(?string $key = null, $default = null)
    {
        return $this->request->input($key, $default);
    }

    /**
     * Get a route parameter value.
     */
    public function route(?string $key = null, $default = null)
    {
        return $this->request->route($key, $default);
    }

    /**
     * Check if the request has a given input key.
     */
    public function has(string $key): bool
    {
        return $this->request->has($key);
    }

    /**
     * Get a subset of validated data by keys.
     */
    public function only(array $keys): array
    {
        return array_intersect_key($this->validatedData, array_flip($keys));
    }

    /**
     * Get validated data except the specified keys.
     */
    public function except(array $keys): array
    {
        return array_diff_key($this->validatedData, array_flip($keys));
    }

    /**
     * Merge additional data into the request input.
     */
    public function merge(array $data): static
    {
        $this->request->merge($data);
        return $this;
    }

    /**
     * Get the HTTP method (GET, POST, PUT, etc.).
     */
    public function method(): string
    {
        return $this->request->method();
    }

    /**
     * Get the underlying Request instance.
     */
    public function request(): Request
    {
        return $this->request;
    }

    /**
     * Get a file from the request ($_FILES).
     *
     * @param string $key The file input name
     * @return array|null  The $_FILES entry or null
     */
    public function file(string $key): ?array
    {
        return $_FILES[$key] ?? null;
    }

    /**
     * Check if the request has an uploaded file.
     */
    public function hasFile(string $key): bool
    {
        return isset($_FILES[$key]) && $_FILES[$key]['error'] !== UPLOAD_ERR_NO_FILE;
    }

    /**
     * Cast an input value to boolean.
     * Treats '1', 'true', 'on', 'yes' as true; everything else as false.
     */
    public function boolean(string $key, bool $default = false): bool
    {
        $value = $this->request->input($key);
        if ($value === null) {
            return $default;
        }
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Get a validated integer value.
     */
    public function integer(string $key, int $default = 0): int
    {
        $value = $this->validated($key);
        return $value !== null ? (int) $value : $default;
    }

    /**
     * Define validation rules.
     */
    abstract public function rules(): array;

    /**
     * Custom validation error messages.
     */
    public function messages(): array
    {
        return [];
    }
}
