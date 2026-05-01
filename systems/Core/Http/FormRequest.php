<?php

namespace Core\Http;

/**
 * FormRequest — validates, sanitizes, casts, aliases, and shapes HTTP input
 * into a clean, typed payload before it reaches your service or repository.
 *
 * Acts as a full DTO replacement. No separate DTO class needed.
 *
 * Pipeline (runs automatically via validateResolved()):
 *   [guard: request initialized]
 *   → authorize()              abort with 403 if not permitted
 *   → applyMaxInputLength()    truncate oversized strings (security guard)
 *   → applySanitize()          apply pre-validation security sanitizers
 *   → prepareForValidation()   custom hook for further normalization
 *   → validate rules()         throw 422 ValidationException on failure
 *   → whitelist                drop any field not in rules()
 *   → applyDefaults()          fill absent/null optional fields
 *   → applyCasts()             type coercion with chained cast support
 *   → applyAliases()           rename keys; syncs castFailures keys
 *   → applyComputed()          derive new fields from validated data
 *   → afterValidation()        final mutations (hashing, cleanup)
 */
abstract class FormRequest
{
    // ─────────────────────────────────────────────────────────────────────────
    // CONSTANTS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Cast types that treat empty string as null.
     * Casting '' to 0 or false is a silent wrong value.
     * Extracted as a constant so it's not rebuilt on every applyCasts() call.
     */
    private const EMPTY_TO_NULL_CASTS = [
        'int'      => true,
        'integer'  => true,
        'float'    => true,
        'double'   => true,
        'bool'     => true,
        'boolean'  => true,
    ];

    // ─────────────────────────────────────────────────────────────────────────
    // PROPERTIES
    // ─────────────────────────────────────────────────────────────────────────

    protected ?Request $request = null;

    /** @var array<string, mixed> Final shaped output */
    protected array $validatedData = [];

    /** @var array<string, array{type: string, original: mixed, reason: string}> */
    protected array $castFailures = [];

    /** @var array<string, array{reason: string}> */
    protected array $computedFailures = [];

    /**
     * Cached normalized primary keys.
     * null = not yet built. Rebuilt each time setRequest() is called.
     *
     * @var array<int, string>|null
     */
    private ?array $normalizedPrimaryKeys = null;

    // ─────────────────────────────────────────────────────────────────────────
    // ABSTRACT
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Validation rules. Also acts as the whitelist —
     * any field not listed here is dropped automatically.
     *
     * @return array<string, string>
     */
    abstract public function rules(): array;

    // ─────────────────────────────────────────────────────────────────────────
    // OVERRIDE IN SUBCLASS (all optional)
    // ─────────────────────────────────────────────────────────────────────────

    /** Custom validation error messages. */
    public function messages(): array
    {
        return [];
    }

    /**
     * Authorization check — runs BEFORE validation.
     * Return false to throw a 403 ValidationException.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Primary key field(s) used to detect create vs update.
     * Always use INPUT field names (pre-alias).
     *
     * Examples:
     *   return 'id';                       // auto-increment (default)
     *   return 'uuid';                     // UUID column
     *   return 'role_code';               // natural key
     *   return ['tenant_id', 'user_id'];  // composite key
     *
     * Empty string / empty array → isUpdate() = false, recordId() = null.
     *
     * @return string|array<int, string>
     */
    public function primaryKey(): string|array
    {
        return 'id';
    }

    /**
     * Maximum byte length for any single string input value.
     * Applied BEFORE sanitize() and rules() — prevents memory exhaustion
     * attacks from oversized inputs (e.g. a 10 MB "name" field).
     *
     * 0 = no limit (not recommended for public-facing endpoints).
     *
     * Example: return 10000; // 10 KB max per field
     */
    public function maxInputLength(): int
    {
        return 0;
    }

        /**
         * Security sanitizers applied to raw input BEFORE validation rules run.
         * Each field maps to one or more sanitizer names (pipe-separated or array).
         *
         * ⚠ Use INPUT field names (pre-alias).
         * ⚠ Sanitizers run IN ORDER, left to right.
         * ⚠ Non-string values are passed through unchanged.
         *
         * Available sanitizers:
         *   trim               strip leading/trailing whitespace
         *   strip_tags         remove all HTML/PHP tags
         *   html_encode        convert <, >, &, ", ' to HTML entities (XSS safe output)
         *   html_decode        convert HTML entities back to characters
         *   no_null_bytes      remove null bytes (\0) — prevents null byte injection
         *   control_chars      remove all control characters (ASCII < 32, except tab/newline)
         *   normalize_spaces   collapse multiple consecutive spaces into one
         *   normalize_newlines normalize CRLF/CR to LF
         *   lowercase          strtolower
         *   uppercase          strtoupper
         *   ucfirst            ucfirst(strtolower())
         *   ucwords            ucwords(strtolower())
         *   digits_only        keep digits only (0-9)
         *   alpha_num          keep letters and digits only
         *   filename_safe      keep letters, digits, dot, underscore, and hyphen
         *   slug               lowercase + replace non-alphanumeric with hyphens
         *
         * Example:
         *   public function sanitize(): array
         *   {
         *       return [
         *           'name'  => 'trim|strip_tags|normalize_spaces',
         *           'email' => ['trim', 'lowercase'],
         *           'bio'   => ['trim', 'strip_tags', 'html_encode'],
         *       ];
         *   }
         *
         * @return array<string, string|array<int, string>>
         */
    public function sanitize(): array
    {
        return [];
    }

    /**
     * Pre-validation hook — called AFTER sanitize() and maxInputLength(),
     * BEFORE validation rules run.
     *
     * Use $this->merge([...]) to normalize input here.
     *
     * Example:
     *   protected function prepareForValidation(): void
     *   {
     *       $this->merge([
     *           'username' => preg_replace('/\s+/', '', $this->input('username', '')),
     *       ]);
     *   }
     */
    protected function prepareForValidation(): void {}

    /**
     * Default values for absent/null optional fields.
     * Applied AFTER whitelist, BEFORE casts.
     * Never overwrites a submitted non-null value.
     *
     * ⚠ Use INPUT field names (pre-alias).
     *
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return [];
    }

    /**
     * Type casts applied AFTER defaults, BEFORE aliases.
     *
     * ⚠ Use INPUT field names (pre-alias).
     *   If 'role_name' aliases to 'name', cast 'role_name', not 'name'.
     *
     * CHAINED CASTS — apply multiple transforms left to right:
     *   'role_name' => 'trim|ucwords'          pipe-separated string
     *   'role_name' => ['trim', 'ucwords']     array syntax
     *
     *   If a step in the chain fails, the chain stops and the failure is logged.
     *   If a step produces null, the chain stops and null is preserved.
     *
     * Single cast types:
     *   int | integer       non-numeric string → null + castFailures logged
     *   float | double      non-numeric string → null + castFailures logged
     *   bool | boolean      FILTER_VALIDATE_BOOLEAN / NULL_ON_FAILURE
     *                       truthy:  true, 1, '1', 'on', 'yes', 'true'
     *                       falsy:   false, 0, '0', 'off', 'no', 'false'
     *                       other → null + castFailures logged
     *   string              always safe, (string) cast
     *   array               wraps non-array scalar in []
     *   json                JSON string → PHP array; invalid → logged
     *   trim                whitespace trim, strings only
     *   uppercase           strtoupper, strings only
     *   lowercase           strtolower, strings only
     *   ucfirst             ucfirst(strtolower()), strings only
     *   ucwords             ucwords(strtolower()), strings only
     *   slug                lowercase + replace non-alphanumeric with '-'
     *   date                any strtotime-parseable string → 'Y-m-d'
     *   datetime            any strtotime-parseable string → 'Y-m-d H:i:s'
     *   nullable_string     trim then null if empty string, string otherwise
     *
     * Empty-value rules (checked before EACH step in the chain):
     *   null    → preserved, chain stops
     *   ''      → converted to null for int/float/bool, chain stops
     *   ''      → passed through for string-based casts
     *   0/'0'   → valid, cast proceeds
     *
     * @return array<string, string|array<int, string>>
     */
    public function casts(): array
    {
        return [];
    }

    /**
     * Rename input keys in the validated output.
     * Runs AFTER casts. castFailures keys are automatically synced.
     *
     * ⚠ casts() / defaults() use INPUT names (pre-alias).
     *   computed() uses ALIAS targets (post-alias).
     * ⚠ Self-aliases ($from === $to) are skipped safely.
     * ⚠ If the alias target already exists, it will be silently overwritten.
     *
     * @return array<string, string>
     */
    public function aliases(): array
    {
        return [];
    }

    /**
     * Computed/derived fields — appended AFTER aliases.
     * Each entry: 'output_key' => callable(array $data): mixed
     * $data is the fully cast + aliased validatedData at this point.
     *
     * ⚠ Use alias TARGET names when reading from $data.
     * ⚠ If a callable throws, field = null + logged in computedFailures.
     *
     * @return array<string, callable>
     */
    public function computed(): array
    {
        return [];
    }

    /**
     * Post-validation transformation hook.
     * Called LAST — after casts, defaults, aliases, computed.
     * Mutate $this->validatedData directly here.
     *
     * Example:
     *   protected function afterValidation(): void
     *   {
     *       if (!empty($this->validatedData['password'])) {
     *           $this->validatedData['password'] = password_hash(
     *               $this->validatedData['password'], PASSWORD_BCRYPT
     *           );
     *       }
     *   }
     */
    protected function afterValidation(): void {}

    /**
     * List of field names that contain sensitive data.
     * Used by redacted() to mask values in serialization output.
     *
     * ⚠ Use ALIAS target names (post-alias).
     *
     * Example:
     *   public function sensitiveFields(): array
     *   {
     *       return ['password', 'token', 'card_number', 'secret'];
     *   }
     *
     * @return array<int, string>
     */
    public function sensitiveFields(): array
    {
        return [];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CORE PIPELINE
    // ─────────────────────────────────────────────────────────────────────────

    public function setRequest(Request $request): static
    {
        $this->request              = $request;
        $this->normalizedPrimaryKeys = null; // invalidate cache on new request
        return $this;
    }

    /**
     * Run the full pipeline. Called automatically by the Router.
     *
     * @throws \LogicException     if setRequest() was never called
     * @throws ValidationException on 403 (authorize) or 422 (rules)
     */
    public function validateResolved(): void
    {
        if ($this->request === null) {
            throw new \LogicException(
                static::class . '::setRequest() must be called before validateResolved().'
            );
        }

        // Reset state for re-entrant safety
        $this->validatedData    = [];
        $this->castFailures     = [];
        $this->computedFailures = [];

        // Step 1 — Authorization
        if (!$this->authorize()) {
            throw new ValidationException('This action is unauthorized.', [], 403);
        }

        // Step 2 — Security: truncate oversized string inputs
        $this->applyMaxInputLength();

        // Step 3 — Security: pre-validation sanitizers
        $this->applySanitize();

        // Step 4 — Custom normalization hook
        $this->prepareForValidation();

        $rules = $this->rules();

        // No rules → leave everything empty
        if (empty($rules)) {
            return;
        }

        // PERF: cache request->all() — it would be called twice otherwise
        $allData = $this->request->all();

        // Step 5 — Validate
        $validator = validator($allData, $rules, $this->messages())->validate();

        if (!$validator->passed()) {
            throw new ValidationException(
                $validator->getFirstError() ?: 'Validation failed.',
                $validator->getErrors(),
                422
            );
        }

        // Step 6 — Whitelist: keep only keys defined in rules()
        foreach (array_keys($rules) as $key) {
            if (array_key_exists($key, $allData)) {
                $this->validatedData[$key] = $allData[$key];
            }
        }

        // Step 7 — Defaults
        $this->applyDefaults();

        // Step 8 — Type casting
        $this->applyCasts();

        // Step 9 — Aliasing + castFailures key sync
        $this->applyAliases();

        // Step 10 — Computed fields
        $this->applyComputed();

        // Step 11 — Final hook
        $this->afterValidation();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PIPELINE INTERNALS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Truncate oversized string inputs to maxInputLength() bytes.
     * Guards against memory exhaustion from huge field values.
     * Silently truncates — no error thrown. Run before sanitize and rules.
     */
    protected function applyMaxInputLength(): void
    {
        $max = $this->maxInputLength();
        if ($max <= 0) {
            return;
        }

        $all = $this->request->all();
        $truncated = $this->truncateOversizedStrings($all, $max);

        if ($truncated !== $all) {
            $this->request->merge($truncated);
        }
    }

    private function truncateOversizedStrings(mixed $value, int $max): mixed
    {
        if (is_array($value)) {
            $result = [];

            foreach ($value as $key => $item) {
                $result[$key] = $this->truncateOversizedStrings($item, $max);
            }

            return $result;
        }

        if (!is_string($value) || $this->stringLength($value) <= $max) {
            return $value;
        }

        return $this->stringSubstring($value, 0, $max);
    }

    /**
     * Apply pre-validation sanitizers defined in sanitize().
     * Only processes string values — other types pass through unchanged.
     */
    protected function applySanitize(): void
    {
        $sanitizers = $this->sanitize();
        if (empty($sanitizers)) {
            return;
        }

        $mutations = [];

        foreach ($sanitizers as $field => $types) {
            $value = $this->request->input($field);

            // Only sanitize string values
            if (!is_string($value)) {
                continue;
            }

            $steps = $this->resolveTypeList($types);
            $current = $value;

            foreach ($steps as $sanitizer) {
                $current = $this->performSanitize($current, $sanitizer);
            }

            if ($current !== $value) {
                $mutations[$field] = $current;
            }
        }

        if (!empty($mutations)) {
            $this->request->merge($mutations);
        }
    }

    /**
     * Apply a single sanitizer to a string value.
     *
     * Unknown sanitizer names are a programming error — throwing here prevents
     * typos (e.g. 'striptags' vs 'strip_tags') from silently leaving values
     * un-sanitized.
     */
    private function performSanitize(string $value, string $sanitizer): string
    {
        return match ($sanitizer) {
            'trim'              => trim($value),
            'strip_tags'        => strip_tags($value),
            'html_encode'       => htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'html_decode'       => htmlspecialchars_decode($value, ENT_QUOTES | ENT_HTML5),
            'no_null_bytes'     => str_replace("\0", '', $value),
            'control_chars'     => preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value) ?? $value,
            'normalize_spaces'  => preg_replace('/\s{2,}/', ' ', $value) ?? $value,
            'normalize_newlines'=> preg_replace("/\r\n?|\n/u", "\n", $value) ?? $value,
            'lowercase'         => $this->lowercaseString($value),
            'uppercase'         => $this->uppercaseString($value),
            'ucfirst'           => $this->uppercaseFirstString($value),
            'ucwords'           => $this->titleCaseString($value),
            'digits_only'       => preg_replace('/\D+/', '', $value) ?? $value,
            'alpha_num'         => preg_replace('/[^\pL\pN]+/u', '', $value) ?? $value,
            'filename_safe'     => trim((preg_replace('/[^A-Za-z0-9._-]+/', '-', $value) ?? $value), '.-_'),
            'slug'              => $this->slugifyString($value),
            default             => throw new \RuntimeException("Unknown sanitizer: '{$sanitizer}'"),
        };
    }

    private function stringLength(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($value, 'UTF-8');
        }

        return strlen($value);
    }

    private function stringSubstring(string $value, int $start, ?int $length = null): string
    {
        if (function_exists('mb_substr')) {
            return $length === null
                ? mb_substr($value, $start, null, 'UTF-8')
                : mb_substr($value, $start, $length, 'UTF-8');
        }

        return $length === null
            ? substr($value, $start)
            : substr($value, $start, $length);
    }

    private function lowercaseString(string $value): string
    {
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($value, 'UTF-8');
        }

        return strtolower($value);
    }

    private function uppercaseString(string $value): string
    {
        if (function_exists('mb_strtoupper')) {
            return mb_strtoupper($value, 'UTF-8');
        }

        return strtoupper($value);
    }

    private function uppercaseFirstString(string $value): string
    {
        $value = $this->lowercaseString($value);

        if ($value === '') {
            return $value;
        }

        $first = $this->stringSubstring($value, 0, 1);
        $rest = $this->stringSubstring($value, 1);

        return $this->uppercaseString($first) . $rest;
    }

    private function titleCaseString(string $value): string
    {
        if (function_exists('mb_convert_case')) {
            return mb_convert_case($value, MB_CASE_TITLE, 'UTF-8');
        }

        return ucwords(strtolower($value));
    }

    private function slugifyString(string $value): string
    {
        $normalized = trim($value);

        if ($normalized === '') {
            return '';
        }

        if (function_exists('iconv')) {
            $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized);

            if (is_string($transliterated) && $transliterated !== '') {
                $normalized = $transliterated;
            }
        }

        $normalized = $this->lowercaseString($normalized);
        $slug = preg_replace('/[^\pL\pN]+/u', '-', $normalized) ?? '';

        return trim($slug, '-');
    }

    protected function applyDefaults(): void
    {
        foreach ($this->defaults() as $field => $default) {
            if (
                !array_key_exists($field, $this->validatedData)
                || $this->validatedData[$field] === null
            ) {
                $this->validatedData[$field] = $default;
            }
        }
    }

    /**
     * Apply type casts with chained cast support and safe failure handling.
     *
     * Each field's cast value may be:
     *   - a single type string: 'int'
     *   - a pipe-separated chain: 'trim|ucwords'
     *   - an array of types:    ['trim', 'ucwords']
     *
     * The chain runs left to right. If any step fails or produces null,
     * the chain stops and the failure (if any) is logged.
     *
     * PERF: uses self::EMPTY_TO_NULL_CASTS constant (no array rebuild per call).
     * PERF: performCast() uses if/else instead of IIFEs (no closure allocation per call).
     */
    protected function applyCasts(): void
    {
        $this->castFailures = [];

        foreach ($this->casts() as $field => $type) {
            if (!array_key_exists($field, $this->validatedData)) {
                continue;
            }

            $originalValue = $this->validatedData[$field];
            $currentValue  = $originalValue;

            // null → preserve, skip entire chain
            if ($currentValue === null) {
                continue;
            }

            $steps  = $this->resolveTypeList($type);
            $failed = false;

            foreach ($steps as $castType) {
                // null produced by a previous step → stop chain, preserve null
                if ($currentValue === null) {
                    break;
                }

                // '' + numeric/bool → null, stop chain
                if ($currentValue === '' && isset(self::EMPTY_TO_NULL_CASTS[$castType])) {
                    $currentValue = null;
                    break;
                }

                try {
                    $currentValue = $this->performCast($currentValue, $castType);
                } catch (\RuntimeException $e) {
                    $this->castFailures[$field] = [
                        'type'     => $castType,
                        'original' => $originalValue,
                        'reason'   => $e->getMessage(),
                    ];
                    $currentValue = $originalValue; // revert entire chain on failure
                    $failed = true;
                    break;
                } catch (\Throwable $e) {
                    $this->castFailures[$field] = [
                        'type'     => $castType,
                        'original' => $originalValue,
                        'reason'   => 'Unexpected error during cast: ' . $e->getMessage(),
                    ];
                    $currentValue = $originalValue;
                    $failed = true;
                    break;
                }
            }

            if (!$failed) {
                $this->validatedData[$field] = $currentValue;
            }
            // on failure: $this->validatedData[$field] stays as $originalValue
        }
    }

    /**
     * Perform a single cast step and return the result.
     *
     * PERF: uses plain if/else instead of IIFEs — avoids Closure object
     * allocation on every single cast call (measurable in bulk scenarios).
     *
     * @throws \RuntimeException on any recoverable cast failure
     */
    private function performCast(mixed $value, string $type): mixed
    {
        // ── Numeric ───────────────────────────────────────────────────────────
        if ($type === 'int' || $type === 'integer') {
            if (is_string($value) && !is_numeric(trim($value))) {
                throw new \RuntimeException(
                    "Cannot cast non-numeric string \"{$value}\" to {$type}. Original value preserved."
                );
            }
            return (int) $value;
        }

        if ($type === 'float' || $type === 'double') {
            if (is_string($value) && !is_numeric(trim($value))) {
                throw new \RuntimeException(
                    "Cannot cast non-numeric string \"{$value}\" to {$type}. Original value preserved."
                );
            }
            return (float) $value;
        }

        // ── Boolean ───────────────────────────────────────────────────────────
        // Truthy: true, 1, '1', 'on', 'yes', 'true'
        // Falsy:  false, 0, '0', 'off', 'no', 'false'
        if ($type === 'bool' || $type === 'boolean') {
            $result = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($result === null) {
                throw new \RuntimeException(
                    "Cannot cast value \"{$value}\" to bool. "
                    . 'Accepted: true/false, 1/0, yes/no, on/off. Original value preserved.'
                );
            }
            return $result;
        }

        // ── JSON ──────────────────────────────────────────────────────────────
        if ($type === 'json') {
            if (!is_string($value)) {
                return $value; // already array/object — pass through
            }
            $decoded = json_decode($value, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException(
                    'JSON decode failed: ' . json_last_error_msg() . '. Original string preserved.'
                );
            }
            return $decoded;
        }

        // ── Date / Datetime ───────────────────────────────────────────────────
        if ($type === 'date') {
            if (!is_string($value)) {
                return $value;
            }
            $ts = strtotime($value);
            if ($ts === false) {
                throw new \RuntimeException(
                    "Cannot parse \"{$value}\" as a date. Original value preserved."
                );
            }
            return date('Y-m-d', $ts);
        }

        if ($type === 'datetime') {
            if (!is_string($value)) {
                return $value;
            }
            $ts = strtotime($value);
            if ($ts === false) {
                throw new \RuntimeException(
                    "Cannot parse \"{$value}\" as a datetime. Original value preserved."
                );
            }
            return date('Y-m-d H:i:s', $ts);
        }

        // ── String transforms ─────────────────────────────────────────────────
        if ($type === 'string')          return (string) $value;
        if ($type === 'array')           return is_array($value) ? $value : (array) $value;
        if ($type === 'trim')            return is_string($value) ? trim($value) : $value;
        if ($type === 'uppercase')       return is_string($value) ? $this->uppercaseString($value) : $value;
        if ($type === 'lowercase')       return is_string($value) ? $this->lowercaseString($value) : $value;
        if ($type === 'ucfirst')         return is_string($value) ? $this->uppercaseFirstString($value) : $value;
        if ($type === 'ucwords')         return is_string($value) ? $this->titleCaseString($value) : $value;

        if ($type === 'slug') {
            if (!is_string($value)) {
                return $value;
            }
            return $this->slugifyString($value);
        }

        if ($type === 'nullable_string') {
            if (!is_string($value)) {
                return $value;
            }
            $trimmed = trim($value);
            return $trimmed === '' ? null : $trimmed;
        }

        // ── Unknown ───────────────────────────────────────────────────────────
        throw new \RuntimeException(
            "Unknown cast type \"{$type}\". Original value preserved."
        );
    }

    /**
     * Parse a cast/sanitize definition into a sequential list of type strings.
     *
     * Handles:
     *   'int'              → ['int']
     *   'trim|ucwords'     → ['trim', 'ucwords']
     *   ['trim', 'ucwords']→ ['trim', 'ucwords']
     *   ''                 → []    (empty string safely → empty list)
     *   []                 → []    (empty array safely → empty list)
     *
     * @param string|array<int, string> $definition
     * @return array<int, string>
     */
    private function resolveTypeList(string|array $definition): array
    {
        if (is_array($definition)) {
            $steps = $definition;
        } else {
            $steps = explode('|', $definition);
        }

        return array_values(
            array_filter(
                array_map('trim', $steps),
                static fn(string $s) => $s !== ''
            )
        );
    }

    /**
     * Rename keys per aliases().
     * Syncs castFailures keys to alias targets.
     * Skips self-aliases to prevent accidental key deletion.
     */
    protected function applyAliases(): void
    {
        foreach ($this->aliases() as $from => $to) {
            if ($from === $to) {
                continue;
            }

            if (array_key_exists($from, $this->validatedData)) {
                $this->validatedData[$to] = $this->validatedData[$from];
                unset($this->validatedData[$from]);
            }

            if (array_key_exists($from, $this->castFailures)) {
                $this->castFailures[$to] = $this->castFailures[$from];
                unset($this->castFailures[$from]);
            }
        }
    }

    protected function applyComputed(): void
    {
        $this->computedFailures = [];

        foreach ($this->computed() as $field => $callable) {
            if (!is_callable($callable)) {
                $this->computedFailures[$field] = [
                    'reason' => "Computed field \"{$field}\" is not callable.",
                ];
                continue;
            }

            try {
                $this->validatedData[$field] = $callable($this->validatedData);
            } catch (\Throwable $e) {
                $this->computedFailures[$field] = [
                    'reason' => "Computed field \"{$field}\" threw: " . $e->getMessage(),
                ];
                $this->validatedData[$field] = null;
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIMARY KEY HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Normalize primaryKey() into a clean 0-indexed string array.
     *
     * Cached per request (invalidated by setRequest()).
     * Calling isUpdate(), isCreate(), recordId(), whenCreate(), whenUpdate()
     * multiple times does not rebuild the array.
     *
     *   'id'                    → ['id']
     *   ['tenant_id','user_id'] → ['tenant_id','user_id']
     *   ['pk' => 'id']          → ['id']   (values used, keys discarded)
     *   [] / ''                 → []
     *   [null, 0, '']           → []       (non-string / blank values filtered)
     *
     * @return array<int, string>
     */
    private function normalizePrimaryKeys(): array
    {
        if ($this->normalizedPrimaryKeys !== null) {
            return $this->normalizedPrimaryKeys;
        }

        $raw = (array) $this->primaryKey();

        $this->normalizedPrimaryKeys = array_values(
            array_filter($raw, static fn($v) => is_string($v) && $v !== '')
        );

        return $this->normalizedPrimaryKeys;
    }

    /**
     * A primary key value is "present" when it is non-null and non-empty.
     * Zero is considered a valid identifier value.
     */
    private function isPrimaryKeyValuePresent(mixed $value): bool
    {
        return !($value === null || $value === '');
    }

    /**
     * Returns true when ALL primary key(s) are present and non-empty.
     *
     * @throws \LogicException if setRequest() was never called
     */
    public function isUpdate(): bool
    {
        if ($this->request === null) {
            throw new \LogicException(
                static::class . '::setRequest() must be called before isUpdate().'
            );
        }

        $keys = $this->normalizePrimaryKeys();

        if (empty($keys)) {
            return false;
        }

        foreach ($keys as $key) {
            if (!$this->isPrimaryKeyValuePresent($this->request->input($key))) {
                return false;
            }
        }

        return true;
    }

    /** Returns true when ANY primary key is missing/empty (create flow). */
    public function isCreate(): bool
    {
        return !$this->isUpdate();
    }

    /**
     * Returns the record identifier from the raw request input.
     *
     * Single key → (string) value | null
     * Composite  → array<key, string value> | null (null if any part is missing)
     * Empty keys → null
     *
     * @return string|array<string, string>|null
     *
     * @throws \LogicException if setRequest() was never called
     */
    public function recordId(): string|array|null
    {
        if ($this->request === null) {
            throw new \LogicException(
                static::class . '::setRequest() must be called before recordId().'
            );
        }

        $keys = $this->normalizePrimaryKeys();

        if (empty($keys)) {
            return null;
        }

        if (count($keys) === 1) {
            $value = $this->request->input($keys[0]);
            return $this->isPrimaryKeyValuePresent($value) ? (string) $value : null;
        }

        $result = [];
        foreach ($keys as $key) {
            $value = $this->request->input($key);
            if (!$this->isPrimaryKeyValuePresent($value)) {
                return null;
            }
            $result[$key] = (string) $value;
        }

        return $result;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // FAILURE INSPECTION
    // ─────────────────────────────────────────────────────────────────────────

    public function hasCastFailures(): bool
    {
        return !empty($this->castFailures);
    }

    /** @return array<string, array{type: string, original: mixed, reason: string}> */
    public function getCastFailures(): array
    {
        return $this->castFailures;
    }

    /** @return array{type: string, original: mixed, reason: string}|null */
    public function getCastFailure(string $field): ?array
    {
        return $this->castFailures[$field] ?? null;
    }

    public function hasComputedFailures(): bool
    {
        return !empty($this->computedFailures);
    }

    /** @return array<string, array{reason: string}> */
    public function getComputedFailures(): array
    {
        return $this->computedFailures;
    }

    /** @return array{reason: string}|null */
    public function getComputedFailure(string $field): ?array
    {
        return $this->computedFailures[$field] ?? null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DATA ACCESS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Get the full validated payload, or a single field with optional default.
     */
    public function validated(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->validatedData;
        }
        return $this->validatedData[$key] ?? $default;
    }

    /**
     * True if the key exists in validatedData, even if its value is null.
     * Different from validated('key') !== null.
     */
    public function hasValidated(string $key): bool
    {
        return array_key_exists($key, $this->validatedData);
    }

    /**
     * Clean processed payload after the full FormRequest pipeline.
     * Acts as the DTO output for controller/service/repository layers.
     */
    public function toDTO(): array
    {
        return $this->validatedData;
    }

    /** Validated data as a plain array. Alias of toDTO(). */
    public function toArray(): array
    {
        return $this->toDTO();
    }

    /** Validated data as a JSON string. Returns '{}' if encoding fails. */
    public function toJson(int $flags = 0): string
    {
        return json_encode($this->validatedData, $flags) ?: '{}';
    }

    /**
     * Validated data with sensitive fields replaced by '***'.
     * Safe for logging, audit trails, and API responses.
     * Sensitive field list is defined by sensitiveFields().
     */
    public function redacted(): array
    {
        $sensitive = array_flip($this->sensitiveFields());

        if (empty($sensitive)) {
            return $this->validatedData;
        }

        $result = $this->validatedData;

        foreach ($sensitive as $field => $_) {
            if (array_key_exists($field, $result)) {
                $result[$field] = '***';
            }
        }

        return $result;
    }

    /**
     * Validated data with null values removed.
     * Use for INSERT — let DB column defaults handle absent optional fields.
     */
    public function withoutNull(): array
    {
        return array_filter($this->validatedData, static fn($v) => $v !== null);
    }

    /**
     * Validated data with null, '' and [] removed.
     * Use for PATCH — only update fields that carry real values.
     */
    public function withoutEmpty(): array
    {
        return array_filter(
            $this->validatedData,
            static fn($v) => $v !== null && $v !== '' && $v !== []
        );
    }

    /**
     * Inject server-side values into validated data.
     * Bypasses whitelist and casting — for trusted server values only
     * (tenant IDs, session IDs, IP addresses, server timestamps).
     */
    public function fill(array $data): static
    {
        foreach ($data as $key => $value) {
            $this->validatedData[$key] = $value;
        }
        return $this;
    }

    /**
     * Remove one or more keys from validated data.
     * Complement of fill() — silently skips keys that don't exist.
     */
    public function forgetValidated(string ...$keys): static
    {
        foreach ($keys as $key) {
            unset($this->validatedData[$key]);
        }
        return $this;
    }

    /**
     * Run a callback only on CREATE requests. No-op on update.
     * Callback receives $this and may mutate validatedData.
     */
    public function whenCreate(callable $callback): static
    {
        if ($this->isCreate()) {
            $callback($this);
        }
        return $this;
    }

    /**
     * Run a callback only on UPDATE requests. No-op on create.
     */
    public function whenUpdate(callable $callback): static
    {
        if ($this->isUpdate()) {
            $callback($this);
        }
        return $this;
    }

    /**
     * Pass validated data through a transform callable and replace it.
     * Callback must return an array.
     *
     * @throws \UnexpectedValueException if callback returns non-array
     */
    public function pipe(callable $callback): static
    {
        $result = $callback($this->validatedData);

        if (!is_array($result)) {
            throw new \UnexpectedValueException(
                'pipe() callback must return an array. Got: ' . gettype($result)
            );
        }

        $this->validatedData = $result;
        return $this;
    }

    /**
     * Inspect validated data without modifying it (debug/logging hook).
     * Callback return value is ignored.
     */
    public function tap(callable $callback): static
    {
        $callback($this->validatedData);
        return $this;
    }

    /** Get a subset of validated data by keys. */
    public function only(array $keys): array
    {
        return array_intersect_key($this->validatedData, array_flip($keys));
    }

    /** Get validated data excluding specified keys. */
    public function except(array $keys): array
    {
        return array_diff_key($this->validatedData, array_flip($keys));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TYPED ACCESSORS — reads from validatedData (post-cast)
    // ─────────────────────────────────────────────────────────────────────────

    public function boolean(string $key, bool $default = false): bool
    {
        $value = $this->validated($key);
        if ($value === null) {
            return $default;
        }
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    public function integer(string $key, int $default = 0): int
    {
        $value = $this->validated($key);
        if ($value === null || (is_string($value) && !is_numeric(trim($value)))) {
            return $default;
        }
        return (int) $value;
    }

    public function float(string $key, float $default = 0.0): float
    {
        $value = $this->validated($key);
        if ($value === null || (is_string($value) && !is_numeric(trim($value)))) {
            return $default;
        }
        return (float) $value;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->validated($key);
        return $value !== null ? (string) $value : $default;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // RAW REQUEST HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    /** Raw unprocessed request input. */
    public function all(): array
    {
        return $this->request !== null ? $this->request->all() : [];
    }

    public function input(?string $key = null, mixed $default = null): mixed
    {
        if ($this->request === null) {
            return $default;
        }
        return $this->request->input($key, $default);
    }

    public function route(?string $key = null, mixed $default = null): mixed
    {
        if ($this->request === null) {
            return $default;
        }
        return $this->request->route($key, $default);
    }

    public function has(string $key): bool
    {
        return $this->request !== null && $this->request->has($key);
    }

    /**
     * Merge data into raw request input.
     * Use inside prepareForValidation() to normalize before rules run.
     *
     * @throws \LogicException if setRequest() was never called
     */
    public function merge(array $data): static
    {
        if ($this->request === null) {
            throw new \LogicException(
                static::class . '::setRequest() must be called before merge().'
            );
        }
        $this->request->merge($data);
        return $this;
    }

    public function method(): string
    {
        return $this->request !== null ? $this->request->method() : '';
    }

    /** Underlying Request instance for low-level access. */
    public function request(): ?Request
    {
        return $this->request;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // FILE HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    public function file(string $key): ?array
    {
        return $_FILES[$key] ?? null;
    }

    public function hasFile(string $key): bool
    {
        return isset($_FILES[$key]) && $_FILES[$key]['error'] !== UPLOAD_ERR_NO_FILE;
    }
}
