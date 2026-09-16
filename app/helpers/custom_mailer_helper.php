<?php

use Core\Mail\Mailer;
use Core\Mail\Message;

/*
|--------------------------------------------------------------------------
| Mailer helper
|--------------------------------------------------------------------------
|
| These wrap Core\Mail. The signatures are unchanged, so existing callers keep
| working; what changed is underneath — a bounded SMTP timeout, a generic error
| message with the detail in the log rather than the SMTP conversation in the
| HTTP response, and log/array drivers so an email path can be exercised
| without a live server.
|
|   sendEmail($recipient, $subject, $body);        // send now
|   queueEmail($recipient, $subject, $body);       // hand to the queue
|   mail_message()->to(...)->subject(...)          // build one directly
|
| $recipient accepts:
|   recipient_email  (required)   recipient_name
|   recipient_cc     string|array recipient_bcc  string|array
|   reply_to         string|array
*/

if (!function_exists('sendEmail')) {
    /**
     * @param array<string, mixed>|null $recipientData
     * @param string|array<int, string>|null $attachment
     * @return array{success: bool, message: string}
     */
    function sendEmail($recipientData = null, $subject = null, $dataBody = null, $attachment = null): array
    {
        return mailer()->send(buildMailMessage($recipientData, $subject, $dataBody, $attachment));
    }
}

if (!function_exists('sendUsingMailer')) {
    /**
     * Retained because callers exist; identical to sendEmail().
     *
     * @param array<string, mixed>|null $recipientData
     * @param string|array<int, string>|null $attachment
     * @return array{success: bool, message: string}
     */
    function sendUsingMailer($recipientData = null, $subject = null, $dataBody = null, $attachment = null): array
    {
        return sendEmail($recipientData, $subject, $dataBody, $attachment);
    }
}

if (!function_exists('queueEmail')) {
    /**
     * Send in the background, so an SMTP round-trip never sits inside a request.
     *
     * Returns the job id, or null when there is no queue — in which case the
     * message was sent inline rather than dropped.
     *
     * @param array<string, mixed>|null $recipientData
     * @param string|array<int, string>|null $attachment
     */
    function queueEmail($recipientData = null, $subject = null, $dataBody = null, $attachment = null): ?string
    {
        return mailer()->queue(buildMailMessage($recipientData, $subject, $dataBody, $attachment));
    }
}

if (!function_exists('buildMailMessage')) {
    /**
     * @param array<string, mixed>|null $recipientData
     * @param string|array<int, string>|null $attachment
     */
    function buildMailMessage($recipientData = null, $subject = null, $dataBody = null, $attachment = null): Message
    {
        return Message::fromLegacy(
            is_array($recipientData) ? $recipientData : [],
            (string) ($subject ?? ''),
            (string) ($dataBody ?? ''),
            $attachment
        );
    }
}

if (!function_exists('mailerCaptured')) {
    /**
     * Messages held by the `array` driver. For tests.
     *
     * @return list<Message>
     */
    function mailerCaptured(): array
    {
        return Mailer::captured();
    }
}

if (!function_exists('replaceTextWithData')) {
    /**
     * Substitute %placeholder% tokens in a template.
     *
     * Values are inserted as-is: templates are HTML and some placeholders are
     * meant to carry markup. Escape anything user-supplied before passing it.
     *
     * @param array<string, mixed> $arrayOfStringToReplace
     */
    function replaceTextWithData($string = null, $arrayOfStringToReplace = []): string
    {
        $subject = (string) ($string ?? '');
        if ($subject === '' || !is_array($arrayOfStringToReplace) || $arrayOfStringToReplace === []) {
            return $subject;
        }

        $search = [];
        $replace = [];

        foreach ($arrayOfStringToReplace as $key => $value) {
            if (is_array($value) || is_object($value)) {
                continue;
            }

            $search[] = '%' . $key . '%';
            $replace[] = (string) $value;
        }

        return $search === [] ? $subject : str_replace($search, $replace, $subject);
    }
}

if (!function_exists('arrayDataReplace')) {
    /**
     * The %key% => value map replaceTextWithData() uses.
     *
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    function arrayDataReplace($data): array
    {
        $map = [];

        foreach ((array) $data as $key => $value) {
            if (is_array($value) || is_object($value)) {
                continue;
            }

            $map['%' . $key . '%'] = (string) $value;
        }

        return $map;
    }
}
