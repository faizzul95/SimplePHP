<?php

declare(strict_types=1);

namespace Core\Mail;

use Core\Queue\Job;

/**
 * Sends a queued Message.
 *
 * The message travels as an array rather than an object so the payload stays
 * readable in the jobs table and survives a class being renamed between the
 * dispatch and the run.
 */
final class SendMailJob extends Job
{
    public string $queue = 'mail';

    public int $priority = Job::PRIORITY_HIGH;

    /** SMTP is slow and occasionally unreachable; three tries covers a blip. */
    public ?int $tries = 3;

    public ?int $timeout = 60;

    /** @param array<string, mixed> $payload */
    public function __construct(private array $payload)
    {
    }

    public function handle(): void
    {
        $message = Message::fromArray($this->payload);

        if (!$message->hasRecipient()) {
            // Nothing retryable about a message with no valid address.
            return;
        }

        $config = function_exists('config') ? (array) (config('mail') ?? []) : [];
        $result = (new Mailer($config))->send($message);

        if (($result['success'] ?? false) !== true) {
            /*
            | Throwing is what hands the job back to the worker's retry budget.
            | Returning quietly would mark a mail that never went out as done.
            */
            throw new \RuntimeException('Queued mail failed: ' . (string) ($result['message'] ?? 'unknown error'));
        }
    }
}
