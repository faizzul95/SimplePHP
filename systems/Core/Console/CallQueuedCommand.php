<?php

namespace Core\Console;

use Core\Queue\Job;

/**
 * Job wrapper for queued console commands.
 *
 * Used internally by Myth::queue() to dispatch a console command
 * as a background job via the queue system.
 *
 * @see \Core\Console\Myth::queue()
 */
class CallQueuedCommand extends Job
{
    public string $commandLine;
    public array $parameters;

    public function __construct(string $commandLine, array $parameters = [])
    {
        $this->commandLine = $commandLine;
        $this->parameters = $parameters;
    }

    /**
     * Execute the queued command.
     */
    public function handle(): void
    {
        $kernel = new Kernel();
        $kernel->bootstrap();
        $kernel->callSilently($this->commandLine, $this->parameters);
    }
}
