<?php

/*
|--------------------------------------------------------------------------
| Mailer
|--------------------------------------------------------------------------
*/

$config['mail'] = [
    'driver'     => 'smtp',
    'host'       => 'smtp.gmail.com',
    'port'       => 587,
    'username'   => '',
    'password'   => '',  // need to set here https://myaccount.google.com/apppasswords
    'encryption' => 'tls',
    'from_email' => '',
    'from_name'  => APP_NAME,
    'debug'      => false,
];