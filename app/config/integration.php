<?php

/*
|--------------------------------------------------------------------------
| Credentials (API KEY, Secret Key, etc)
|--------------------------------------------------------------------------
*/

$config['credentials'] = [
    'google_auth' => [
        'client_id' => (string) env('GOOGLE_CLIENT_ID', ''),
        'client_secret' => (string) env('GOOGLE_CLIENT_SECRET', ''),
        'cookie_policy' => 'single_host_origin',
        'redirect_uri' => (string) env('GOOGLE_REDIRECT_URI', ''), // paramUrl(['_p' => REDIRECT_LOGIN], true)
    ],
    'recaptcha' => [
        'enable' => (bool) env('RECAPTCHA_ENABLED', false),
        'site_key' => (string) env('RECAPTCHA_SITE_KEY', ''),
        'secret_key' => (string) env('RECAPTCHA_SECRET_KEY', ''),
    ],
];
