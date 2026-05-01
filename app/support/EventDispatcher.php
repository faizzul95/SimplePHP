<?php

namespace App\Support;

class EventDispatcher
{
    protected array $listeners = [];

    public function listen(string $event, callable $listener): void
    {
        $event = trim($event);
        if ($event === '') {
            return;
        }

        $this->listeners[$event] ??= [];
        $this->listeners[$event][] = $listener;
    }

    public function forget(string $event, ?callable $listener = null): void
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
     * Exceptions from listeners no longer halt the chain: each listener runs,
     * its response (or the caught Throwable) is returned to the caller. This
     * keeps one buggy listener from silently skipping the rest.
     */
    public function dispatch(string $event, array $payload = []): array
    {
        $responses = [];

        foreach ($this->listeners[$event] ?? [] as $listener) {
            try {
                $responses[] = $listener($payload);
            } catch (\Throwable $e) {
                if (function_exists('logger')) {
                    try {
                        logger()->error('Event listener failed', [
                            'event' => $event,
                            'exception' => get_class($e),
                            'message' => $e->getMessage(),
                            'file' => $e->getFile(),
                            'line' => $e->getLine(),
                        ]);
                    } catch (\Throwable) {
                        // logger unavailable — swallow to keep remaining listeners.
                    }
                }
                $responses[] = $e;
            }
        }

        return $responses;
    }

    public function listeners(string $event): array
    {
        return $this->listeners[$event] ?? [];
    }

    public function reset(): void
    {
        $this->listeners = [];
    }
}