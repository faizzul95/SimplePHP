<?php

namespace Core\Database\Traits;

use Closure;

/**
 * ValidationTrait for input validation
 *
 * @category  Database
 * @package   Core\Database\Traits
 * @license   MIT License
 * @version   1.0.0
 */
trait ValidationTrait
{
    /**
     * Validate macro/scope name
     *
     * @param string $name The name to validate
     * @return bool
     */
    protected static function isValidName(string $name): bool
    {
        // Allow letters, numbers, underscores, no leading numbers
        return preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name) === 1;
    }

    /**
     * Validate callback is properly callable
     *
     * @param mixed $callback The callback to validate
     * @return bool
     */
    protected static function isValidCallback($callback): bool
    {
        return is_callable($callback) && ($callback instanceof Closure || is_array($callback) || is_string($callback));
    }

    /**
     * Sanitize method name for security
     *
     * @param string $name The name to sanitize
     * @return string
     */
    protected static function sanitizeName(string $name): string
    {
        return preg_replace('/[^a-zA-Z0-9_]/', '', $name);
    }
}