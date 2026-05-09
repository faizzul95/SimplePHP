<?php

namespace App\Support;

use Core\Events\ShouldQueue;
use Core\Events\StoppableEvent;
use Core\Events\QueuedListenerJob;
use Core\Queue\Dispatcher as QueueDispatcher;

/**
 * Enhanced EventDispatcher.
 *
 * Supports:
 *   - Synchronous listeners (callable or class-string with handle() method)
 *   - Wildcard listeners via the '*' event name
 *   - Stoppable event propagation (events extending StoppableEvent)
 *   - Queued listeners (listeners implementing ShouldQueue interface)
 *   - Model observer integration (via ModelObserverRegistry)
 */
class EventDispatcher
{
    protected array $listeners = [];

    public function listen(string $event, callable|string $listener): void
    {
        $event = trim($event);
        if ($event === '') {
            return;
        }

        $this->listeners[$event] ??= [];
        $this->listeners[$event][] = $listener;
    }

    public function forget(string $event, callable|string|null $listener = null): void
    {
        $event = trim($event);
        if ($event === '') {
            return;
        }

        if ($listener === null) {
            unset($this->listeners[$event]);
            return;
        }

        if (!isset($this->listeners[$event])) {
            return;
        }

        $this->listeners[$event] = array_values(array_filter(
            $this->listeners[$event],
            static fn($existing) => $existing !== $listener
        ));

        if ($this->listeners[$event] === []) {
            unset($this->listeners[$event]);
        }
    }

    /**
     * Dispatch an event to every registered listener.
     *
     * - Checks stoppable propagation: if the event extends StoppableEvent and
     *   a listener calls $event->stopPropagation(), no further listeners run.
     * - Supports wildcard ('*') listeners that receive every event.
     * - ShouldQueue listeners are dispatched to the queue; return value is a job ID.
     *
     * @param  string|object  $event   Event name string OR a StoppableEvent object
     * @param  array          $payload Additional data for string-based events
     * @return array                   Responses from each synchronous listener
     */
    public function dispatch(string|object $event, array $payload = []): array
    {
        // Support object-style dispatch: dispatch(new UserRegistered($userId))
        if (is_object($event)) {
            $eventObject = $event;
            $eventName   = get_class($event);
            $payload     = ['event' => $eventObject];
        } else {
            $eventObject = null;
            $eventName   = $event;
        }

        $responses = [];

        // Collect specific + wildcard listeners
        $allListeners = array_merge(
            $this->listeners[$eventName] ?? [],
            $this->listeners['*'] ?? []
        );

        foreach ($allListeners as $listener) {
            // Stoppable event check
            if ($eventObject instanceof StoppableEvent && $eventObject->isPropagationStopped()) {
                break;
            }

            try {
                $responses[] = $this->callListener($listener, $eventObject, $eventName, $payload);
            } catch (\Throwable $e) {
                if (function_exists('logger')) {
                    try {
                        logger()->error('Event listener failed', [
                            'event'     => $eventName,
                            'listener'  => is_string($listener) ? $listener : (is_array($listener) ? implode('::', $listener) : 'closure'),
                            'exception' => get_class($e),
                            'message'   => $e->getMessage(),
                            'file'      => $e->getFile(),
                            'line'      => $e->getLine(),
                        ]);
                    } catch (\Throwable) {
                        // logger unavailable — swallow
                    }
                }
                $responses[] = $e;
            }
        }

        return $responses;
    }

    /**
     * Call a single listener — handles class-string, ShouldQueue, callable.
     */
    private function callListener(
        callable|string $listener,
        ?object $eventObject,
        string $eventName,
        array $payload
    ): mixed {
        // Class-string listener with optional ShouldQueue support
        if (is_string($listener) && class_exists($listener)) {
            $instance = new $listener();

            // If listener implements ShouldQueue, push it to the background worker
            if ($instance instanceof ShouldQueue) {
                return $this->queueListener($instance, $listener, $eventObject, $eventName, $payload);
            }

            if (method_exists($instance, 'handle')) {
                return $eventObject !== null
                    ? $instance->handle($eventObject)
                    : $instance->handle($payload);
            }

            return null;
        }

        // Callable listener (closure or array)
        if (is_callable($listener)) {
            return $eventObject !== null ? $listener($eventObject) : $listener($payload);
        }

        return null;
    }

    /**
     * Push a ShouldQueue listener into the background queue.
     */
    private function queueListener(
        ShouldQueue $instance,
        string $listenerClass,
        ?object $eventObject,
        string $eventName,
        array $payload
    ): string {
        $queue = (string) ($instance->queue ?? 'default');
        $delay = (int)    ($instance->delay ?? 0);

        $serializedPayload = $eventObject !== null
            ? ['__serialized' => base64_encode(serialize($eventObject))]
            : $payload;

        $job = new QueuedListenerJob($listenerClass, $eventName, $serializedPayload);
        $job->queue = $queue;
        $job->delay = $delay;

        try {
            $config   = function_exists('config') ? (config('queue') ?? []) : [];
            $queueDispatcher = new QueueDispatcher($config);
            $queueDispatcher->dispatch($job);
            return "queued:{$listenerClass}";
        } catch (\Throwable $e) {
            // Queue unavailable — fall back to synchronous execution
            if (method_exists($instance, 'handle')) {
                return $eventObject !== null
                    ? $instance->handle($eventObject)
                    : $instance->handle($payload);
            }
            return 'fallback_sync';
        }
    }

    public function listeners(string $event): array
    {
        return $this->listeners[$event] ?? [];
    }

    public function reset(): void
    {
        $this->listeners = [];
    }

    /**
     * Check if any listeners are registered for the given event.
     */
    public function hasListeners(string $event): bool
    {
        return !empty($this->listeners[$event]) || !empty($this->listeners['*']);
    }
}
