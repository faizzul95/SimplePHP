<?php

namespace Core\Database;

/**
 * Database Helper Class
 *
 *
 * @category  Helper
 * @package   Core\Database
 * @author    Mohd Fahmy Izwan Zulkhafri <faizzul14@gmail.com>
 * @license   http://opensource.org/licenses/gpl-3.0.html GNU Public License
 * @link      -
 * @version   0.0.1
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


    # GENERAL SECTION

    /**
     * Sanitize input data to prevent XSS and SQL injection attacks based on the secure flag.
     *
     * @param mixed $value The input data to sanitize.
     * @return mixed|null The sanitized input data or null if $value is null or empty.
     */
    protected function sanitize($value = null)
    {
        // Check if $value is not null or empty
        if (!isset($value) || is_null($value)) {
            return $value;
        }

        // Sanitize input based on data type
        switch (gettype($value)) {
            case 'string':
                return htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8');  // Apply XSS protection and trim
            case 'integer':
            case 'double':
                return $value;
            case 'boolean':
                return (bool) $value;
            case 'array':
                return array_map([$this, 'sanitize'], $value);
            default:
                return $value;
        }
    }

    /**
     * Validates a raw query string to prevent full SQL statement execution.
     *
     * This function ensures the provided string only contains allowed expressions. 
     * It throws an exception if the string contains keywords associated with full SQL statements like `SELECT`, `INSERT`,
     * etc.
     *
     * @param string|array $string The raw query string to validate.
     * @param string $message (Optional) The exception message to throw (defaults to "Not supported to run full query").
     * @throws \InvalidArgumentException If the string contains forbidden keywords.
     */
    protected function _forbidRawQuery($string, $message = 'Not supported to run full query')
    {
        $stringArr = is_string($string) ? [$string] : $string;

        $forbiddenKeywords = '/\b(SELECT|INSERT|UPDATE|DELETE|DROP|CREATE|ALTER|TRUNCATE|REPLACE|GRANT|REVOKE|SHOW)\b/i';

        foreach ($stringArr as $str) {
            if (preg_match($forbiddenKeywords, $str)) {
                throw new \InvalidArgumentException($message);
            }
        }
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
        $cache = new DatabaseCache();
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
        $cache = new DatabaseCache();
        return $cache->set($key, $data, $expire);
    }

    # VALIDATION SECTION

    /**
     * Validates if the given value is a string and throws an exception if not.
     *
     * @param string $column The column name to validate.
     * @throws InvalidArgumentException If the column name is not a string or no value.
     */
    protected function validateColumn($column, $default = 'Column')
    {
        if (!is_string($column)) {
            throw new \InvalidArgumentException("Invalid $default value. Must be a string.");
        }

        if (empty($column)) {
            throw new \InvalidArgumentException("$default cannot be empty or null.");
        }
    }

    /**
     * Validates if the given date string is in a recognizable format (Y-m-d or d-m-Y)
     * and converts it to the Y-m-d format.
     *
     * @param string $date The date string to validate and convert.
     * @return string The validated date in Y-m-d format.
     * @throws InvalidArgumentException If the date format is not recognized.
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
     * @param string $operator The operator to validate.
     * @param array $extra The extra operator to validate.
     * @throws InvalidArgumentException If the operator is not supported.
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
     * @param int $day The day to validate.
     * @throws InvalidArgumentException If the day is not a valid number between 1 and 31.
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
     * @param int $month The month to validate.
     * @throws InvalidArgumentException If the month is not a valid number between 1 and 12.
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
     * @param int $year The year to validate.
     * @throws InvalidArgumentException If the year is not a valid four-digit number.
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
     * @param string $time
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
