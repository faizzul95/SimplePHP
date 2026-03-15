<?php

/*
|--------------------------------------------------------------------------
| AUTHENTICATION
|--------------------------------------------------------------------------
|
| Configure session keys, table names, column mappings, and social
| authentication (OAuth / Socialite) settings. All values can be
| changed here without touching framework code.
|
*/

$config['auth'] = [

    /*
    |----------------------------------------------------------------------
    | Session Configuration
    |----------------------------------------------------------------------
    */
    'session_flag'    => 'isLoggedIn',
    'session_user_id' => 'userID',

    // Default auth resolution order for auth()->check()/user()/id()/via().
    // Use least-privilege defaults globally; enable additional methods explicitly per route.
    // Supported values: session, token, jwt, api_key, oauth, basic, digest
    'methods' => ['session', 'token'],

    // Default methods for `auth.api` middleware (RequireApiToken).
    // Keep token-only by default, then opt into others as needed.
    'api_methods' => ['token'],

    'session_keys' => [
        'userFullName'  => 'userFullName',
        'userNickname'  => 'userNickname',
        'userEmail'     => 'userEmail',
        'roleID'        => 'roleID',
        'roleRank'      => 'roleRank',
        'roleName'      => 'roleName',
        'permissions'   => 'permissions',
        'userAvatar'    => 'userAvatar',
        'oauthProvider' => 'oauth_provider',
    ],

    /*
    |----------------------------------------------------------------------
    | Database Tables
    |----------------------------------------------------------------------
    */
    'users_table'  => 'users',
    'token_table'  => 'users_access_tokens',

    /*
    |----------------------------------------------------------------------
    | Token Table Schema (used by ensureTokenTable auto-migration)
    |----------------------------------------------------------------------
    | Column name mappings for the personal-access-token table.
    | Change these if your token table uses different column names.
    */
    'token_columns' => [
        'id'           => 'id',
        'user_id'      => 'user_id',
        'name'         => 'name',
        'token'        => 'token',
        'abilities'    => 'abilities',
        'expires_at'   => 'expires_at',
        'last_used_at' => 'last_used_at',
        'created_at'   => 'created_at',
        'updated_at'   => 'updated_at',
    ],

    /*
    |----------------------------------------------------------------------
    | Users Table Columns (used by sessionUser / socialite queries)
    |----------------------------------------------------------------------
    */
    'user_columns' => [
        'id'                  => 'id',
        'name'                => 'name',
        'preferred_name'      => 'user_preferred_name',
        'email'               => 'email',
        'username'            => 'username',
        'password'            => 'password',
        'digest_ha1'          => 'digest_ha1',
        'social_provider'     => 'social_provider',
        'social_provider_id'  => 'social_provider_id',
    ],

    'jwt' => [
        'enabled' => false,
        'algo' => 'HS256',
        // Prefer env var APP_KEY in production. Keep empty here by default.
        'secret' => '',
        'leeway' => 60,
        'user_id_claim' => 'sub',
    ],

    'api_key' => [
        'enabled' => false,
        'header' => 'X-API-KEY',
        'query_param' => 'api_key',
        // Keep false in production to avoid leaking keys via URL logs/history.
        'allow_query_param' => false,
        'columns' => [
            'id' => 'id',
            'user_id' => 'user_id',
            'name' => 'name',
            'api_key' => 'api_key',
            'abilities' => 'abilities',
            'is_active' => 'is_active',
            'expires_at' => 'expires_at',
            'last_used_at' => 'last_used_at',
            'created_at' => 'created_at',
            'updated_at' => 'updated_at',
        ],
    ],

    'basic' => [
        'enabled' => false,
        'realm' => 'MythPHP',
        'identifier_columns' => ['username', 'email'],
    ],

    'digest' => [
        'enabled' => false,
        'realm' => 'MythPHP API',
        'qop' => 'auth',
        // Prefer env var APP_KEY fallback by leaving this blank.
        'nonce_secret' => '',
        'nonce_ttl' => 300,
        // Reject nonce timestamps too far in the future.
        'nonce_future_skew' => 30,
        'username_column' => 'username',
        'ha1_column' => 'digest_ha1',
    ],

    /*
    |----------------------------------------------------------------------
    | Socialite / OAuth
    |----------------------------------------------------------------------
    | Toggle social login support. When disabled, socialite() returns 403.
    */
    'socialite_enabled' => true,

];
