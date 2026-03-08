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

    'session_keys' => [
        'userFullName'  => 'userFullName',
        'userNickname'  => 'userNickname',
        'userEmail'     => 'userEmail',
        'roleID'        => 'roleID',
        'roleRank'      => 'roleRank',
        'roleName'      => 'roleName',
        'permissions'   => 'permissions',
        'userAvatar'    => 'userAvatar',
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
        'social_provider'     => 'social_provider',
        'social_provider_id'  => 'social_provider_id',
    ],

    /*
    |----------------------------------------------------------------------
    | Socialite / OAuth
    |----------------------------------------------------------------------
    | Toggle social login support. When disabled, socialite() returns 403.
    */
    'socialite_enabled' => true,

];
