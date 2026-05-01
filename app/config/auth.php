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
    // Supported values: session, token, jwt, api_key, oauth, oauth2, basic, digest
    'methods' => env_list('AUTH_METHODS', ['session']),

    // Default methods for `auth.api` middleware (RequireApiToken).
    // Keep token-only by default, then opt into others (jwt, api_key, oauth2, basic, digest) as needed.
    'api_methods' => env_list('AUTH_API_METHODS', []),

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

    // Session hijack hardening (fingerprint current client per authenticated session).
    'session_security' => [
        'enabled' => (bool) env('AUTH_SESSION_SECURITY_ENABLED', true),
        'bind_user_agent' => (bool) env('AUTH_SESSION_BIND_USER_AGENT', true),
        // strict=full UA hash, normalized=browser+major+platform+device, family=browser+major.
        'user_agent_mode' => (string) env('AUTH_SESSION_USER_AGENT_MODE', 'strict'),
        // Keep false for environments behind rotating proxies/load balancers.
        'bind_ip' => (bool) env('AUTH_SESSION_BIND_IP', false),
        'fingerprint_key' => (string) env('AUTH_SESSION_FINGERPRINT_KEY', '_auth_fp'),
        // Enable verbose unauthorized diagnostics in logger.log.
        'debug_log_enabled' => (bool) env('AUTH_DEBUG_LOG_ENABLED', false),
    ],

    // Session/device concurrency policy.
    'session_concurrency' => [
        'enabled' => (bool) env('AUTH_SESSION_CONCURRENCY_ENABLED', false),
        // 1 = single-device login, 0 = unlimited.
        'max_devices' => (int) env('AUTH_SESSION_MAX_DEVICES', 0),
        // When over limit: kick out oldest sessions.
        'invalidate_oldest' => (bool) env('AUTH_SESSION_INVALIDATE_OLDEST', true),
        // If true, reject new login when limit reached (instead of kicking oldest).
        'deny_new_login_when_limit_reached' => (bool) env('AUTH_SESSION_DENY_WHEN_LIMIT', false),
        'ttl' => (int) env('AUTH_SESSION_CONCURRENCY_TTL', 2592000),
        'enforce_on_check' => (bool) env('AUTH_SESSION_ENFORCE_ON_CHECK', true),
        'fail_open_if_cache_unavailable' => (bool) env('AUTH_SESSION_FAIL_OPEN', true),
        'cache_key_prefix' => (string) env('AUTH_SESSION_CACHE_PREFIX', 'auth_sessions_'),
        'store_fingerprint' => (bool) env('AUTH_SESSION_STORE_FINGERPRINT', true),
    ],

    // Credential login hardening policy (rate limiting + lockout + audit logs).
    'systems_login_policy' => [
        'enabled' => (bool) env('AUTH_LOGIN_POLICY_ENABLED', true),
        'max_attempts' => (int) env('AUTH_LOGIN_POLICY_MAX_ATTEMPTS', 5),
        'decay_seconds' => (int) env('AUTH_LOGIN_POLICY_DECAY_SECONDS', 600),
        'lockout_seconds' => (int) env('AUTH_LOGIN_POLICY_LOCKOUT_SECONDS', 900),
        'ban_enabled' => (bool) env('AUTH_LOGIN_POLICY_BAN_ENABLED', false),
        'ban_after_failures' => (int) env('AUTH_LOGIN_POLICY_BAN_AFTER_FAILURES', 5),
        'ban_user_status' => (int) env('AUTH_LOGIN_POLICY_BAN_USER_STATUS', 2),
        'track_by_identifier' => (bool) env('AUTH_LOGIN_POLICY_TRACK_IDENTIFIER', true),
        'track_by_ip' => (bool) env('AUTH_LOGIN_POLICY_TRACK_IP', true),
        'identifier_fields' => env_list('AUTH_LOGIN_POLICY_IDENTIFIER_FIELDS', ['email', 'username']),
        'cache_key_prefix' => (string) env('AUTH_LOGIN_POLICY_CACHE_PREFIX', 'auth_login_policy_'),
        'fail_open_if_cache_unavailable' => (bool) env('AUTH_LOGIN_POLICY_FAIL_OPEN', true),
        'record_attempts' => (bool) env('AUTH_LOGIN_POLICY_RECORD_ATTEMPTS', true),
        'record_history' => (bool) env('AUTH_LOGIN_POLICY_RECORD_HISTORY', true),
        'attempts_table' => 'system_login_attempt',
        'history_table' => 'system_login_history',
        'attempts_columns' => [
            'user_id' => 'user_id',
            'identifier' => 'identifier',
            'ip_address' => 'ip_address',
            'time' => 'time',
            'user_agent' => 'user_agent',
            'created_at' => 'created_at',
            'updated_at' => 'updated_at',
        ],
        'history_columns' => [
            'user_id' => 'user_id',
            'ip_address' => 'ip_address',
            'login_type' => 'login_type',
            'operating_system' => 'operating_system',
            'browsers' => 'browsers',
            'time' => 'time',
            'user_agent' => 'user_agent',
            'created_at' => 'created_at',
            'updated_at' => 'updated_at',
        ],
        // 1 means active in default users migration.
        'enforce_user_status' => (bool) env('AUTH_LOGIN_POLICY_ENFORCE_USER_STATUS', true),
        'user_status_column' => 'user_status',
        'allowed_user_status' => array_map('intval', env_list('AUTH_LOGIN_POLICY_ALLOWED_STATUS', ['1'])),
        'password_rotation' => [
            'enabled' => (bool) env('AUTH_PASSWORD_ROTATION_ENABLED', false),
            'max_age_days' => (int) env('AUTH_PASSWORD_MAX_AGE_DAYS', 90),
            'password_changed_at_column' => (string) env('AUTH_PASSWORD_CHANGED_AT_COLUMN', 'password_changed_at'),
            'force_reset_column' => (string) env('AUTH_PASSWORD_FORCE_RESET_COLUMN', 'force_password_change'),
            'require_password_changed_at' => (bool) env('AUTH_PASSWORD_REQUIRE_CHANGED_AT', false),
        ],
        'password_hashing' => [
            'enabled' => (bool) env('AUTH_PASSWORD_HASHING_ENABLED', true),
            'algorithm' => (string) env('AUTH_PASSWORD_HASHING_ALGORITHM', 'default'),
            'bcrypt_rounds' => (int) env('AUTH_PASSWORD_BCRYPT_ROUNDS', 12),
            'argon_memory_cost' => (int) env('AUTH_PASSWORD_ARGON_MEMORY_COST', PASSWORD_ARGON2_DEFAULT_MEMORY_COST),
            'argon_time_cost' => (int) env('AUTH_PASSWORD_ARGON_TIME_COST', PASSWORD_ARGON2_DEFAULT_TIME_COST),
            'argon_threads' => (int) env('AUTH_PASSWORD_ARGON_THREADS', PASSWORD_ARGON2_DEFAULT_THREADS),
        ],
        'audit_logging' => [
            'enabled' => (bool) env('AUTH_LOGIN_POLICY_AUDIT_ENABLED', true),
            'level' => (string) env('AUTH_LOGIN_POLICY_AUDIT_LEVEL', 'INFO'),
            'include_user_agent' => (bool) env('AUTH_LOGIN_POLICY_AUDIT_INCLUDE_USER_AGENT', true),
        ],
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
        'status'              => 'user_status',
        'password_changed_at' => 'password_changed_at',
        'force_password_change' => 'force_password_change',
        'digest_ha1'          => 'digest_ha1',
        'social_provider'     => 'social_provider',
        'social_provider_id'  => 'social_provider_id',
    ],

    'jwt' => [
        'enabled' => (bool) env('AUTH_JWT_ENABLED', false),
        'algo' => (string) env('AUTH_JWT_ALGO', 'HS256'),
        // Prefer AUTH_JWT_SECRET, fallback to APP_KEY in runtime component.
        'secret' => (string) env('AUTH_JWT_SECRET', ''),
        'leeway' => (int) env('AUTH_JWT_LEEWAY', 60),
        'user_id_claim' => (string) env('AUTH_JWT_USER_ID_CLAIM', 'sub'),
    ],

    'api_key' => [
        'enabled' => (bool) env('AUTH_API_KEY_ENABLED', false),
        'header' => (string) env('AUTH_API_KEY_HEADER', 'X-API-KEY'),
        'query_param' => (string) env('AUTH_API_KEY_QUERY_PARAM', 'api_key'),
        // Keep false in production to avoid leaking keys via URL logs/history.
        'allow_query_param' => (bool) env('AUTH_API_KEY_ALLOW_QUERY_PARAM', false),
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

    'oauth2' => [
        'enabled' => (bool) env('AUTH_OAUTH2_ENABLED', false),
        // Keep true to store hashed token at rest.
        'hash_tokens' => (bool) env('AUTH_OAUTH2_HASH_TOKENS', true),
        'header_prefix' => (string) env('AUTH_OAUTH2_HEADER_PREFIX', 'Bearer'),
        'columns' => [
            'id' => 'id',
            'user_id' => 'user_id',
            'name' => 'name',
            'token' => 'token',
            'scopes' => 'scopes',
            'revoked' => 'revoked',
            'expires_at' => 'expires_at',
            'last_used_at' => 'last_used_at',
            'created_at' => 'created_at',
            'updated_at' => 'updated_at',
        ],
    ],

    'basic' => [
        'enabled' => (bool) env('AUTH_BASIC_ENABLED', false),
        'realm' => (string) env('AUTH_BASIC_REALM', 'MythPHP'),
        'identifier_columns' => env_list('AUTH_BASIC_IDENTIFIER_COLUMNS', ['username', 'email']),
    ],

    'digest' => [
        'enabled' => (bool) env('AUTH_DIGEST_ENABLED', false),
        'realm' => (string) env('AUTH_DIGEST_REALM', 'MythPHP API'),
        'qop' => (string) env('AUTH_DIGEST_QOP', 'auth'),
        // Prefer env var APP_KEY fallback by leaving this blank.
        'nonce_secret' => (string) env('AUTH_DIGEST_NONCE_SECRET', ''),
        'nonce_ttl' => (int) env('AUTH_DIGEST_NONCE_TTL', 300),
        // Reject nonce timestamps too far in the future.
        'nonce_future_skew' => (int) env('AUTH_DIGEST_NONCE_FUTURE_SKEW', 30),
        'username_column' => (string) env('AUTH_DIGEST_USERNAME_COLUMN', 'username'),
        'ha1_column' => (string) env('AUTH_DIGEST_HA1_COLUMN', 'digest_ha1'),
    ],

    /*
    |----------------------------------------------------------------------
    | Socialite / OAuth
    |----------------------------------------------------------------------
    | Toggle social login support. When disabled, socialite() returns 403.
    */
    'socialite_enabled' => (bool) env('AUTH_SOCIALITE_ENABLED', true),

    /*
    |----------------------------------------------------------------------
    | RBAC / ACL
    |----------------------------------------------------------------------
    | Supports multiple roles per user via user_profile table.
    | Permissions are resolved from role -> system_permission -> system_abilities.
    */
    'rbac' => [
        'enabled' => true,
        'only_active_profiles' => true,
        'only_active_roles' => true,
        'tables' => [
            'user_profile' => 'user_profile',
            'roles' => 'master_roles',
            'permissions' => 'system_permission',
            'abilities' => 'system_abilities',
        ],
        'user_profile_columns' => [
            'id' => 'id',
            'user_id' => 'user_id',
            'role_id' => 'role_id',
            'is_main' => 'is_main',
            'status' => 'profile_status',
        ],
        'role_columns' => [
            'id' => 'id',
            'name' => 'role_name',
            'rank' => 'role_rank',
            'status' => 'role_status',
        ],
        'permission_columns' => [
            'id' => 'id',
            'role_id' => 'role_id',
            'ability_id' => 'abilities_id',
        ],
        'ability_columns' => [
            'id' => 'id',
            'name' => 'abilities_name',
            'slug' => 'abilities_slug',
        ],
    ],

];
