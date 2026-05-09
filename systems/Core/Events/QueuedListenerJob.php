<?php

declare(strict_types=1);

namespace Core\Events;

use Core\Queue\Job;

/**
 * Internal queue job that wraps a ShouldQueue event listener.
 *
 * Created automatically by EventDispatcher when a listener implements ShouldQueue.
 * The listener's handle() method is called from the queue worker with the
 * serialized event payload.
 */
final class QueuedListenerJob extends Job
{
    public function __construct(
        private readonly string $listenerClass,
        private readonly string $eventClass,
        private readonly array  $eventPayload
    ) {
        $this->queue = 'default';
    }

    public function handle(): void
    {
        if (!class_exists($this->listenerClass)) {
            throw new \RuntimeException("Queued listener class [{$this->listenerClass}] not found.");
        }

        $listener = new $this->listenerClass();

        if (!empty($this->eventPayload) && !empty($this->eventClass) && class_exists($this->eventClass)) {
            $event = unserialize(base64_decode($this->eventPayload['__serialized'] ?? ''), [
                'allowed_classes' => [$this->eventClass, StoppableEvent::class],
            ]);
            if ($event !== false && method_exists($listener, 'handle')) {
                $listener->handle($event);
                return;
            }
        }

        // Fallback: call handle() with raw payload array
        if (method_exists($listener, 'handle')) {
            $listener->handle($this->eventPayload);
        }
    }
}
