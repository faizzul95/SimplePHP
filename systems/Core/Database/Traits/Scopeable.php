<?php

namespace Core\Database\Traits;

use InvalidArgumentException;
use BadMethodCallException;
use Closure;
use Throwable;
use Components\Logger;

/**
 * Scopeable trait for adding query scopes
 *
 * @category  Database
 * @package   Core\Database\Traits
 * @license   MIT License
 * @version   1.0.0
 */
trait Scopeable
{
    /**
     * The registered scopes.
     *
     * @var array<string, array<string, callable>>
     */
    protected static array $_scopes = [];

    /**
     * Register a query scope
     *
     * @param string $name The name of the scope
     * @param callable $callback The scope implementation
     * @return void
     * @throws InvalidArgumentException If name is empty or callback is not callable
     */
    public static function scope(string $name, callable $callback): void
    {
        if (empty($name)) {
            throw new InvalidArgumentException('Scope name cannot be empty');
        }

        if (!is_callable($callback)) {
            throw new InvalidArgumentException('Scope callback must be callable');
        }

        // Validate that callback is a closure for proper binding
        if (!($callback instanceof Closure)) {
            throw new InvalidArgumentException('Scope callback must be a Closure for proper binding');
        }

        static::$_scopes[static::class][$name] = $callback;
    }

    /**
     * Register multiple scopes at once
     *
     * @param array<string, callable> $scopes Array of scope name => callback pairs
     * @return void
     * @throws InvalidArgumentException If any scope is invalid
     */
    public static function scopes(array $scopes): void
    {
        foreach ($scopes as $name => $callback) {
            static::scope($name, $callback);
        }
    }

    /**
     * Check if a scope exists
     *
     * @param string $name The scope name
     * @return bool
     */
    public function hasScope(string $name): bool
    {
        return isset(static::$_scopes[static::class][$name]);
    }

    /**
     * Get all registered scopes for this class
     *
     * @return array<string, callable>
     */
    public static function getScopes(): array
    {
        return static::$_scopes[static::class] ?? [];
    }

    /**
     * Get scope names only
     *
     * @return array<string>
     */
    public static function getScopeNames(): array
    {
        return array_keys(static::getScopes());
    }

    /**
     * Remove a registered scope
     *
     * @param string $name The name of the scope to remove
     * @return bool True if scope was removed, false if it didn't exist
     */
    public static function removeScope(string $name): bool
    {
        if (!isset(static::$_scopes[static::class][$name])) {
            return false;
        }

        unset(static::$_scopes[static::class][$name]);
        return true;
    }

    /**
     * Clear all scopes for this class
     *
     * @return void
     */
    public static function clearScopes(): void
    {
        static::$_scopes[static::class] = [];
    }

    /**
     * Call a scope method
     *
     * @param string $name The scope name
     * @param array $parameters The scope parameters
     * @return mixed
     * @throws BadMethodCallException If scope doesn't exist
     */
    protected function callScope(string $name, array $parameters)
    {
        if (!$this->hasScope($name)) {
            throw new BadMethodCallException(
                sprintf('Scope %s::%s does not exist.', static::class, $name)
            );
        }

        try {
            $scope = static::$_scopes[static::class][$name];

            if ($scope instanceof Closure) {
                // Bind scope to current instance
                $scope = $scope->bindTo($this, static::class);
                return $scope(...$parameters);
            }

            return $this;
        } catch (Throwable $e) {
            $this->logErrorScope("Error executing scope '{$name}': " . $e->getMessage(), [
                'scope' => $name,
                'parameters' => $parameters,
                'exception' => $e
            ]);
            throw $e;
        }
    }

    /**
     * Apply multiple scopes
     *
     * @param array $scopes Array of scope names or [name => parameters]
     * @return static
     * @throws InvalidArgumentException If scopes array is malformed
     * @throws BadMethodCallException If any scope doesn't exist
     */
    public function applyScopes(array $scopes): static
    {
        if (empty($scopes)) {
            return $this;
        }

        try {
            foreach ($scopes as $scope => $parameters) {
                if (is_numeric($scope)) {
                    // Simple scope name: ['active', 'published']
                    if (!is_string($parameters)) {
                        throw new InvalidArgumentException('Scope name must be a string');
                    }
                    $this->{$parameters}();
                } else {
                    // Scope with parameters: ['recent' => 30, 'byStatus' => 'active']
                    if (!is_string($scope)) {
                        throw new InvalidArgumentException('Scope name must be a string');
                    }
                    $this->{$scope}(...(array) $parameters);
                }
            }
        } catch (Throwable $e) {
            $this->logErrorScope("Error applying scopes: " . $e->getMessage(), [
                'scopes' => $scopes,
                'exception' => $e
            ]);
            throw $e;
        }

        return $this;
    }

    /**
     * Check if any scopes are registered
     *
     * @return bool
     */
    public static function hasScopes(): bool
    {
        return !empty(static::$_scopes[static::class]);
    }

    /**
     * Get count of registered scopes
     *
     * @return int
     */
    public static function getScopeCount(): int
    {
        return count(static::$_scopes[static::class] ?? []);
    }

    /**
     * Log error message (shared with Macroable trait)
     *
     * @param string $message The error message
     * @param array $context Additional context
     * @return void
     */
    protected function logErrorScope(string $message, array $context = []): void
    {
        try {
            if (class_exists(Logger::class)) {
                $logger = new Logger(__DIR__ . '/../../../../logs/database/error.log');
                $logger->logWithContext($message, $context, Logger::LOG_LEVEL_ERROR);
            }
        } catch (Throwable $e) {
            // Fail silently if logging fails
            error_log("Failed to log error: " . $e->getMessage());
        }
    }
}
