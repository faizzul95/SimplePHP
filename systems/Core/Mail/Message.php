<?php

declare(strict_types=1);

namespace Core\Mail;

/**
 * An email, built up and then handed to the Mailer.
 *
 * Addresses are validated as they are added rather than at send time, so a
 * typo surfaces at the call site that introduced it. Duplicates are dropped:
 * PHPMailer refuses a repeated recipient and returns false, which used to read
 * as "the server rejected the mail".
 */
final class Message
{
    /** @var list<array{email: string, name: string}> */
    private array $to = [];

    /** @var list<array{email: string, name: string}> */
    private array $cc = [];

    /** @var list<array{email: string, name: string}> */
    private array $bcc = [];

    /** @var list<array{email: string, name: string}> */
    private array $replyTo = [];

    private ?string $fromEmail = null;

    private string $fromName = '';

    private string $subject = '';

    private string $html = '';

    private string $text = '';

    /** @var list<string> */
    private array $attachments = [];

    public static function make(): self
    {
        return new self();
    }

    /**
     * Build from the shape the legacy sendEmail() helper accepts.
     *
     * @param array<string, mixed> $recipient
     * @param string|list<string>|null $attachment
     */
    public static function fromLegacy(array $recipient, string $subject, string $body, mixed $attachment = null): self
    {
        $message = (new self())
            ->to(
                trim((string) ($recipient['recipient_email'] ?? '')),
                trim((string) ($recipient['recipient_name'] ?? ''))
            )
            ->subject($subject)
            ->html($body);

        foreach ((array) ($recipient['recipient_cc'] ?? []) as $cc) {
            if (is_string($cc) || is_int($cc)) { $message->cc((string) $cc); }
        }

        foreach ((array) ($recipient['recipient_bcc'] ?? []) as $bcc) {
            if (is_string($bcc) || is_int($bcc)) { $message->bcc((string) $bcc); }
        }

        foreach ((array) ($recipient['reply_to'] ?? []) as $replyTo) {
            if (is_string($replyTo) || is_int($replyTo)) { $message->replyTo((string) $replyTo); }
        }

        foreach ((array) ($attachment ?? []) as $file) {
            if (is_string($file)) { $message->attach($file); }
        }

        return $message;
    }

    public function to(string $email, string $name = ''): self
    {
        $this->push($this->to, $email, $name);

        return $this;
    }

    public function cc(string $email, string $name = ''): self
    {
        $this->push($this->cc, $email, $name);

        return $this;
    }

    public function bcc(string $email, string $name = ''): self
    {
        $this->push($this->bcc, $email, $name);

        return $this;
    }

    public function replyTo(string $email, string $name = ''): self
    {
        $this->push($this->replyTo, $email, $name);

        return $this;
    }

    public function from(string $email, string $name = ''): self
    {
        if ($this->isValidAddress($email)) {
            $this->fromEmail = strtolower(trim($email));
            $this->fromName = $this->sanitizeHeader($name);
        }

        return $this;
    }

    public function subject(string $subject): self
    {
        /*
        | A newline in a subject starts a new header, which is how an attacker
        | adds a Bcc to somebody else's mail. PHPMailer strips these too; doing
        | it here means the Message is safe whatever consumes it.
        */
        $this->subject = $this->sanitizeHeader($subject);

        return $this;
    }

    public function html(string $html): self
    {
        $this->html = $html;

        return $this;
    }

    public function text(string $text): self
    {
        $this->text = $text;

        return $this;
    }

    public function attach(string $path): self
    {
        if ($path !== '' && !in_array($path, $this->attachments, true)) {
            $this->attachments[] = $path;
        }

        return $this;
    }

    /** @return list<array{email: string, name: string}> */
    public function recipients(): array
    {
        return $this->to;
    }

    /** @return list<array{email: string, name: string}> */
    public function ccList(): array
    {
        return $this->cc;
    }

    /** @return list<array{email: string, name: string}> */
    public function bccList(): array
    {
        return $this->bcc;
    }

    /** @return list<array{email: string, name: string}> */
    public function replyToList(): array
    {
        return $this->replyTo;
    }

    public function fromEmail(): ?string
    {
        return $this->fromEmail;
    }

    public function fromName(): string
    {
        return $this->fromName;
    }

    public function subjectLine(): string
    {
        return $this->subject;
    }

    public function htmlBody(): string
    {
        return $this->html;
    }

    /** Falls back to a text rendering of the HTML so non-HTML clients see something. */
    public function textBody(): string
    {
        if ($this->text !== '') {
            return $this->text;
        }

        if ($this->html === '') {
            return '';
        }

        $plain = preg_replace('/<br\s*\/?>|<\/p>|<\/div>|<\/tr>/i', "\n", $this->html) ?? $this->html;

        return trim(html_entity_decode(strip_tags($plain), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /** @return list<string> */
    public function attachmentPaths(): array
    {
        return $this->attachments;
    }

    public function hasRecipient(): bool
    {
        return $this->to !== [];
    }

    /**
     * A queue-safe representation. Bodies can be large, so a queued message is
     * stored as data rather than a serialized object graph.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'to' => $this->to,
            'cc' => $this->cc,
            'bcc' => $this->bcc,
            'reply_to' => $this->replyTo,
            'from_email' => $this->fromEmail,
            'from_name' => $this->fromName,
            'subject' => $this->subject,
            'html' => $this->html,
            'text' => $this->text,
            'attachments' => $this->attachments,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $message = new self();

        foreach (['to', 'cc', 'bcc', 'reply_to'] as $bucket) {
            foreach ((array) ($data[$bucket] ?? []) as $entry) {
                if (!is_array($entry)) { continue; }
                $email = (string) ($entry['email'] ?? '');
                $name = (string) ($entry['name'] ?? '');

                match ($bucket) {
                    'to' => $message->to($email, $name),
                    'cc' => $message->cc($email, $name),
                    'bcc' => $message->bcc($email, $name),
                    default => $message->replyTo($email, $name),
                };
            }
        }

        $fromEmail = $data['from_email'] ?? null;
        if (is_string($fromEmail) && $fromEmail !== '') {
            $message->from($fromEmail, (string) ($data['from_name'] ?? ''));
        }

        $message->subject((string) ($data['subject'] ?? ''));
        $message->html((string) ($data['html'] ?? ''));
        $message->text((string) ($data['text'] ?? ''));

        foreach ((array) ($data['attachments'] ?? []) as $path) {
            if (is_string($path)) { $message->attach($path); }
        }

        return $message;
    }

    /** @param list<array{email: string, name: string}> $bucket */
    private function push(array &$bucket, string $email, string $name): void
    {
        $email = strtolower(trim($email));
        if (!$this->isValidAddress($email)) {
            return;
        }

        foreach ($bucket as $existing) {
            if ($existing['email'] === $email) {
                return;
            }
        }

        $bucket[] = ['email' => $email, 'name' => $this->sanitizeHeader($name)];
    }

    private function isValidAddress(string $email): bool
    {
        $email = trim($email);

        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    private function sanitizeHeader(string $value): string
    {
        return trim(str_replace(["\r", "\n", "\0"], '', $value));
    }
}
