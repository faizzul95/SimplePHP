<?php

// Reserved for the planned Mailer component; runtime consumers are not implemented yet.

/*
|--------------------------------------------------------------------------
| Mailer
|--------------------------------------------------------------------------
*/

$config['mail'] = [
    'driver'     => (string) env('MAIL_DRIVER', 'smtp'),
    'host'       => (string) env('MAIL_HOST', 'smtp.gmail.com'),
    'port'       => (int) env('MAIL_PORT', 587),
    'username'   => (string) env('MAIL_USERNAME', ''),
    'password'   => (string) env('MAIL_PASSWORD', ''),  // Use .env for secrets.
    'encryption' => (string) env('MAIL_ENCRYPTION', 'tls'),
    'from_email' => (string) env('MAIL_FROM_ADDRESS', ''),
    'from_name'  => (string) env('MAIL_FROM_NAME', APP_NAME),
    'debug'      => (bool) env('MAIL_DEBUG', false),
];