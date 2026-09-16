<?php

/*
|--------------------------------------------------------------------------
| APPLICATION
|--------------------------------------------------------------------------
|
| Loaded by bootstrap.php as $config['app'], so every value here is reachable
| through config('app.<key>').
|
*/

return [

    /*
    |----------------------------------------------------------------------
    | Encryption Key
    |----------------------------------------------------------------------
    |
    | Read by Core\Security\Encryptor (AES-256-GCM column encryption) and by
    | Core\Security\SignedUrl (HMAC-SHA256 signed URLs). Both throw a
    | RuntimeException when it is empty, so encryption and signed URLs are
    | non-functional until this is set.
    |
    | Generate and write it with:  php myth key:generate
    |
    | Accepts either 64 hex characters (a raw 256-bit key) or any other string,
    | which is stretched to 256 bits with SHA-256 before use.
    |
    | Rotating this key makes every previously encrypted column and every
    | outstanding signed URL unreadable. Re-encrypt before you rotate.
    |
    */
    'key' => (string) env('APP_KEY', ''),

    /*
    |----------------------------------------------------------------------
    | Identity
    |----------------------------------------------------------------------
    */
    'name' => (string) env('APP_NAME', 'SimplePHP'),
    'url'  => (string) env('APP_URL', 'http://localhost'),

];
