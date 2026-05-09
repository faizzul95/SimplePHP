<?php

declare(strict_types=1);

namespace Core\Events;

/**
 * Base class for events that support stopping propagation mid-listener-chain.
 *
 * Usage:
 *   class UserRegistered extends StoppableEvent
 *   {
 *       public function __construct(public readonly int $userId) {}
 *   }
 *
 *   // In a listener:
 *   public function handle(UserRegistered $event): void
 *   {
 *       $event->stopPropagation(); // no further listeners receive this event
 *   }
 */
class StoppableEvent
{
    private bool $propagationStopped = false;

    /**
     * Signal that no further listeners should be called for this event.
     */
    public function stopPropagation(): void
    {
        $this->propagationStopped = true;
    }

    /**
     * Whether propagation has been stopped by a previous listener.
     */
    public function isPropagationStopped(): bool
    {
        return $this->propagationStopped;
    }
}
