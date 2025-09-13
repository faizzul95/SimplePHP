<?php

namespace Core\Database\Traits;

use InvalidArgumentException;
use BadMethodCallException;
use Closure;
use Throwable;
use Components\Logger;

/**
 * Macroable trait for adding custom methods dynamically
 *
 * @category  Database
 * @package   Core\Database\Traits
 * @author    Your Name <your.email@example.com>
 * @license   MIT License
 * @version   1.0.0
 */
trait Macroable
{
    /**
     * The registered macros.
     *
     * @var array<string, array<string, callable>>
     */
    protected static array $_macros = [];

    /**
     * Register a custom macro function
     *
     * @param string $name The name of the macro
     * @param callable $callback The macro implementation
     * @return void
     * @throws InvalidArgumentException If name is empty or callback is not callable
     */
    public static function macro(string $name, callable $callback): void
    {
        if (empty($name)) {
            throw new InvalidArgumentException('Macro name cannot be empty');
        }

        if (!is_callable($callback)) {
            throw new InvalidArgumentException('Macro callback must be callable');
        }

        static::$_macros[static::class][$name] = $callback;
    }

    /**
     * Register multiple macros at once
     *
     * @param array<string, callable> $macros Array of macro name => callback pairs
     * @return void
     * @throws InvalidArgumentException If any macro is invalid
     */
    public static function macros(array $macros): void
    {
        foreach ($macros as $name => $callback) {
            static::macro($name, $callback);
        }
    }

    /**
     * Check if a macro is registered
     *
     * @param string $name The name of the macro
     * @return bool
     */
    public static function hasMacro(string $name): bool
    {
        return isset(static::$_macros[static::class][$name]);
    }

    /**
     * Get all registered macros for this class
     *
     * @return array<string, callable>
     */
    public static function getMacros(): array
    {
        return static::$_macros[static::class] ?? [];
    }

    /**
     * Get macro names only
     *
     * @return array<string>
     */
    public static function getMacroNames(): array
    {
        return array_keys(static::getMacros());
    }

    /**
     * Remove a registered macro
     *
     * @param string $name The name of the macro to remove
     * @return bool True if macro was removed, false if it didn't exist
     */
    public static function removeMacro(string $name): bool
    {
        if (!static::hasMacro($name)) {
            return false;
        }

        unset(static::$_macros[static::class][$name]);
        return true;
    }

    /**
     * Clear all macros for this class
     *
     * @return void
     */
    public static function clearMacros(): void
    {
        static::$_macros[static::class] = [];
    }

    /**
     * Handle static macro calls
     *
     * @param string $method The method name
     * @param array $parameters The method parameters
     * @return mixed
     * @throws BadMethodCallException If macro doesn't exist
     */
    public static function __callStatic(string $method, array $parameters)
    {
        if (!static::hasMacro($method)) {
            throw new BadMethodCallException(
                sprintf('Static method %s::%s does not exist.', static::class, $method)
            );
        }

        try {
            $macro = static::$_macros[static::class][$method];

            if ($macro instanceof Closure) {
                $macro = $macro->bindTo(null, static::class);
            }

            return $macro(...$parameters);
        } catch (Throwable $e) {
            static::logErrorMacro("Error calling static macro '{$method}': " . $e->getMessage(), [
                'method' => $method,
                'parameters' => $parameters,
                'exception' => $e
            ]);
            throw $e;
        }
    }

    /**
     * Handle instance macro calls
     *
     * @param string $method The method name
     * @param array $parameters The method parameters
     * @return mixed
     * @throws BadMethodCallException If method doesn't exist
     */
    public function __call(string $method, array $parameters)
    {
        // First check for scopes if trait is available
        if (method_exists($this, 'hasScope') && $this->hasScope($method)) {
            try {
                return $this->callScope($method, $parameters);
            } catch (Throwable $e) {
                $this->logErrorMacro("Error calling scope '{$method}': " . $e->getMessage(), [
                    'method' => $method,
                    'parameters' => $parameters,
                    'exception' => $e
                ]);
                throw $e;
            }
        }

        // Then check for macros
        if (!static::hasMacro($method)) {
            throw new BadMethodCallException(
                sprintf('Method %s::%s does not exist.', static::class, $method)
            );
        }

        try {
            $macro = static::$_macros[static::class][$method];

            if ($macro instanceof Closure) {
                $macro = $macro->bindTo($this, static::class);
            }

            return $macro(...$parameters);
        } catch (Throwable $e) {
            $this->logErrorMacro("Error calling macro '{$method}': " . $e->getMessage(), [
                'method' => $method,
                'parameters' => $parameters,
                'exception' => $e
            ]);
            throw $e;
        }
    }

    /**
     * Log error message
     *
     * @param string $message The error message
     * @param array $context Additional context
     * @return void
     */
    protected static function logErrorMacro(string $message, array $context = []): void
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