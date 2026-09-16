<?php

declare(strict_types=1);

namespace Core\Mail;

use Core\Support\SafeLog;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

/**
 * Sends a Message through one of four drivers.
 *
 * Why this exists rather than the helper it replaces:
 *
 * - The helper had no timeout, so PHPMailer's 300-second default applied. A
 *   dead SMTP host held the request open for five minutes; the password-reset
 *   route was one `sendEmail()` away from that.
 * - It returned `$mail->ErrorInfo` to the caller, which carries the SMTP
 *   conversation — hostnames, banner, sometimes credentials-adjacent detail —
 *   straight into an HTTP response body.
 * - It set `SMTPAuth = true` unconditionally, so a local Mailpit or MailHog
 *   with no auth could not be used at all.
 * - There was no way to exercise an email path in a test without a live server.
 *
 * Drivers: `smtp` sends; `log` writes the message to the log and reports
 * success; `array` records it in memory for tests; `null` discards it.
 */
final class Mailer
{
    public const DRIVER_SMTP = 'smtp';
    public const DRIVER_LOG = 'log';
    public const DRIVER_ARRAY = 'array';
    public const DRIVER_NULL = 'null';

    /** Messages captured by the `array` driver, newest last. @var list<Message> */
    private static array $captured = [];

    /** @var array<string, mixed> */
    private array $config;

    /** @param array<string, mixed> $config */
    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    /**
     * Send now.
     *
     * @return array{success: bool, message: string}
     */
    public function send(Message $message): array
    {
        if (!$message->hasRecipient()) {
            return $this->failure('Invalid recipient email address');
        }

        $driver = $this->driver();
        $started = microtime(true);

        try {
            $result = match ($driver) {
                self::DRIVER_ARRAY => $this->sendToArray($message),
                self::DRIVER_LOG => $this->sendToLog($message),
                self::DRIVER_NULL => $this->success('Email discarded by the null driver'),
                default => $this->sendOverSmtp($message),
            };

            $this->recordTelemetry($message, $driver, $started, $result['success']);

            return $result;
        } catch (\Throwable $e) {
            /*
            | The detail goes to the log; the caller gets a sentence it can show
            | a user. Returning ErrorInfo was how SMTP hostnames ended up in
            | HTTP responses.
            */
            SafeLog::error(sprintf(
                'Mail send failed via [%s] to [%s]: %s',
                $driver,
                $this->primaryRecipient($message),
                $e->getMessage()
            ));

            $this->recordTelemetry($message, $driver, $started, false, $e->getMessage());

            return $this->failure('Unable to send the email. The failure has been logged.');
        }
    }

    /**
     * Feed the debug bar.
     *
     * The body is included: seeing what was actually rendered into an email is
     * most of why you look. The Recorder redacts by key and truncates by
     * length, and the whole thing is a no-op unless telemetry is switched on.
     */
    private function recordTelemetry(Message $message, string $driver, float $started, bool $sent, ?string $error = null): void
    {
        if (!function_exists('telemetry')) {
            return;
        }

        try {
            telemetry()->recordMail([
                'driver' => $driver,
                'sent' => $sent,
                'error' => $error,
                'to' => array_column($message->recipients(), 'email'),
                'cc' => array_column($message->ccList(), 'email'),
                'bcc' => array_column($message->bccList(), 'email'),
                'subject' => $message->subjectLine(),
                'body' => $message->htmlBody(),
                'attachments' => count($message->attachmentPaths()),
            ], (microtime(true) - $started) * 1000);
        } catch (\Throwable) {
            // Observing a send must not break it.
        }
    }

    /**
     * Hand the message to the queue and return immediately.
     *
     * Returns the job id, or null when no queue is configured — in which case
     * nothing is lost, because the message is sent inline instead.
     */
    public function queue(Message $message): ?string
    {
        if (!$message->hasRecipient()) {
            return null;
        }

        if (!function_exists('dispatch')) {
            $this->send($message);

            return null;
        }

        try {
            return dispatch(new SendMailJob($message->toArray()));
        } catch (\Throwable $e) {
            // A queue that is down must not silently drop the mail.
            SafeLog::warning('Queueing mail failed, sending inline instead: ' . $e->getMessage());
            $this->send($message);

            return null;
        }
    }

    public function driver(): string
    {
        $driver = strtolower(trim((string) ($this->config['driver'] ?? self::DRIVER_SMTP)));

        return in_array($driver, [self::DRIVER_SMTP, self::DRIVER_LOG, self::DRIVER_ARRAY, self::DRIVER_NULL], true)
            ? $driver
            : self::DRIVER_SMTP;
    }

    // ─── Test support ────────────────────────────────────────────────

    /** @return list<Message> */
    public static function captured(): array
    {
        return self::$captured;
    }

    public static function lastCaptured(): ?Message
    {
        return self::$captured === [] ? null : self::$captured[count(self::$captured) - 1];
    }

    public static function flushCaptured(): void
    {
        self::$captured = [];
    }

    // ─── Drivers ─────────────────────────────────────────────────────

    /** @return array{success: bool, message: string} */
    private function sendToArray(Message $message): array
    {
        self::$captured[] = $message;

        return $this->success('Email captured by the array driver');
    }

    /** @return array{success: bool, message: string} */
    private function sendToLog(Message $message): array
    {
        SafeLog::debug(sprintf(
            "Mail [log driver]\n  To: %s\n  Subject: %s\n  Body: %s",
            $this->primaryRecipient($message),
            $message->subjectLine(),
            $message->textBody()
        ));

        return $this->success('Email written to the log');
    }

    /** @return array{success: bool, message: string} */
    private function sendOverSmtp(Message $message): array
    {
        $mail = new PHPMailer(true);

        $mail->CharSet = 'UTF-8';
        $mail->Encoding = PHPMailer::ENCODING_BASE64;

        /*
        | Bound, because PHPMailer's default is 300 seconds and a request
        | blocked on an unreachable relay is indistinguishable from a hung app.
        */
        $mail->Timeout = $this->timeout();
        $mail->SMTPKeepAlive = false;

        if ($this->debugEnabled()) {
            $mail->SMTPDebug = SMTP::DEBUG_SERVER;
            $mail->Debugoutput = static function (string $line): void {
                SafeLog::debug('SMTP: ' . trim($line));
            };
        }

        $mail->isSMTP();
        $mail->Host = (string) ($this->config['host'] ?? '');
        $mail->Port = (int) ($this->config['port'] ?? 587);

        $username = (string) ($this->config['username'] ?? '');
        $password = (string) ($this->config['password'] ?? '');

        /*
        | Only authenticate when there are credentials. Forcing SMTPAuth on made
        | a local Mailpit/MailHog instance — which accepts no auth — unusable.
        */
        $mail->SMTPAuth = $username !== '';
        if ($mail->SMTPAuth) {
            $mail->Username = $username;
            $mail->Password = $password;
        }

        $encryption = strtolower(trim((string) ($this->config['encryption'] ?? 'tls')));
        $mail->SMTPSecure = match ($encryption) {
            'ssl', 'smtps' => PHPMailer::ENCRYPTION_SMTPS,
            '', 'none', 'null', 'false' => '',
            default => PHPMailer::ENCRYPTION_STARTTLS,
        };
        $mail->SMTPAutoTLS = $mail->SMTPSecure !== '';

        $fromEmail = $message->fromEmail() ?? (string) ($this->config['from_email'] ?? '');
        $fromName = $message->fromName() !== '' ? $message->fromName() : (string) ($this->config['from_name'] ?? '');

        if ($fromEmail === '' || filter_var($fromEmail, FILTER_VALIDATE_EMAIL) === false) {
            SafeLog::error('Mail not sent: mail.from_email is missing or not a valid address.');

            return $this->failure('Email is not configured correctly on this server.');
        }

        $mail->setFrom($fromEmail, $fromName);

        foreach ($message->recipients() as $to) {
            $mail->addAddress($to['email'], $to['name']);
        }

        foreach ($message->ccList() as $cc) {
            $mail->addCC($cc['email'], $cc['name']);
        }

        foreach ($message->bccList() as $bcc) {
            $mail->addBCC($bcc['email'], $bcc['name']);
        }

        foreach ($message->replyToList() as $replyTo) {
            $mail->addReplyTo($replyTo['email'], $replyTo['name']);
        }

        $this->attachTo($mail, $message);

        $mail->isHTML(true);
        $mail->Subject = $message->subjectLine();
        $mail->Body = $message->htmlBody();
        $mail->AltBody = $message->textBody();

        // Exceptions are on, so a false here means PHPMailer declined without throwing.
        if (!$mail->send()) {
            SafeLog::error('Mail send returned false: ' . $mail->ErrorInfo);

            return $this->failure('Unable to send the email. The failure has been logged.');
        }

        return $this->success('Email sent successfully');
    }

    private function attachTo(PHPMailer $mail, Message $message): void
    {
        $maxFiles = max(0, (int) ($this->config['max_attachments'] ?? 10));
        $maxBytes = max(0, (int) ($this->config['max_attachment_bytes'] ?? 10 * 1024 * 1024));
        $budget = $maxBytes;
        $count = 0;

        foreach ($message->attachmentPaths() as $path) {
            if ($count >= $maxFiles) {
                SafeLog::warning('Mail attachment skipped: more than ' . $maxFiles . ' files.');
                break;
            }

            // canReadPath() keeps an attachment inside the paths the app owns,
            // so a caller cannot mail out /etc/passwd by passing its path.
            if (function_exists('security') && !security()->canReadPath($path)) {
                SafeLog::warning('Mail attachment refused, path is outside the allowed roots.');
                continue;
            }

            if (!is_file($path) || !is_readable($path)) {
                SafeLog::warning('Mail attachment skipped, not a readable file.');
                continue;
            }

            $size = (int) (filesize($path) ?: 0);
            if ($maxBytes > 0 && $size > $budget) {
                SafeLog::warning('Mail attachment skipped, total size would exceed the configured limit.');
                continue;
            }

            $budget -= $size;
            $count++;
            $mail->addAttachment($path);
        }
    }

    // ─── Helpers ─────────────────────────────────────────────────────

    private function timeout(): int
    {
        $timeout = (int) ($this->config['timeout'] ?? 10);

        return $timeout > 0 ? min($timeout, 120) : 10;
    }

    private function debugEnabled(): bool
    {
        return filter_var($this->config['debug'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    private function primaryRecipient(Message $message): string
    {
        $recipients = $message->recipients();

        return $recipients === [] ? '(none)' : $recipients[0]['email'];
    }

    /** @return array{success: bool, message: string} */
    private function success(string $message): array
    {
        return ['success' => true, 'message' => $message];
    }

    /** @return array{success: bool, message: string} */
    private function failure(string $message): array
    {
        return ['success' => false, 'message' => $message];
    }
}
