<?php

/*
|--------------------------------------------------------------------------
| Credentials (API KEY, Secret Key, etc)
|--------------------------------------------------------------------------
*/

$config['credentials'] = [
    'google_auth' => [
        'client_id' => '',
        'client_secret' => '',
        'cookie_policy' => 'single_host_origin',
        'redirect_uri' => '', // paramUrl(['_p' => REDIRECT_LOGIN], true)
    ],
    'recaptcha' => [
        'enable' => false,
        'site_key' => '',
        'secret_key' => '',
    ],
];
