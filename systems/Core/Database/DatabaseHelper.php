<?php

namespace Core\Database;

/**
 * Database Helper Class
 *
 * @author    Mohd Fahmy Izwan Zulkhafri <faizzul14@gmail.com>
 * @license   http://opensource.org/licenses/gpl-3.0.html GNU Public License
 * @link      -
 */

use Core\Database\DatabaseCache;

class DatabaseHelper
{
    /**
     * @var string|null The cache key.
     */
    protected $cacheFile = null;

    /**
     * @var string|integer The cache to expire in seconds.
     */
    protected $cacheFileExpired = 3600;

    /**
     * @var array Cache for validated columns to avoid re-validation
     */
    protected $validatedColumns = [];

    /**
     * @var int Maximum recursion depth for sanitize() to prevent stack overflow attacks
     */
    protected static $maxSanitizeDepth = 32;

    /**
     * @var array Cache for validated table names to avoid re-validation
     */
    protected $validatedTableNames = [];

    /**
     * @var array<string, bool> Cache for string inputs that already passed raw-query validation.
     */
    protected $validatedRawQueryInputs = [];

    # GENERAL SECTION

    /**
     * Sanitize input data to prevent XSS and SQL injection attacks based on the secure flag.
     * Protected against deep recursion attacks with a configurable depth limit.
     *
     * @param array $ignoreList List of keys/columns to ignore during sanitization.
     * @param int $depth Current recursion depth (internal use).
     * @return mixed|null The sanitized input data or null if $value is null or empty.
     * @throws \RuntimeException If the maximum recursion depth is exceeded.
     */
    protected function sanitize($value = null, $ignoreList = [], $depth = 0)
    {
        if (!isset($value) || is_null($value)) {
            return $value;
        }

        // Guard against excessively nested input (stack overflow / DoS vector)
        if ($depth > self::$maxSanitizeDepth) {
            throw new \RuntimeException('Maximum sanitization depth exceeded. Input is too deeply nested.');
        }

        // Sanitize input based on data type
        switch (gettype($value)) {
            case 'string':
                // Remove null bytes (common injection payload)
                $value = str_replace("\0", '', $value);
                return htmlspecialchars(trim($value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            case 'integer':
            case 'double':
                return $value;
            case 'boolean':
                return (bool) $value;
            case 'array':
                $result = [];
                foreach ($value as $key => $val) {
                    // Sanitize the key itself if it's a string
                    $safeKey = is_string($key) ? htmlspecialchars(trim(str_replace("\0", '', $key)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : $key;
                    // If the key is in ignoreList, skip sanitization for this key
                    if (in_array($key, $ignoreList, true)) {
                        $result[$safeKey] = $val;
                    } else {
                        $result[$safeKey] = $this->sanitize($val, $ignoreList, $depth + 1);
                    }
                }
                return $result;
            default:
                return $value;
        }
    }

    /**
     * Normalize database values for storage or internal processing without HTML escaping.
     * Protected against deep recursion attacks with a configurable depth limit.
     *
     * @param array $ignoreList List of keys/columns to ignore during normalization.
     * @param int $depth Current recursion depth (internal use).
     * @return mixed|null The normalized input data or null if $value is null.
     * @throws \RuntimeException If the maximum recursion depth is exceeded.
     */
    protected function normalizeDatabaseValue($value = null, $ignoreList = [], $depth = 0)
    {
        if (!isset($value) || is_null($value)) {
            return $value;
        }

        if ($depth > self::$maxSanitizeDepth) {
            throw new \RuntimeException('Maximum sanitization depth exceeded. Input is too deeply nested.');
        }

        switch (gettype($value)) {
            case 'string':
                return trim(str_replace("\0", '', $value));
            case 'integer':
            case 'double':
                return $value;
            case 'boolean':
                return (bool) $value;
            case 'array':
                $result = [];
                foreach ($value as $key => $val) {
                    $safeKey = is_string($key) ? trim(str_replace("\0", '', $key)) : $key;
                    if (in_array($key, $ignoreList, true)) {
                        $result[$safeKey] = $val;
                    } else {
                        $result[$safeKey] = $this->normalizeDatabaseValue($val, $ignoreList, $depth + 1);
                    }
                }
                return $result;
            default:
                return $value;
        }
    }

    /**
     * Validates a raw query string to prevent full SQL statement execution and SQL injection.
     * Blocks full statements, comment injections, hex/char obfuscation, stacked queries,
     * and common SQL injection payloads.
     *
     * @throws \InvalidArgumentException If the string contains forbidden keywords or patterns.
     */
    protected function _forbidRawQuery($string, $message = 'Not supported to run full query')
    {
        // Skip validation for non-string/non-array values (e.g., integers, floats, null, booleans)
        if (!is_string($string) && !is_array($string)) {
            return;
        }

        $stringArr = is_string($string) ? [$string] : $string;

        foreach ($stringArr as $str) {
            if (!is_string($str) || strlen($str) < 4) {
                continue;
            }

            if (isset($this->validatedRawQueryInputs[$str])) {
                continue;
            }

            // Block null bytes (common injection payload)
            if (strpos($str, "\0") !== false) {
                throw new \InvalidArgumentException('Null bytes are not allowed in query parameters');
            }

            // Fast-path common identifiers and scalar tokens while still rejecting bare SQL keywords.
            if (preg_match('/^[a-zA-Z0-9_`.]+$/', $str) === 1) {
                if (preg_match('/^(SELECT|INSERT|UPDATE|DELETE|DROP|CREATE|ALTER|TRUNCATE|REPLACE|GRANT|REVOKE|SHOW|UNION|EXEC|EXECUTE)$/i', $str) === 1) {
                    throw new \InvalidArgumentException($message);
                }

                $this->validatedRawQueryInputs[$str] = true;
                continue;
            }

            // Block stacked queries (semicolons followed by statements)
            if (preg_match('/;\s*(SELECT|INSERT|UPDATE|DELETE|DROP|CREATE|ALTER|TRUNCATE|EXEC|EXECUTE|UNION|GRANT|REVOKE)/i', $str)) {
                throw new \InvalidArgumentException('Stacked queries are not allowed');
            }

            // Block SQL comment injection (used to bypass WHERE clauses)
            if (preg_match('/\/\*[\s\S]*?\*\/|--\s|#\s|#$/', $str)) {
                throw new \InvalidArgumentException('SQL comments are not allowed in query parameters');
            }

            // Block hex/char obfuscation attacks
            if (preg_match('/0x[0-9a-fA-F]+|CHAR\s*\(/i', $str)) {
                throw new \InvalidArgumentException('Hex or CHAR() encoding is not allowed in query parameters');
            }

            // Block SLEEP/BENCHMARK (time-based blind SQL injection)
            if (preg_match('/\b(SLEEP|BENCHMARK|WAITFOR|DELAY|LOAD_FILE|INTO\s+(OUT|DUMP)FILE)\b/i', $str)) {
                throw new \InvalidArgumentException('Potentially dangerous SQL function detected');
            }

            // Fast pre-check: look for uppercase first letters of forbidden keywords
            $upper = strtoupper($str);
            $hasPotentialKeyword = false;
            foreach (['SEL', 'INS', 'UPD', 'DEL', 'DRO', 'CRE', 'ALT', 'TRU', 'REP', 'GRA', 'REV', 'SHO', 'UNI', 'EXE'] as $prefix) {
                if (strpos($upper, $prefix) !== false) {
                    $hasPotentialKeyword = true;
                    break;
                }
            }

            if ($hasPotentialKeyword && preg_match('/\b(SELECT|INSERT|UPDATE|DELETE|DROP|CREATE|ALTER|TRUNCATE|REPLACE|GRANT|REVOKE|SHOW|UNION|EXEC|EXECUTE)\b/i', $str)) {
                throw new \InvalidArgumentException($message);
            }

            $this->validatedRawQueryInputs[$str] = true;
        }
    }

    /**
     * Validates a table or column name against SQL injection.
     * Only allows alphanumeric, underscores, dots (for schema.table), and backticks.
     *
     * @param string $label Human-readable label for error messages.
     * @throws \InvalidArgumentException If the name contains invalid characters.
     * @return string The validated name.
     */
    protected function validateTableName($name, $label = 'Table name')
    {
        if (empty($name) || !is_string($name)) {
            throw new \InvalidArgumentException("$label cannot be empty and must be a string.");
        }

        // Check cache first
        $cacheKey = $label . ':' . $name;
        if (isset($this->validatedTableNames[$cacheKey])) {
            return $name;
        }

        // Strip backticks for validation (they're safe SQL identifiers)
        $stripped = str_replace('`', '', $name);

        // Allow: letters, numbers, underscores, dots (for schema.table), hyphens
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_.\-]*$/', $stripped)) {
            throw new \InvalidArgumentException("$label contains invalid characters: $name");
        }

        // Block reserved words used as bare identifiers (common attack vector)
        if (preg_match('/^(SELECT|INSERT|UPDATE|DELETE|DROP|CREATE|ALTER|TRUNCATE|UNION|EXEC)$/i', $stripped)) {
            throw new \InvalidArgumentException("$label cannot be a reserved SQL keyword: $name");
        }

        // Max length guard (MySQL limit is 64 chars)
        if (strlen($stripped) > 128) {
            throw new \InvalidArgumentException("$label exceeds maximum length: $name");
        }

        $this->validatedTableNames[$cacheKey] = true;
        return $name;
    }

    /**
     * Formats a number of bytes into a human-readable string with units.
     *
     * This function takes a number of bytes and converts it to a human-readable
     * format with appropriate units (B, KB, MB, GB, TB). It uses a specified
     * precision for rounding the value.
     *
     * @param int $bytes The number of bytes to format.
     * @param int $precision (optional) The number of decimal places to round to. Defaults to 2.
     * @return string The formatted string with units (e.g., 1023.45 KB).
     */
    protected function _formatBytes($bytes, $precision = 2)
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $bytes = max($bytes, 0); // Ensure non-negative bytes
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024)); // Calculate power of 1024
        $pow = min($pow, count($units) - 1); // Limit to valid unit index

        $bytes /= (1 << (10 * $pow)); // Divide by appropriate factor

        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    # CACHING SECTION

    /**
     * Caches data with an expiration time.
     *
     * This method sets a cache entry identified by the provided key with optional expiration time.
     *
     * @param string $key The unique key identifying the cache entry.
     * @param int $expire The expiration time of the cache entry in seconds (default: 1800 seconds).
     * @return $this Returns the current object instance for method chaining.
     */
    public function cache($key, $expire = 1800)
    {
        $this->cacheFile = $key;
        $this->cacheFileExpired = $expire;

        return $this;
    }

    /**
     * Retrieves cached data for the given key.
     *
     * This method retrieves cached data identified by the provided key using the Cache class.
     *
     * @param string $key The unique key identifying the cached data.
     * @return mixed|null Returns the cached data if found; otherwise, returns null.
     */
    protected function _getCacheData($key)
    {
        $cache = new DatabaseCache('cache/query');
        return $cache->get($key);
    }

    /**
     * Stores data in the cache with an optional expiration time.
     *
     * This method stores data identified by the provided key in the cache using the Cache class.
     *
     * @param string $key The unique key identifying the cache entry.
     * @param mixed $data The data to be stored in the cache.
     * @param int $expire The expiration time of the cache entry in seconds (default: 1800 seconds).
     * @return bool Returns true on success, false on failure.
     */
    protected function _setCacheData($key, $data, $expire = 1800)
    {
        $cache = new DatabaseCache('cache/query');
        return $cache->set($key, $data, $expire);
    }

    # VALIDATION SECTION

    /**
     * Assert that a value is a bare SQL identifier, optionally qualified.
     *
     * Accepts `column` or `table.column`, with or without backtick quoting.
     * Rejects everything else — expressions, function calls, whitespace, and
     * any embedded backtick that would close the identifier quoting early.
     *
     * This is the strict counterpart to validateColumn(), which only checks
     * that a value is a non-empty string because several of its 38 call sites
     * legitimately pass expressions. Use this one wherever the value is
     * concatenated into SQL as an identifier rather than bound as a parameter.
     *
     * @return array{0:string,1:string|null} [column, qualifier|null]
     * @throws \InvalidArgumentException
     */
    protected function parseIdentifier($name, string $label = 'Identifier'): array
    {
        if (!is_string($name)) {
            throw new \InvalidArgumentException("$label must be a string.");
        }

        $name = trim($name);

        if ($name === '') {
            throw new \InvalidArgumentException("$label cannot be empty.");
        }

        // `qualifier`.`column`, qualifier.column, `column`, column.
        // The backtick is optional but must be balanced, so a stray one is rejected.
        $pattern = '/^`?([A-Za-z_][A-Za-z0-9_$]*)`?(?:\.`?([A-Za-z_][A-Za-z0-9_$]*)`?)?$/';

        if (preg_match($pattern, $name, $parts) !== 1) {
            throw new \InvalidArgumentException(
                "$label is not a valid identifier: {$name}. "
                . 'Expected `column` or `table.column`; use a raw method for expressions.'
            );
        }

        return isset($parts[2]) && $parts[2] !== ''
            ? [$parts[2], $parts[1]]
            : [$parts[1], null];
    }

    /**
     * Validate an identifier and return it backtick-quoted.
     *
     * @throws \InvalidArgumentException
     */
    protected function quoteIdentifier($name, string $label = 'Identifier', ?string $defaultQualifier = null): string
    {
        [$column, $qualifier] = $this->parseIdentifier($name, $label);

        if ($qualifier === null && $defaultQualifier !== null && trim($defaultQualifier) !== '') {
            [$qualifierColumn] = $this->parseIdentifier($defaultQualifier, 'Table name');
            $qualifier = $qualifierColumn;
        }

        return $qualifier === null
            ? '`' . $column . '`'
            : '`' . $qualifier . '`.`' . $column . '`';
    }

    /**
     * Assert that a value is a non-empty string.
     *
     * NOTE: this is deliberately permissive and is NOT an injection guard.
     * Several call sites pass SQL expressions (aggregates, raw fragments), so
     * it cannot enforce an identifier grammar without breaking them. When a
     * value is concatenated into SQL as an identifier, use quoteIdentifier()
     * or parseIdentifier() instead.
     *
     * @throws \InvalidArgumentException If the column name is not a string or has no value.
     */
    protected function validateColumn($column, $default = 'Column')
    {
        // Check cache first (optimization)
        $cacheKey = md5($column . $default);
        if (isset($this->validatedColumns[$cacheKey])) {
            return;
        }

        if (!is_string($column)) {
            throw new \InvalidArgumentException("Invalid $default value. Must be a string.");
        }

        if (empty($column)) {
            throw new \InvalidArgumentException("$default cannot be empty or null.");
        }

        $this->validatedColumns[$cacheKey] = true;
    }

    /**
     * Validates if the given date string is in a recognizable format (Y-m-d or d-m-Y)
     * and converts it to the Y-m-d format.
     *
     * @return string The validated date in Y-m-d format.
     * @throws \InvalidArgumentException If the date format is not recognized.
     */
    protected function validateDate($date)
    {
        $timestamp = strtotime($date);
        if ($timestamp === false) {
            throw new \InvalidArgumentException('Invalid date format. Date must be in a recognizable format. Suggested format : Y-m-d OR d-m-Y');
        }
        return date('Y-m-d', $timestamp);
    }

    /**
     * Validates if the given operator is supported.
     *
     * @throws \InvalidArgumentException If the operator is not supported.
     */
    protected function validateOperator($operator, $extra = [])
    {
        $supportedOperators = array_merge(['=', '<', '>', '<=', '>=', '<>', '!='], $extra);
        if (!in_array($operator, $supportedOperators)) {
            throw new \InvalidArgumentException('Invalid operator. Supported operators are: ' . implode(', ', $supportedOperators));
        }
    }

    /**
     * Validates if the given day is a valid number between 1 and 31.
     *
     * @throws \InvalidArgumentException If the day is not a valid number between 1 and 31.
     */
    protected function validateDay($day)
    {
        if (!is_numeric($day) || $day < 1 || $day > 31) {
            throw new \InvalidArgumentException('Invalid day. Must be a number between 1 and 31.');
        }
    }

    /**
     * Validates if the given month is a valid number between 1 and 12.
     *
     * @throws \InvalidArgumentException If the month is not a valid number between 1 and 12.
     */
    protected function validateMonth($month)
    {
        if (!is_numeric($month) || $month < 1 || $month > 12) {
            throw new \InvalidArgumentException('Invalid month. Must be a number between 1 and 12.');
        }
    }

    /**
     * Validates if the given year is a valid four-digit number.
     *
     * @throws \InvalidArgumentException If the year is not a valid four-digit number.
     */
    protected function validateYear($year)
    {
        if (!is_numeric($year) || strlen((string)$year) !== 4) {
            throw new \InvalidArgumentException('Invalid year. Must be a four-digit number.');
        }
    }

    /**
     * Validates a time string (HH:MM or HH:MM:SS).
     *
     * @return string
     * @throws \InvalidArgumentException
     */
    protected function validateTime($time)
    {
        if (preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $time)) {
            // Normalize to HH:MM:SS
            if (strlen($time) === 5) {
                $time .= ':00';
            }
            return $time;
        }

        throw new \InvalidArgumentException("Invalid time format: $time. Expected HH:MM or HH:MM:SS.");
    }
}
