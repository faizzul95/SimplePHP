<?php

/*
|--------------------------------------------------------------------------
| Mailer
|--------------------------------------------------------------------------
|
| Drivers:
|   smtp   send through the configured SMTP server
|   log    write the message to the log and report success — local development
|   array  keep the message in memory — tests, via Mailer::captured()
|   null   discard
|
| Leave MAIL_USERNAME empty to skip SMTP authentication, which is what a local
| Mailpit or MailHog expects.
|
| Consumed by Core\Mail\Mailer. See app/helpers/custom_mailer_helper.php for
| the sendEmail()/queueEmail() entry points.
*/

$config['mail'] = [
    'driver'     => (string) env('MAIL_DRIVER', 'smtp'),
    'host'       => (string) env('MAIL_HOST', 'smtp.gmail.com'),
    'port'       => (int) env('MAIL_PORT', 587),
    'username'   => (string) env('MAIL_USERNAME', ''),
    'password'   => (string) env('MAIL_PASSWORD', ''),  // Use .env for secrets.
    'encryption' => (string) env('MAIL_ENCRYPTION', 'tls'),   // tls | ssl | none
    'from_email' => (string) env('MAIL_FROM_ADDRESS', ''),
    'from_name'  => (string) env('MAIL_FROM_NAME', APP_NAME),
    'debug'      => (bool) env('MAIL_DEBUG', false),

    /*
    | Seconds to wait on the SMTP conversation. PHPMailer defaults to 300, which
    | means an unreachable relay holds a web request open for five minutes.
    | Capped at 120 by the Mailer regardless of what is set here.
    */
    'timeout'    => (int) env('MAIL_TIMEOUT', 10),

    'max_attachments'      => (int) env('MAIL_MAX_ATTACHMENTS', 10),
    'max_attachment_bytes' => (int) env('MAIL_MAX_ATTACHMENT_BYTES', 10 * 1024 * 1024),
];
