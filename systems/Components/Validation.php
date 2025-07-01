<?php

namespace Components;

use Exception;

class Validation
{
    /**
     * Data to validate
     * 
     * @var array
     */
    private $data = [];

    /**
     * Validation rules
     * 
     * @var array
     */
    private $rules = [];

    /**
     * Custom error messages
     * 
     * @var array
     */
    private $messages = [];

    /**
     * Custom validation rules
     * 
     * @var array
     */
    private $customRules = [];

    /**
     * Before validation hooks
     * 
     * @var array
     */
    private $beforeHooks = [];

    /**
     * After validation hooks
     * 
     * @var array
     */
    private $afterHooks = [];

    /**
     * Validation errors
     * 
     * @var array
     */
    private $errors = [];

    /**
     * Failed fields
     * 
     * @var array
     */
    private $failedFields = [];

    /**
     * Maximum file size in KB
     * 
     * @var int
     */
    private $maxFileSize = 10240; // 10MB

    /**
     * Allowed file extensions
     * 
     * @var array
     */
    private $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'txt', 'xls', 'xlsx'];

    /**
     * Dangerous file extensions
     * 
     * @var array
     */
    private $dangerousExtensions = [
        'php', 'phtml', 'php3', 'php4', 'php5', 'pht', 'phar', 'js', 'html', 'htm', 
        'exe', 'bat', 'cmd', 'com', 'scr', 'vbs', 'jar', 'sh', 'asp', 'aspx', 'jsp',
        'pl', 'py', 'rb', 'cgi', 'htaccess', 'htpasswd', 'ini', 'conf', 'sql'
    ];

    /**
     * XSS patterns with comprehensive coverage
     * 
     * @var array
     */
    private $xssPatterns = [
        // Script tags
        '/<script[^>]*>.*?<\/script>/is',
        '/<script[^>]*>/is',
        
        // Iframe and embed tags
        '/<iframe[^>]*>.*?<\/iframe>/is',
        '/<iframe[^>]*>/is',
        '/<object[^>]*>.*?<\/object>/is',
        '/<embed[^>]*>.*?<\/embed>/is',
        '/<applet[^>]*>.*?<\/applet>/is',
        
        // Meta and link tags
        '/<meta[^>]*>/is',
        '/<link[^>]*>/is',
        
        // JavaScript protocols
        '/javascript:/is',
        '/vbscript:/is',
        '/data:/is',
        
        // Event handlers
        '/on\w+\s*=/is',
        '/onload\s*=/is',
        '/onerror\s*=/is',
        '/onclick\s*=/is',
        '/onmouseover\s*=/is',
        '/onmouseout\s*=/is',
        '/onfocus\s*=/is',
        '/onblur\s*=/is',
        '/onchange\s*=/is',
        '/onsubmit\s*=/is',
        '/onkeydown\s*=/is',
        '/onkeyup\s*=/is',
        '/onkeypress\s*=/is',
        '/onmousedown\s*=/is',
        '/onmouseup\s*=/is',
        '/onmousemove\s*=/is',
        '/onresize\s*=/is',
        '/onscroll\s*=/is',
        '/onunload\s*=/is',
        
        // Style with javascript
        '/style\s*=.*javascript/is',
        '/style\s*=.*expression/is',
        
        // Form action
        '/<form[^>]*action\s*=\s*["\']?javascript/is',
        
        // Import statements
        '/@import/is',
        
        // Generic HTML with potential XSS
        '/<\s*\w.*?>/is',
        
        // Unicode and encoding bypasses
        '/&#/is',
        '/%3C/is',
        '/%3E/is',
        '/&lt;/is',
        '/&gt;/is',
        
        // SVG XSS
        '/<svg[^>]*>/is',
        '/<use[^>]*>/is',
        '/<image[^>]*>/is',
        
        // Base64 encoded scripts
        '/data:text\/html;base64/is',
        '/data:application\/javascript;base64/is'
    ];

    /**
     * Default error messages
     * 
     * @var array
     */
    private $defaultMessages = [
        'required' => 'The :field field is required.',
        'string' => 'The :field must be a string.',
        'numeric' => 'The :field must be a number.',
        'integer' => 'The :field must be an integer.',
        'boolean' => 'The :field field must be true or false.',
        'email' => 'The :field must be a valid email address.',
        'url' => 'The :field format is invalid.',
        'ip' => 'The :field must be a valid IP address.',
        'min' => 'The :field must be at least :min characters.',
        'max' => 'The :field may not be greater than :max characters.',
        'min_length' => 'The :field must be at least :min_length characters long.',
        'max_length' => 'The :field may not be more than :max_length characters long.',
        'between' => 'The :field must be between :min and :max characters.',
        'size' => 'The :field must be exactly :size characters.',
        'same' => 'The :field and :other must match.',
        'different' => 'The :field and :other must be different.',
        'confirmed' => 'The :field confirmation does not match.',
        'in' => 'The selected :field is invalid.',
        'not_in' => 'The selected :field is invalid.',
        'alpha' => 'The :field may only contain letters.',
        'alpha_num' => 'The :field may only contain letters and numbers.',
        'alpha_dash' => 'The :field may only contain letters, numbers, dashes and underscores.',
        'regex' => 'The :field format is invalid.',
        'date' => 'The :field is not a valid date.',
        'date_format' => 'The :field does not match the format :format.',
        'before' => 'The :field must be a date before :date.',
        'after' => 'The :field must be a date after :date.',
        'date_equals' => 'The :field must be a date equal to :date.',
        'accepted' => 'The :field must be accepted.',
        'array' => 'The :field must be an array.',
        'file' => 'The :field must be a file.',
        'image' => 'The :field must be an image.',
        'mimes' => 'The :field must be a file of type: :values.',
        'gt' => 'The :field must be greater than :value.',
        'gte' => 'The :field must be greater than or equal :value.',
        'lt' => 'The :field must be less than :value.',
        'lte' => 'The :field must be less than or equal :value.',
        'starts_with' => 'The :field must start with one of the following: :values.',
        'ends_with' => 'The :field must end with one of the following: :values.',
        'nullable' => 'The :field field may be null.',
        'sometimes' => 'The :field field is sometimes required.',
        'password' => 'The :field does not meet the password requirements.',
        'xss' => 'The :field contains potentially dangerous content.',
        'safe_html' => 'The :field contains unsafe HTML content.',
        'no_sql_injection' => 'The :field contains potentially dangerous SQL patterns.',
        'secure_value' => 'The :field contains potentially dangerous content.',
        'secure_filename' => 'The :field contains an unsafe filename.',
        'file_extension' => 'The :field has an invalid file extension.',
        'max_file_size' => 'The :field exceeds the maximum file size of :max KB.',
        'min_image_width' => 'The :field must be at least :min pixels wide.',
        'max_image_width' => 'The :field may not be more than :max pixels wide.',
        'min_image_height' => 'The :field must be at least :min pixels high.',
        'max_image_height' => 'The :field may not be more than :max pixels high.',
        'deep_array' => 'The :field array structure is invalid.',
        'array_keys' => 'The :field array contains invalid keys.',
        'array_values' => 'The :field array contains invalid values.'
    ];

    /**
     * Constructor
     * 
     * @param array $data
     * @param array $rules
     * @param array $messages
     */
    public function __construct(array $data = [], array $rules = [], array $messages = [])
    {
        try {
            $this->data = $this->sanitizeInput($data);
            $this->rules = $rules;
            $this->messages = $messages;
        } catch (Exception $e) {
            throw new Exception("Validation initialization failed: " . $e->getMessage());
        }
    }

    /**
     * Static factory method
     * 
     * @param array $data
     * @param array $rules
     * @param array $messages
     * @return self
     */
    public static function make(array $data, array $rules, array $messages = []): self
    {
        try {
            return new self($data, $rules, $messages);
        } catch (Exception $e) {
            throw new Exception("Validation creation failed: " . $e->getMessage());
        }
    }

    /**
     * Sanitize input data
     * 
     * @param array $data
     * @return array
     */
    private function sanitizeInput(array $data): array
    {
        try {
            $sanitized = [];

            foreach ($data as $key => $value) {
                if (is_array($value)) {
                    $sanitized[$key] = $this->sanitizeInput($value);
                } elseif (is_string($value)) {
                    $sanitized[$key] = trim($value);
                } else {
                    $sanitized[$key] = $value;
                }
            }

            return $sanitized;
        } catch (Exception $e) {
            throw new Exception("Input sanitization failed: " . $e->getMessage());
        }
    }

    /**
     * Set validation data
     * 
     * @param array $data
     * @return self
     */
    public function setData(array $data): self
    {
        try {
            $this->data = $this->sanitizeInput($data);
            return $this;
        } catch (Exception $e) {
            throw new Exception("Setting validation data failed: " . $e->getMessage());
        }
    }

    /**
     * Set validation rules
     * 
     * @param array $rules
     * @return self
     */
    public function setRules(array $rules): self
    {
        $this->rules = $rules;
        return $this;
    }

    /**
     * Set custom messages
     * 
     * @param array $messages
     * @return self
     */
    public function setMessages(array $messages): self
    {
        $this->messages = $messages;
        return $this;
    }

    /**
     * Add a custom validation rule
     * 
     * @param string $name
     * @param callable $callback
     * @param string $message
     * @return self
     */
    public function addRule(string $name, callable $callback, string $message = ''): self
    {
        try {
            $this->customRules[$name] = [
                'callback' => $callback,
                'message' => $message ?: "The :field field is invalid."
            ];
            return $this;
        } catch (Exception $e) {
            throw new Exception("Adding custom rule failed: " . $e->getMessage());
        }
    }

    /**
     * Add a before validation hook
     * 
     * @param callable $callback
     * @return self
     */
    public function beforeValidation(callable $callback): self
    {
        $this->beforeHooks[] = $callback;
        return $this;
    }

    /**
     * Add an after validation hook
     * 
     * @param callable $callback
     * @return self
     */
    public function afterValidation(callable $callback): self
    {
        $this->afterHooks[] = $callback;
        return $this;
    }

    /**
     * Set maximum file size
     * 
     * @param int $size Size in KB
     * @return self
     */
    public function setMaxFileSize(int $size): self
    {
        $this->maxFileSize = $size;
        return $this;
    }

    /**
     * Set allowed file extensions
     * 
     * @param array $extensions
     * @return self
     */
    public function setAllowedExtensions(array $extensions): self
    {
        $this->allowedExtensions = $extensions;
        return $this;
    }

    /**
     * Get validation status
     * 
     * @return array
     */
    public function status(): array
    {
        return [
            'valid' => empty($this->errors),
            'errors' => $this->errors,
            'failed_fields' => $this->failedFields,
            'count' => count($this->errors)
        ];
    }

    /**
     * Get validation passed status
     * 
     * @return bool
     */
    public function passed(): bool
    {
        return empty($this->errors);
    }

    /**
     * Get validation errors
     * 
     * @return array
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Get the very first validation error message
     * 
     * @return string
     */
    public function getFirstError(): string
    {
        if (empty($this->errors)) {
            return '';
        }

        // Get the first field that had errors
        $firstField = array_key_first($this->errors);
        $messages = $this->errors[$firstField];

        return reset($messages);
    }

    /**
     * Get the very last validation error message
     * 
     * @return string
     */
    public function getLastError(): string
    {
        if (empty($this->errors)) {
            return '';
        }

        // Get the last field that had errors
        $lastField = array_key_last($this->errors);
        $messages = $this->errors[$lastField];

        return end($messages);
    }

    /**
     * Validate the data
     * 
     * @return self
     */
    public function validate(): self
    {
        try {
            // Reset errors
            $this->errors = [];
            $this->failedFields = [];

            // Execute before hooks
            $this->executeBeforeHooks();

            // Validate each field
            foreach ($this->rules as $field => $rules) {
                try {
                    $this->validateField($field, $rules);
                } catch (Exception $e) {
                    $this->addError($field, 'validation_error', [], $e->getMessage());
                }
            }

            // Execute after hooks
            $this->executeAfterHooks();

            return $this;

        } catch (Exception $e) {
            throw new Exception("Validation failed: " . $e->getMessage());
        }
    }

    /**
     * Validate batch of datasets
     * 
     * @param array $datasets
     * @return array
     */
    public function validateBatch(array $datasets): array
    {
        $results = [];

        try {
            $originalData = $this->data;
            foreach ($datasets as $index => $data) {
                $this->setData($data);

                $result = $this->validate();
                $results[$index] = $result;

                $this->setData($originalData);
            }
        } catch (Exception $e) {
            throw new Exception("Batch validation failed: " . $e->getMessage());
        }

        return $results;
    }

    /**
     * Execute before validation hooks
     * 
     * @return void
     */
    private function executeBeforeHooks(): void
    {
        foreach ($this->beforeHooks as $hook) {
            try {
                $hook($this);
            } catch (Exception $e) {
                error_log("Before validation hook error: " . $e->getMessage());
            }
        }
    }

    /**
     * Execute after validation hooks
     * 
     * @return void
     */
    private function executeAfterHooks(): void
    {
        foreach ($this->afterHooks as $hook) {
            try {
                $hook($this);
            } catch (Exception $e) {
                error_log("After validation hook error: " . $e->getMessage());
            }
        }
    }

    /**
     * Validate a single field
     * 
     * @param string $field
     * @param string $rules
     * @return void
     * @throws Exception
     */
    private function validateField(string $field, string $rules): void
    {
        try {
            $rulesArray = explode('|', $rules);
            $value = $this->getFieldValue($field);
            $shouldBail = false;

            // Quick exit for nullable
            if (in_array('nullable', $rulesArray) && is_null($value)) {
                return; // Skip validation if value is null and nullable is present
            }

            // Quick exit for sometimes
            if (in_array('sometimes', $rulesArray) && !$this->fieldExists($field)) {
                return; // Skip validation if sometimes is present and field is missing
            }

            foreach ($rulesArray as $rule) {
                $rule = trim($rule);

                if ($rule === 'bail') {
                    $shouldBail = true;
                    continue;
                }

                if (!$this->applyRule($field, $value, $rule)) {
                    if ($shouldBail) {
                        break;
                    }
                }
            }
        } catch (Exception $e) {
            throw new Exception("Field validation failed for '$field': " . $e->getMessage());
        }
    }

    /**
     * Get field value with support for nested arrays and deep checking
     * 
     * @param string $field
     * @return mixed
     */
    private function getFieldValue(string $field)
    {
        try {
            if (strpos($field, '*') !== false) {
                return $this->getWildcardValues($field);
            }

            if (strpos($field, '.') === false) {
                return $this->data[$field] ?? null;
            }

            return $this->getNestedValue($this->data, explode('.', $field));
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Get nested value from array using dot notation
     * 
     * @param array $array
     * @param array $keys
     * @return mixed
     */
    private function getNestedValue(array $array, array $keys)
    {
        try {
            $value = $array;

            foreach ($keys as $key) {
                if (!is_array($value) || !array_key_exists($key, $value)) {
                    return null;
                }
                $value = $value[$key];
            }

            return $value;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Get values for wildcard fields with deep array support
     * 
     * @param string $field
     * @return array
     */
    private function getWildcardValues(string $field): array
    {
        try {
            $parts = explode('.', $field);
            $results = [];

            $this->extractWildcardValues($this->data, $parts, 0, [], $results);

            return $results;
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Extract values for wildcard fields recursively with enhanced deep checking
     * 
     * @param array $data
     * @param array $parts
     * @param int $index
     * @param array $path
     * @param array &$results
     * @return void
     */
    private function extractWildcardValues(array $data, array $parts, int $index, array $path, array &$results): void
    {
        try {
            if ($index >= count($parts)) {
                return;
            }

            $part = $parts[$index];

            if ($part === '*') {
                foreach ($data as $key => $value) {
                    $newPath = array_merge($path, [$key]);
                    if ($index === count($parts) - 1) {
                        $results[implode('.', $newPath)] = $value;
                    } elseif (is_array($value)) {
                        $this->extractWildcardValues($value, $parts, $index + 1, $newPath, $results);
                    }
                }
            } elseif ($part === '**') {
                // Deep wildcard - matches any depth
                $this->extractDeepWildcardValues($data, $parts, $index + 1, $path, $results);
            } else {
                if (isset($data[$part])) {
                    $newPath = array_merge($path, [$part]);
                    if ($index === count($parts) - 1) {
                        $results[implode('.', $newPath)] = $data[$part];
                    } elseif (is_array($data[$part])) {
                        $this->extractWildcardValues($data[$part], $parts, $index + 1, $newPath, $results);
                    }
                }
            }
        } catch (Exception $e) {
            // Log error but continue processing
            error_log("Wildcard extraction error: " . $e->getMessage());
        }
    }

    /**
     * Extract values for deep wildcard fields
     * 
     * @param array $data
     * @param array $parts
     * @param int $startIndex
     * @param array $path
     * @param array &$results
     * @return void
     */
    private function extractDeepWildcardValues(array $data, array $parts, int $startIndex, array $path, array &$results): void
    {
        try {
            if ($startIndex >= count($parts)) {
                return;
            }

            $targetPart = $parts[$startIndex];

            // Recursively search through all levels
            foreach ($data as $key => $value) {
                $newPath = array_merge($path, [$key]);

                if ($key === $targetPart) {
                    if ($startIndex === count($parts) - 1) {
                        $results[implode('.', $newPath)] = $value;
                    } elseif (is_array($value)) {
                        $this->extractWildcardValues($value, $parts, $startIndex + 1, $newPath, $results);
                    }
                }

                if (is_array($value)) {
                    $this->extractDeepWildcardValues($value, $parts, $startIndex, $newPath, $results);
                }
            }
        } catch (Exception $e) {
            error_log("Deep wildcard extraction error: " . $e->getMessage());
        }
    }

    /**
     * Check if field exists in data with deep array support
     * 
     * @param string $field
     * @return bool
     */
    private function fieldExists(string $field): bool
    {
        try {
            if (strpos($field, '.') === false) {
                return array_key_exists($field, $this->data);
            }

            $keys = explode('.', $field);
            $value = $this->data;

            foreach ($keys as $key) {
                if (!is_array($value) || !array_key_exists($key, $value)) {
                    return false;
                }
                $value = $value[$key];
            }

            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Apply a validation rule with enhanced security
     * 
     * @param string $field
     * @param mixed $value
     * @param string $rule
     * @return bool
     */
    private function applyRule(string $field, $value, string $rule): bool
    {
        try {
            // Handle wildcard fields
            if (strpos($field, '*') !== false && is_array($value)) {
                $allValid = true;
                foreach ($value as $subField => $subValue) {
                    if (!$this->applyRule($subField, $subValue, $rule)) {
                        $allValid = false;
                    }
                }
                return $allValid;
            }

            $ruleParts = explode(':', $rule, 2);
            $ruleName = $ruleParts[0];
            $ruleParams = isset($ruleParts[1]) ? explode(',', $ruleParts[1]) : [];

            // Check custom rules first
            if (isset($this->customRules[$ruleName])) {
                $result = $this->customRules[$ruleName]['callback']($field, $value);
                if ($result !== true) {
                    $this->addError($field, $ruleName, $ruleParams, $result);
                    return false;
                }
                return true;
            }

            // Apply built-in rules
            $method = 'validate' . ucfirst(str_replace('_', '', $ruleName));

            if (method_exists($this, $method)) {
                if (!$this->$method($field, $value, $ruleParams)) {
                    $this->addError($field, $ruleName, $ruleParams);
                    return false;
                }
                return true;
            }

            // If rule doesn't exist, add error
            $this->addError($field, 'unknown_rule', [$ruleName], "Unknown validation rule: $ruleName");
            return false;
        } catch (Exception $e) {
            $this->addError($field, 'rule_error', [$rule], "Rule application failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Add validation error
     * 
     * @param string $field
     * @param string $rule
     * @param array $params
     * @param string $customMessage
     * @return void
     */
    private function addError(string $field, string $rule, array $params = [], string $customMessage = ''): void
    {
        try {
            if (!in_array($field, $this->failedFields)) {
                $this->failedFields[] = $field;
            }

            $message = $customMessage ?: $this->getErrorMessage($field, $rule, $params);

            if (!isset($this->errors[$field])) {
                $this->errors[$field] = [];
            }

            $this->errors[$field][] = $message;
        } catch (Exception $e) {
            error_log("Error adding validation error: " . $e->getMessage());
        }
    }

    /**
     * Get error message for a rule
     * 
     * @param string $field
     * @param string $rule
     * @param array $params
     * @return string
     */
    private function getErrorMessage(string $field, string $rule, array $params = []): string
    {
        try {
            $key = $field . '.' . $rule;

            if (isset($this->messages[$key])) {
                $message = $this->messages[$key];
            } elseif (isset($this->customRules[$rule])) {
                $message = $this->customRules[$rule]['message'];
            } elseif (isset($this->defaultMessages[$rule])) {
                $message = $this->defaultMessages[$rule];
            } else {
                $message = "The $field field is invalid.";
            }

            // Remove underscores from field name for display
            $displayField = str_replace('_', ' ', $field);

            // Replace placeholders
            $message = str_replace(':field', $displayField, $message);

            if (!empty($params)) {
                switch ($rule) {
                    case 'min':
                    case 'max':
                    case 'min_length':
                    case 'max_length':
                    case 'size':
                    case 'max_file_size':
                        $message = str_replace(':' . $rule, $params[0], $message);
                        break;
                    case 'between':
                        $message = str_replace([':min', ':max'], $params, $message);
                        break;
                    case 'same':
                    case 'different':
                        $message = str_replace(':other', $params[0], $message);
                        break;
                    case 'in':
                    case 'not_in':
                    case 'mimes':
                    case 'starts_with':
                    case 'ends_with':
                        $message = str_replace(':values', implode(', ', $params), $message);
                        break;
                    case 'date_format':
                        $message = str_replace(':format', $params[0], $message);
                        break;
                    case 'before':
                    case 'after':
                    case 'date_equals':
                        $message = str_replace(':date', $params[0], $message);
                        break;
                    case 'gt':
                    case 'gte':
                    case 'lt':
                    case 'lte':
                        $message = str_replace(':value', $params[0], $message);
                        break;
                }
            }

            return $message;
        } catch (Exception $e) {
            return "The $field field is invalid.";
        }
    }

    /**
     * Validate XSS protection
     */
   private function validateXss(string $field, $value, array $params = []): bool
    {
        try {
            if (!is_string($value)) {
                return true; // Only validate strings
            }

            $original = $value;

            // Prepare decoding levels
            $decodedLevels = [
                $original,
                html_entity_decode($original, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                urldecode($original),
                rawurldecode($original)
            ];

            // Also recursively decode to handle double encoding
            foreach ([$original, html_entity_decode($original), urldecode($original), rawurldecode($original)] as $input) {
                $doubleDecoded = html_entity_decode(urldecode($input), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $decodedLevels[] = $doubleDecoded;
            }

            foreach ($decodedLevels as $decoded) {
                $sanitized = strtolower($decoded);
                $sanitized = preg_replace('/\s+/', '', $sanitized); // Remove all whitespace
                $sanitized = str_replace(['%00', "\0"], '', $sanitized); // Remove null bytes

                foreach ($this->xssPatterns as $pattern) {
                    if (preg_match($pattern, $decoded) || preg_match($pattern, $sanitized)) {
                        return false;
                    }
                }
            }

            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Validate safe HTML content
     */
    private function validateSafehtml(string $field, $value, array $params = []): bool
    {
        try {
            if (!is_string($value)) {
                return true;
            }

            $cleanValue = null;

            // Tags that should be removed
            $safeTags = [
                'p', 'br', 'strong', 'em', 'u', 'i', 'b', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
                'ul', 'ol', 'li', 'blockquote', 'pre', 'code', 'span', 'div', 'hr',
                'a', 'img', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td',
                'sup', 'sub', 'small', 'mark', 'del', 'ins', 'abbr', 'cite'
            ];

            // Remove all tags in the $safeTags list
            $cleanValue = preg_replace_callback('#<(/?)([a-zA-Z0-9]+)([^>]*)>#', function ($matches) use ($safeTags) {
                if (in_array(strtolower($matches[2]), $safeTags)) {
                    return ''; // Remove the tag
                }
                return $matches[0]; // Keep the tag (not in safeTags)
            }, $value);

            // Optionally validate against XSS patterns
            return $this->validateXss($field, $cleanValue, $params);
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Validate against SQL injection
     */
    private function validateNosqlinjection(string $field, $value, array $params = []): bool
    {
        try {
            if (!is_string($value)) {
                return true;
            }

            $sqlPatterns = [
                // Basic SQL keywords
                '/(\s*(union|select|insert|update|delete|drop|create|alter|exec|execute)\s+)/i',

                // Basic conditional tautologies
                '/(\s*(or|and)\s+\d+\s*=\s*\d+)/i',
                '/(\s*(\'|\"|`)\s*(or|and)\s*\d+\s*=\s*\d+\s*(\'|\"|`))/i',

                // SQL comments
                '/(\s*--\s*)/i',
                '/(\s*\/\*.*?\*\/\s*)/i',
                '/--/', // Single line comment
                '/\/\*.*\*\//s', // Multi-line comment

                // Common tautology patterns
                '/(\'|\")\s*or\s*(\'|\")?\d+(\'|\")?\s*=\s*(\'|\")?\d+(\'|\")?/i',
                '/(\'|\")\s*or\s*(\'|\")?\w+(\'|\")?\s*=\s*(\'|\")?\w+(\'|\")?/i',
                '/\bor\b\s+\d+\s*=\s*\d+/i',
                '/\bor\b\s+\w+\s*=\s*\w+/i',

                // Chained queries
                '/;\s*(drop|delete|insert|update|select|create|alter|exec|execute)\b/i',

                // Union injection
                '/\bunion\b.*\bselect\b/i',

                // DDL/DML attacks
                '/\bdrop\b\s+\btable\b/i',
                '/\bdelete\b\s+\bfrom\b/i',
                '/\binsert\b\s+\binto\b/i',
                '/\bupdate\b\s+\w+\s+\bset\b/i',

                // Sub-select attacks
                '/\bselect\b\s+.*\bfrom\b\s+\(/i',

                // Information schema targeting
                '/\bfrom\b\s+information_schema\./i',
                '/\bselect\b\s+.*\bfrom\b\s+mysql\./i',

                // Hexadecimal injections
                '/0x[0-9a-fA-F]+/i',

                // LIKE, NOT LIKE, BETWEEN, IS NULL conditions
                '/\blike\b\s+[\'"].*[\'"]/i',
                '/\bnot\s+like\b\s+[\'"].*[\'"]/i',
                '/\bbetween\b\s+.*\s+and\s+.*/i',
                '/\bis\s+null\b/i',

                // Concatenation operator
                '/\bconcat\b\s*\(/i',

                // Time-based attacks
                '/\bsleep\s*\(\s*\d+\s*\)/i',
                '/\bbenchmark\s*\(\s*\d+\s*,/i',

                // Blind SQL injection pattern
                '/\bif\s*\(.*\)/i',

                // Comment at end of input
                '/(--|#)\s*$/i'
            ];

            foreach ($sqlPatterns as $pattern) {
                if (preg_match($pattern, $value)) {
                    return false;
                }
            }

            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Validate secure filename
     */
    private function validateSecurefilename(string $field, $value, array $params = []): bool
    {
        try {
            if (!is_string($value)) {
                return false;
            }

            $filename = trim($value);

            // Reject empty filenames after trimming
            if (strlen($filename) === 0) {
                return false;
            }

            // Prevent path traversal
            if (preg_match('/(\.\.\/|\.\/|\\\\|\/)/', $filename)) {
                return false;
            }

            // Block null byte injections
            if (strpos($filename, "\0") !== false) {
                return false;
            }

            // Block Windows reserved device names (case-insensitive)
            $windowsReserved = [
                'CON', 'PRN', 'AUX', 'NUL',
                'COM1', 'COM2', 'COM3', 'COM4', 'COM5', 'COM6', 'COM7', 'COM8', 'COM9',
                'LPT1', 'LPT2', 'LPT3', 'LPT4', 'LPT5', 'LPT6', 'LPT7', 'LPT8', 'LPT9'
            ];
            $namePart = strtoupper(pathinfo($filename, PATHINFO_FILENAME));
            if (in_array($namePart, $windowsReserved)) {
                return false;
            }

            // Block dangerous characters
            if (preg_match('/[<>:"|?*\\\]/', $filename)) {
                return false;
            }

            // Prevent embedded scripts in filename
            if (preg_match('/<script.*?>|<\/script>/i', $filename)) {
                return false;
            }

            $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if ($extension && in_array($extension, $this->dangerousExtensions, true)) {
                return false;
            }

            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Validate secure value
     */
    private function validateSecurevalue(string $field, $value, array $params = []): bool
    {
        try {
            if (!is_string($value)) {
                return false;
            }

            $sanitizeValue = trim($value);

            $this->validateXss($field, $sanitizeValue, $params);
            $this->validateNosqlinjection($field, $sanitizeValue, $params);

            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * File validation with security checks
     */
    private function validateFile(string $field, $value, array $params = []): bool
    {
        try {
            if (!is_array($value)) {
                return false;
            }

            // Required fields check with early return
            if (!isset($value['tmp_name'], $value['error']) || $value['error'] !== UPLOAD_ERR_OK) {
                return false;
            }

            // Verify uploaded file integrity
            // if (!is_uploaded_file($value['tmp_name']) || !file_exists($value['tmp_name'])) {
            if (!file_exists($value['tmp_name'])) {
                return false;
            }

            // Extract filename once if present
            $filename = $value['name'] ?? null;

            if ($filename !== null) {
                // Validate filename security
                if (!$this->validateSecurefilename($field, $filename, [])) {
                    return false;
                }

                // Security: Check for dangerous extensions
                $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                if ($extension && in_array($extension, $this->dangerousExtensions, true)) {
                    return false;
                }
            }

            // File size validation with null coalescing
            $fileSize = $value['size'] ?? 0;
            if ($fileSize > ($this->maxFileSize * 1024) || $fileSize <= 0) {
                return false;
            }

            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Validate file extension
     */
    private function validateFileextension(string $field, $value, array $params = []): bool
    {
        try {
            if (!is_array($value) || !isset($value['name'])) {
                return false;
            }

            $extension = strtolower(pathinfo($value['name'], PATHINFO_EXTENSION));
            $allowedExtensions = !empty($params) ? $params : $this->allowedExtensions;

            return in_array($extension, $allowedExtensions);
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Validate maximum file size
     */
    private function validateMaxfilesize(string $field, $value, array $params = []): bool
    {
        try {
            if (!is_array($value) || !isset($value['size'])) {
                return false;
            }

            $maxSize = !empty($params) ? (int) $params[0] : $this->maxFileSize;
            return $value['size'] <= ($maxSize * 1024);
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Enhanced deep array validation
     */
    private function validateDeeparray(string $field, $value, array $params = []): bool
    {
        try {
            if (!is_array($value)) {
                return false;
            }

            // Validate array structure depth
            $maxDepth = !empty($params) ? (int) $params[0] : 10;
            if ($this->getArrayDepth($value) > $maxDepth) {
                return false;
            }

            // Validate array size
            if (count($value, COUNT_RECURSIVE) > 1000) { // Prevent memory exhaustion
                return false;
            }

            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Get array depth
     */
    private function getArrayDepth(array $array): int
    {
        try {
            $maxDepth = 1;

            foreach ($array as $value) {
                if (is_array($value)) {
                    $depth = $this->getArrayDepth($value) + 1;
                    if ($depth > $maxDepth) {
                        $maxDepth = $depth;
                    }
                }
            }

            return $maxDepth;
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Validate array keys
     */
    private function validateArraykeys(string $field, $value, array $params = []): bool
    {
        try {
            if (!is_array($value)) {
                return false;
            }

            $allowedKeys = $params;
            if (empty($allowedKeys)) {
                return true;
            }

            foreach (array_keys($value) as $key) {
                if (!in_array($key, $allowedKeys)) {
                    return false;
                }
            }

            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Validate required rule
     */
    private function validateRequired(string $field, $value, array $params = []): bool
    {
        try {
            if (is_null($value)) {
                return false;
            }

            if (is_string($value) && trim($value) === '') {
                return false;
            }

            if (is_array($value) && empty($value)) {
                return false;
            }

            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Validate string rule
     */
    private function validateString(string $field, $value, array $params = []): bool
    {
        try {
            return is_string($value);
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Validate numeric rule
     */
    private function validateNumeric(string $field, $value, array $params = []): bool
    {
        try {
            return is_numeric($value);
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Validate integer rule
     */
    private function validateInteger(string $field, $value, array $params = []): bool
    {
        try {
            return filter_var($value, FILTER_VALIDATE_INT) !== false;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Validate boolean rule
     */
    private function validateBoolean(string $field, $value, array $params = []): bool
    {
        try {
            $acceptable = [true, false, 0, 1, '0', '1', 'true', 'false'];
            return in_array($value, $acceptable, true);
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Validate email rule
     */
    private function validateEmail(string $field, $value, array $params = []): bool
    {
        try {
            return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Validate url rule
     */
    private function validateUrl(string $field, $value, array $params = []): bool
    {
        try {
            return filter_var($value, FILTER_VALIDATE_URL) !== false;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Validate max rule
     */
    private function validateMax(string $field, $value, array $params = []): bool
    {
        try {
            if (empty($params)) {
                return false;
            }

            $max = $params[0];

            if (is_numeric($value)) {
                return $value <= $max;
            }

            if (is_string($value)) {
                return strlen($value) <= $max;
            }

            if (is_array($value)) {
                return count($value) <= $max;
            }

            return false;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Validate min rule
     */
    private function validateMin(string $field, $value, array $params = []): bool
    {
        try {
            if (empty($params)) {
                return false;
            }

            $min = $params[0];

            if (is_numeric($value)) {
                return $value >= $min;
            }

            if (is_string($value)) {
                return strlen($value) >= $min;
            }

            if (is_array($value)) {
                return count($value) >= $min;
            }

            return false;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Validate max length rule (for strings and numbers)
     */
    private function validateMaxLength(string $field, $value, array $params = []): bool
    {
        try {
            if (empty($params)) {
                return false;
            }

            $maxLength = $params[0];

            if (is_string($value)) {
                return strlen($value) <= $maxLength;
            }

            if (is_numeric($value)) {
                return strlen((string)$value) <= $maxLength;
            }

            return false;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Validate min length rule (for strings and numbers)
     */
    private function validateMinLength(string $field, $value, array $params = []): bool
    {
        try {
            if (empty($params)) {
                return false;
            }

            $minLength = $params[0];

            if (is_string($value)) {
                return strlen($value) >= $minLength;
            }

            if (is_numeric($value)) {
                return strlen((string)$value) >= $minLength;
            }

            return false;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Validate between rule
     */
    private function validateBetween(string $field, $value, array $params = []): bool
    {
        try {
            if (count($params) < 2) {
                return false;
            }

            $min = $params[0];
            $max = $params[1];

            if (is_numeric($value)) {
                return $value >= $min && $value <= $max;
            }

            if (is_string($value)) {
                $length = strlen($value);
                return $length >= $min && $length <= $max;
            }

            if (is_array($value)) {
                $count = count($value);
                return $count >= $min && $count <= $max;
            }

            return false;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Validate size rule
     */
    private function validateSize(string $field, $value, array $params = []): bool
    {
        try {
            if (empty($params)) {
                return false;
            }

            $size = $params[0] ?? 0;

            if (is_string($value)) {
                return strlen($value) == $size;
            }

            if (is_numeric($value)) {
                return $value === $size;
            }

            if (is_array($value)) {
                return count($value) == $size;
            }

            return false;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Validate same rule
     */
    private function validateSame(string $field, $value, array $params = []): bool
    {
        try {
            if (empty($params)) {
                return false;
            }

            $otherField = $params[0];
            $otherValue = $this->getFieldValue($otherField);

            return $value === $otherValue;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Validate different rule
     */
    private function validateDifferent(string $field, $value, array $params = []): bool
    {
        try {
            if (empty($params)) {
                return false;
            }

            $otherField = $params[0];
            $otherValue = $this->getFieldValue($otherField);

            return $value !== $otherValue;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Validate confirmed rule
     */
    private function validateConfirmed(string $field, $value, array $params = []): bool
    {
        try {
            $confirmationField = $field . '_confirmation';
            $confirmationValue = $this->getFieldValue($confirmationField);

            return $value === $confirmationValue;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Validate in rule
     */
    private function validateIn(string $field, $value, array $params = []): bool
    {
        try {
            return in_array($value, $params);
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Validate not_in rule
     */
    private function validateNotin(string $field, $value, array $params = []): bool
    {
        try {
            return !in_array($value, $params);
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Validate alpha rule
     */
    private function validateAlpha(string $field, $value, array $params = []): bool
    {
        try {
            return ctype_alpha($value);
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Validate alpha_num rule
     */
    private function validateAlphanum(string $field, $value, array $params = []): bool
    {
        try {
            return ctype_alnum($value);
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Validate alpha_dash rule
     */
    private function validateAlphadash(string $field, $value, array $params = []): bool
    {
        try {
            return preg_match('/^[a-zA-Z0-9_-]+$/', $value);
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Validate regex rule
     */
    private function validateRegex(string $field, $value, array $params = []): bool
    {
        try {
            if (empty($params)) {
                return false;
            }

            $pattern = $params[0];
            return preg_match($pattern, $value);
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Validate date rule
     */
    private function validateDate(string $field, $value, array $params = []): bool
    {
        try {
            if (!is_string($value)) {
                return false;
            }

            $date = date_create($value);
            return $date !== false;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Validate date_format rule
     */
    private function validateDateformat(string $field, $value, array $params = []): bool
    {
        try {
            if (empty($params)) {
                return false;
            }

            $format = $params[0];
            $date = date_create_from_format($format, $value);

            return $date !== false && $date->format($format) === $value;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Validate before rule
     */
    private function validateBefore(string $field, $value, array $params = []): bool
    {
        try {
            if (empty($params)) {
                return false;
            }

            $beforeDate = $params[0];
            $valueDate = date_create($value);
            $beforeDateTime = date_create($beforeDate);

            return $valueDate !== false && $beforeDateTime !== false && $valueDate < $beforeDateTime;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Validate after rule
     */
    private function validateAfter(string $field, $value, array $params = []): bool
    {
        try {
            if (empty($params)) {
                return false;
            }

            $afterDate = $params[0];
            $valueDate = date_create($value);
            $afterDateTime = date_create($afterDate);

            return $valueDate !== false && $afterDateTime !== false && $valueDate > $afterDateTime;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Validate date_equals rule
     */
    private function validateDateequals(string $field, $value, array $params = []): bool
    {
        try {
            if (empty($params)) {
                return false;
            }

            $equalDate = $params[0];
            $valueDate = date_create($value);
            $equalDateTime = date_create($equalDate);

            return $valueDate !== false && $equalDateTime !== false && $valueDate == $equalDateTime;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Validate accepted rule
     */
    private function validateAccepted(string $field, $value, array $params = []): bool
    {
        try {
            $acceptable = ['yes', 'on', '1', 1, true, 'true'];
            return in_array($value, $acceptable, true);
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Validate array rule
     */
    private function validateArray(string $field, $value, array $params = []): bool
    {
        try {
            return is_array($value);
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Validate image rule
     */
    private function validateImage(string $field, $value, array $params = []): bool
    {
        try {
            if (!$this->validateFile($field, $value, $params)) {
                return false;
            }

            $imageTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/bmp', 'image/svg+xml'];
            return in_array($value['type'], $imageTypes);
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Validate mimes rule
     */
    private function validateMimes(string $field, $value, array $params = []): bool
    {
        try {
            if (!$this->validateFile($field, $value, $params)) {
                return false;
            }

            $mimeTypes = [
                // PDF
                'pdf' => 'application/pdf',
                
                // Microsoft Word
                'doc' => 'application/msword',
                'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                
                // Microsoft Excel
                'xls' => 'application/vnd.ms-excel',
                'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'xlsm' => 'application/vnd.ms-excel.sheet.macroEnabled.12',
                'xlsb' => 'application/vnd.ms-excel.sheet.binary.macroEnabled.12',
                'xltx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.template',
                'xltm' => 'application/vnd.ms-excel.template.macroEnabled.12',
                'csv' => 'text/csv',
                
                // Microsoft PowerPoint
                'ppt' => 'application/vnd.ms-powerpoint',
                'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'pptm' => 'application/vnd.ms-powerpoint.presentation.macroEnabled.12',
                'potx' => 'application/vnd.openxmlformats-officedocument.presentationml.template',
                'potm' => 'application/vnd.ms-powerpoint.template.macroEnabled.12',
                'ppsx' => 'application/vnd.openxmlformats-officedocument.presentationml.slideshow',
                'ppsm' => 'application/vnd.ms-powerpoint.slideshow.macroEnabled.12',
                
                // Images - Common formats
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'gif' => 'image/gif',
                'webp' => 'image/webp',
                'bmp' => 'image/bmp',
                'tiff' => 'image/tiff',
                'tif' => 'image/tiff',
                'svg' => 'image/svg+xml',
                'ico' => 'image/x-icon',
                'heic' => 'image/heic',
                'heif' => 'image/heif',
                'avif' => 'image/avif',
                
                // Text formats
                'txt' => 'text/plain',
                'rtf' => 'application/rtf',
                
                // Archive formats
                'zip' => 'application/zip',
                'rar' => 'application/vnd.rar',
                '7z' => 'application/x-7z-compressed',
                'tar' => 'application/x-tar',
                'gz' => 'application/gzip',
                
                // Audio formats
                'mp3' => 'audio/mpeg',
                'wav' => 'audio/wav',
                'flac' => 'audio/flac',
                'aac' => 'audio/aac',
                'ogg' => 'audio/ogg',
                'm4a' => 'audio/mp4',
                
                // Video formats
                'mp4' => 'video/mp4',
                'avi' => 'video/x-msvideo',
                'mov' => 'video/quicktime',
                'wmv' => 'video/x-ms-wmv',
                'flv' => 'video/x-flv',
                'webm' => 'video/webm',
                'mkv' => 'video/x-matroska',
                '3gp' => 'video/3gpp',
                
                // JSON and XML
                'json' => 'application/json',
                'xml' => 'application/xml',
                
                // OpenDocument formats
                'odt' => 'application/vnd.oasis.opendocument.text',
                'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
                'odp' => 'application/vnd.oasis.opendocument.presentation'
            ];

            foreach ($params as $extension) {
                $extension = strtolower(trim($extension));
                if (isset($mimeTypes[$extension]) && $value['type'] === $mimeTypes[$extension]) {
                    return true;
                }
            }

            // Basic MIME type validation
            if (isset($value['type'])) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $actualMimeType = finfo_file($finfo, $value['tmp_name']);
                finfo_close($finfo);

                // Check if actual MIME type matches reported type
                if ($actualMimeType !== $value['type']) {
                    return false;
                }
            }

            return false;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Validate gt (greater than) rule
     */
    private function validateGt(string $field, $value, array $params = []): bool
    {
        try {
            if (empty($params)) {
                return false;
            }

            $otherField = $params[0];
            $otherValue = $this->getFieldValue($otherField);

            if (is_numeric($value) && is_numeric($otherValue)) {
                return $value > $otherValue;
            }

            return false;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Validate gte (greater than or equal) rule
     */
    private function validateGte(string $field, $value, array $params = []): bool
    {
        try {
            if (empty($params)) {
                return false;
            }

            $otherField = $params[0];
            $otherValue = $this->getFieldValue($otherField);

            if (is_numeric($value) && is_numeric($otherValue)) {
                return $value >= $otherValue;
            }

            return false;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Validate lt (less than) rule
     */
    private function validateLt(string $field, $value, array $params = []): bool
    {
        try {
            if (empty($params)) {
                return false;
            }

            $otherField = $params[0];
            $otherValue = $this->getFieldValue($otherField);

            if (is_numeric($value) && is_numeric($otherValue)) {
                return $value < $otherValue;
            }

            return false;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Validate lte (less than or equal) rule
     */
    private function validateLte(string $field, $value, array $params = []): bool
    {
        try {
            if (empty($params)) {
                return false;
            }

            $otherField = $params[0];
            $otherValue = $this->getFieldValue($otherField);

            if (is_numeric($value) && is_numeric($otherValue)) {
                return $value <= $otherValue;
            }

            return false;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Validate starts_with rule
     */
    private function validateStartswith(string $field, $value, array $params = []): bool
    {
        try {
            if (empty($params)) {
                return false;
            }

            foreach ($params as $start) {
                if (strpos($value, $start) === 0) {
                    return true;
                }
            }

            return false;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Validate ends_with rule
     */
    private function validateEndswith(string $field, $value, array $params = []): bool
    {
        try {
            if (empty($params)) {
                return false;
            }

            foreach ($params as $end) {
                if (substr($value, -strlen($end)) === $end) {
                    return true;
                }
            }

            return false;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Validate required_if rule
     */
    private function validateRequiredif(string $field, $value, array $params = []): bool
    {
        try {
            if (count($params) < 2) {
                return true;
            }

            $otherField = $params[0];
            $otherValue = $params[1];
            $otherFieldValue = $this->getFieldValue($otherField);

            if ($otherFieldValue == $otherValue) {
                return $this->validateRequired($field, $value);
            }

            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Validate password rule
     */
    private function validatePassword(string $field, $value, array $params = []): bool
    {
        try {
            if (empty($params) || $params[0] === 'default') {
                // Default password validation: minimum 8 characters
                return strlen($value) >= 8;
            }

            // Parse password requirements
            $requirements = [];
            foreach ($params as $param) {
                if (strpos($param, ':') !== false) {
                    list($key, $val) = explode(':', $param);
                    $requirements[$key] = (int) $val;
                } else {
                    $requirements[$param] = true;
                }
            }

            // Check minimum length
            if (isset($requirements['min']) && strlen($value) < $requirements['min']) {
                return false;
            }

            // Check uppercase letters
            if (isset($requirements['uppercase']) && preg_match_all('/[A-Z]/', $value) < $requirements['uppercase']) {
                return false;
            }

            // Check lowercase letters
            if (isset($requirements['lowercase']) && preg_match_all('/[a-z]/', $value) < $requirements['lowercase']) {
                return false;
            }

            // Check numbers
            if (isset($requirements['numbers']) && preg_match_all('/[0-9]/', $value) < $requirements['numbers']) {
                return false;
            }

            // Check symbols
            if (isset($requirements['symbols']) && preg_match_all('/[^A-Za-z0-9]/', $value) < $requirements['symbols']) {
                return false;
            }

            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Validate IP address
     */
    private function validateIp(string $field, $value, array $params = []): bool
    {
        try {
            // Allow valid IPs or 'localhost'
            if (strtolower($value) === 'localhost') {
                return true;
            }

            return filter_var($value, FILTER_VALIDATE_IP) !== false;
        } catch (Exception $e) {
            return false;
        }
    }
}