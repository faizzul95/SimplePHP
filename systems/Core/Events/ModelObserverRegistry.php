<?php

declare(strict_types=1);

namespace Core\Events;

/**
 * Central registry for Model Observers.
 *
 * Associates observer instances with model class names.
 * The Model base class calls the static helpers here during lifecycle events.
 *
 * Thread-safe in worker mode: static state is reset by WorkerState::flush() if
 * this class is registered there, but observers are typically registered once at
 * boot and should remain across requests — register via AppServiceProvider.
 */
final class ModelObserverRegistry
{
    /** @var array<class-string, ModelObserver[]> */
    private static array $observers = [];

    /**
     * Register an observer for a model class.
     *
     * @param class-string  $modelClass FQN of the Model subclass
     * @param ModelObserver $observer   Observer instance
     */
    public static function observe(string $modelClass, ModelObserver $observer): void
    {
        self::$observers[$modelClass][] = $observer;
    }

    /**
     * Remove all observers for a model class (useful in tests).
     */
    public static function forget(string $modelClass): void
    {
        unset(self::$observers[$modelClass]);
    }

    /**
     * Remove all registered observers.
     */
    public static function reset(): void
    {
        self::$observers = [];
    }

    /**
     * Fire a lifecycle hook on all observers registered for $modelClass.
     *
     * @param class-string $modelClass
     * @param string       $event     Hook name: 'creating', 'created', 'updating', etc.
     * @param array        $data      Row data passed to the observer
     * @return bool                   false if any "before" observer cancelled the operation
     */
    public static function fire(string $modelClass, string $event, array $data): bool
    {
        $observers = self::$observers[$modelClass] ?? [];

        // Walk up the inheritance chain to also fire parent-model observers
        $parent = get_parent_class($modelClass);
        while ($parent && $parent !== \Core\Database\Model::class && $parent !== false) {
            $observers = array_merge($observers, self::$observers[$parent] ?? []);
            $parent = get_parent_class($parent);
        }

        foreach ($observers as $observer) {
            if (!method_exists($observer, $event)) {
                continue;
            }

            $result = $observer->$event($data);

            // "Before" hooks (creating, updating, deleting, forceDeleting) can return false to cancel
            if ($result === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if any observers are registered for the given model class.
     */
    public static function hasObservers(string $modelClass): bool
    {
        return !empty(self::$observers[$modelClass]);
    }
}
