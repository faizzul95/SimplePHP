<?php

declare(strict_types=1);

namespace Core\Events;

/**
 * Marker interface for event listeners that should be dispatched through the queue.
 *
 * When EventDispatcher encounters a listener class implementing this interface,
 * instead of calling the listener synchronously it dispatches a QueuedListenerJob
 * to the queue system — the listener's handle() method runs in a background worker.
 *
 * Usage:
 *   class SendWelcomeEmail implements ShouldQueue
 *   {
 *       public string $queue = 'emails';
 *       public int    $delay = 0;
 *
 *       public function handle(UserRegistered $event): void
 *       {
 *           // runs in background worker
 *       }
 *   }
 *
 *   // Register like any listener:
 *   Events::listen('UserRegistered', SendWelcomeEmail::class);
 */
interface ShouldQueue
{
    /**
     * The queue channel this listener should run on.
     * Defaults to 'default' when the property is absent.
     */
    // public string $queue = 'default';

    /**
     * Seconds to delay execution.
     * Defaults to 0 (immediate) when the property is absent.
     */
    // public int $delay = 0;
}
