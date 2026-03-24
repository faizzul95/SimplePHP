<?php

namespace Components;

class Auth
{
    private array $config;
    private array $lastAttemptStatus = [];
    private ?array $tokenUserCache = null;
    private ?array $sessionUserCache = null;
    private ?array $jwtUserCache = null;
    private ?array $apiKeyUserCache = null;
    private ?array $basicUserCache = null;
    private ?array $digestUserCache = null;
    private ?array $oauthUserCache = null;
    private ?array $oauth2UserCache = null;
    private array $digestNcMemory = [];
    private array $schemaCheckCache = [];

    /**
     * Fallback defaults — override via app/config/auth.php
     */
    private const DEFAULTS = [
        'session_flag'      => 'isLoggedIn',
        'session_user_id'   => 'userID',
        'methods'           => ['session'],
        'api_methods'       => [],
        'users_table'       => 'users',
        'token_table'       => 'users_access_tokens',
        'api_key_table'     => 'users_api_keys',
        'oauth2_table'      => 'oauth2_access_tokens',
        'socialite_enabled' => true,
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
        'session_security' => [
            'enabled' => true,
            'bind_user_agent' => true,
            'user_agent_mode' => 'strict',
            'bind_ip' => false,
            'fingerprint_key' => '_auth_fp',
            'debug_log_enabled' => false,
        ],
        'session_concurrency' => [
            'enabled' => false,
            // 1 = single-device login, 0 = unlimited devices.
            'max_devices' => 0,
            // If true, oldest sessions are invalidated when exceeding max_devices.
            'invalidate_oldest' => true,
            // If false and limit exceeded, login() returns false.
            'deny_new_login_when_limit_reached' => false,
            // In seconds. Also used to prune stale session records.
            'ttl' => 2592000,
            // Validate active session membership on each checkSession call.
            'enforce_on_check' => true,
            // Keep app available if cache backend is unavailable.
            'fail_open_if_cache_unavailable' => true,
            'cache_key_prefix' => 'auth_sessions_',
            // Cross-check stored fingerprint in active-session registry.
            'store_fingerprint' => true,
        ],
        'systems_login_policy' => [
            'enabled' => true,
            'max_attempts' => 5,
            'decay_seconds' => 600,
            'lockout_seconds' => 900,
            'ban_enabled' => false,
            'ban_after_failures' => 5,
            'ban_user_status' => 2,
            'track_by_identifier' => true,
            'track_by_ip' => true,
            'identifier_fields' => ['email', 'username'],
            'cache_key_prefix' => 'auth_login_policy_',
            'fail_open_if_cache_unavailable' => true,
            'record_attempts' => true,
            'record_history' => true,
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
            'enforce_user_status' => true,
            'user_status_column' => 'user_status',
            'allowed_user_status' => [1],
            'password_rotation' => [
                'enabled' => false,
                'max_age_days' => 90,
                'password_changed_at_column' => 'password_changed_at',
                'force_reset_column' => 'force_password_change',
                'require_password_changed_at' => false,
            ],
        ],
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
        'user_columns' => [
            'id'                 => 'id',
            'name'               => 'name',
            'preferred_name'     => 'user_preferred_name',
            'email'              => 'email',
            'username'           => 'username',
            'password'           => 'password',
            'status'             => 'user_status',
            'password_changed_at' => 'password_changed_at',
            'force_password_change' => 'force_password_change',
            'digest_ha1'         => 'digest_ha1',
            'social_provider'    => 'social_provider',
            'social_provider_id' => 'social_provider_id',
        ],
        'jwt' => [
            'enabled' => false,
            'algo' => 'HS256',
            'secret' => '',
            'leeway' => 60,
            'user_id_claim' => 'sub',
        ],
        'api_key' => [
            'enabled' => false,
            'header' => 'X-API-KEY',
            'query_param' => 'api_key',
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
            'nonce_secret' => '',
            'nonce_ttl' => 300,
            'nonce_future_skew' => 30,
            'username_column' => 'username',
            'ha1_column' => 'digest_ha1',
        ],
        'oauth2' => [
            'enabled' => false,
            'hash_tokens' => true,
            'header_prefix' => 'Bearer',
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
        'rbac' => [
            'enabled' => true,
            'cache_session_permissions' => true,
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

    public function __construct(array $config = [])
    {
        $defaults = self::DEFAULTS;

        // Resolve token_table from api config if not explicitly set
        if (empty($config['token_table'])) {
            $apiTokenTable = \config('api.token_table');
            if (is_string($apiTokenTable) && trim($apiTokenTable) !== '') {
                $defaults['token_table'] = $apiTokenTable;
            }
        }

        // Deep-merge nested arrays to allow partial overrides
        foreach (['session_keys', 'token_columns', 'user_columns', 'session_security', 'session_concurrency', 'systems_login_policy'] as $nestedKey) {
            if (isset($config[$nestedKey]) && is_array($config[$nestedKey])) {
                $config[$nestedKey] = array_merge($defaults[$nestedKey], $config[$nestedKey]);
            }
        }

        if (isset($config['systems_login_policy']) && is_array($config['systems_login_policy'])) {
            foreach (['attempts_columns', 'history_columns', 'password_rotation'] as $policyNestedKey) {
                if (isset($config['systems_login_policy'][$policyNestedKey]) && is_array($config['systems_login_policy'][$policyNestedKey])) {
                    $config['systems_login_policy'][$policyNestedKey] = array_merge(
                        $defaults['systems_login_policy'][$policyNestedKey],
                        $config['systems_login_policy'][$policyNestedKey]
                    );
                }
            }
        }

        if (isset($config['jwt']) && is_array($config['jwt'])) {
            $config['jwt'] = array_merge($defaults['jwt'], $config['jwt']);
        }

        if (isset($config['api_key']) && is_array($config['api_key'])) {
            if (isset($config['api_key']['columns']) && is_array($config['api_key']['columns'])) {
                $config['api_key']['columns'] = array_merge($defaults['api_key']['columns'], $config['api_key']['columns']);
            }
            $config['api_key'] = array_merge($defaults['api_key'], $config['api_key']);
        }

        if (isset($config['oauth2']) && is_array($config['oauth2'])) {
            if (isset($config['oauth2']['columns']) && is_array($config['oauth2']['columns'])) {
                $config['oauth2']['columns'] = array_merge($defaults['oauth2']['columns'], $config['oauth2']['columns']);
            }
            $config['oauth2'] = array_merge($defaults['oauth2'], $config['oauth2']);
        }

        if (isset($config['rbac']) && is_array($config['rbac'])) {
            foreach (['tables', 'user_profile_columns', 'role_columns', 'permission_columns', 'ability_columns'] as $rbacNestedKey) {
                if (isset($config['rbac'][$rbacNestedKey]) && is_array($config['rbac'][$rbacNestedKey])) {
                    $config['rbac'][$rbacNestedKey] = array_merge($defaults['rbac'][$rbacNestedKey], $config['rbac'][$rbacNestedKey]);
                }
            }
            $config['rbac'] = array_merge($defaults['rbac'], $config['rbac']);
        }

        if (isset($config['basic']) && is_array($config['basic'])) {
            $config['basic'] = array_merge($defaults['basic'], $config['basic']);
        }

        if (isset($config['digest']) && is_array($config['digest'])) {
            $config['digest'] = array_merge($defaults['digest'], $config['digest']);
        }

        $this->config = array_merge($defaults, $config);
    }

    // ─── Authentication State ────────────────────────────────

    public function check(array|string|null $methods = null): bool
    {
        foreach ($this->normalizeMethods($methods) as $method) {
            if ($this->checkMethod($method)) {
                return true;
            }
        }

        return false;
    }

    public function guest(array|string|null $methods = null): bool
    {
        return !$this->check($methods);
    }

    public function via(array|string|null $methods = null): ?string
    {
        foreach ($this->normalizeMethods($methods) as $method) {
            if ($this->checkMethod($method)) {
                return $method;
            }
        }

        return null;
    }

    public function checkSession(): bool
    {
        if (!(bool) \getSession($this->config['session_flag']) || empty(\getSession($this->config['session_user_id']))) {
            return false;
        }

        if (!$this->validateSessionFingerprint()) {
            return false;
        }

        $userId = (int) \getSession($this->config['session_user_id']);
        if (!$this->validateSessionConcurrency($userId)) {
            return false;
        }

        return $this->validateSessionUserAccess($userId);
    }

    public function checkToken(): bool
    {
        return !empty($this->tokenUser());
    }

    public function id(array|string|null $methods = null): ?int
    {
        $user = $this->user($methods);
        if (!empty($user['id'])) {
            return (int) $user['id'];
        }

        return null;
    }

    public function user(array|string|null $methods = null): ?array
    {
        foreach ($this->normalizeMethods($methods) as $method) {
            $user = $this->userByMethod($method);
            if (!empty($user)) {
                return $user;
            }
        }

        return null;
    }

    public function checkAny(array|string $methods): bool
    {
        return $this->check($methods);
    }

    // ─── Session Authentication ──────────────────────────────

    /**
     * Attempt to authenticate a user with credentials.
     * Returns the user row on success, false on failure.
     *
     * @param array $credentials ['email' => '...', 'password' => '...'] or ['username' => '...', 'password' => '...']
     * @return array|false  User row on success, false on failure
     */
    public function attempt(array $credentials): array|false
    {
        $this->resetLastAttemptStatus();

        $password = $credentials['password'] ?? '';
        unset($credentials['password']);

        if (empty($credentials) || empty($password)) {
            $this->setLastAttemptStatus('invalid_credentials', 'Invalid username or password', 400);
            return false;
        }

        if (!$this->canAttemptWithLoginPolicy($credentials)) {
            return false;
        }

        $uc = $this->config['user_columns'];
        $query = \db()->table($this->safeTable($this->config['users_table']));

        $allowedCredentialMap = [
            'email' => (string) ($uc['email'] ?? 'email'),
            'username' => (string) ($uc['username'] ?? 'username'),
        ];

        $appliedCredentialCount = 0;

        foreach ($credentials as $column => $value) {
            $column = strtolower(trim((string) $column));
            if (!isset($allowedCredentialMap[$column])) {
                continue;
            }

            $safeColumn = $this->safeColumn($allowedCredentialMap[$column], 'email');
            $query->where($safeColumn, $value);
            $appliedCredentialCount++;
        }

        if ($appliedCredentialCount < 1) {
            $this->setLastAttemptStatus('invalid_credentials', 'Invalid username or password', 400);
            return false;
        }

        $user = $query->safeOutput()->fetch();

        $passwordCol = $uc['password'] ?? 'password';
        if (empty($user)) {
            $this->registerLoginFailure($credentials);
            $this->setLastAttemptStatus('invalid_credentials', 'Invalid username or password', 401);
            return false;
        }

        $policy = (array) ($this->config['systems_login_policy'] ?? []);
        $statusColumn = $this->safeColumn((string) ($policy['user_status_column'] ?? ($uc['status'] ?? 'user_status')), 'user_status');
        if (($policy['enforce_user_status'] ?? true) === true && array_key_exists($statusColumn, $user)) {
            $allowed = array_map('intval', (array) ($policy['allowed_user_status'] ?? [1]));
            $currentStatus = (int) ($user[$statusColumn] ?? 0);
            if (!in_array($currentStatus, $allowed, true)) {
                $this->registerLoginFailure($credentials, (int) ($user[$uc['id'] ?? 'id'] ?? 0));
                $this->setLastAttemptStatus('account_status_restricted', 'Your account is not allowed to sign in.', 403, [
                    'user_status' => $currentStatus,
                ]);
                return false;
            }
        }

        if (!password_verify((string) $password, (string) ($user[$passwordCol] ?? ''))) {
            $this->registerLoginFailure($credentials, (int) ($user[$uc['id'] ?? 'id'] ?? 0));
            $this->setLastAttemptStatus('invalid_credentials', 'Invalid username or password', 401);
            return false;
        }

        $userId = (int) ($user[$uc['id'] ?? 'id'] ?? 0);
        $this->clearLoginFailures($credentials, $userId);

        if (!$this->passesPasswordRotationPolicy($user)) {
            return false;
        }

        // Strip the password hash from the returned user — callers should never need it
        unset($user[$passwordCol]);

        return $user;
    }

    public function lastAttemptStatus(): array
    {
        return $this->lastAttemptStatus;
    }

    /**
     * Log a user in by ID. Sets session variables.
     * 
     * @param int   $userId       The user ID to log in
     * @param array $sessionData  Additional session data to store (merged with defaults)
     * @return bool
     */
    public function login(int $userId, array $sessionData = []): bool
    {
        if ($userId < 1) {
            return false;
        }

        // Ensure session exists before rotation; rotate ID on every successful login.
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        // Build base session data with configurable keys
        $baseSession = [
            $this->config['session_user_id'] => $userId,
            $this->config['session_flag'] => true,
        ];

        // Merge user-provided session data
        foreach ($sessionData as $key => $value) {
            $baseSession[$key] = $value;
        }

        $baseSession[(string) ($this->config['session_security']['fingerprint_key'] ?? '_auth_fp')] = $this->clientFingerprint();

        \startSession($baseSession);

        if (!$this->registerSessionForUser($userId)) {
            $this->logout(true);
            return false;
        }

        $this->recordLoginHistory($userId, $this->resolveLoginType($sessionData));

        // Clear cache so subsequent calls fetch fresh data
        $this->sessionUserCache = null;

        return true;
    }

    /**
     * Log a user in by their ID (alias for login).
     *
     * @param int   $userId       The user ID
     * @param array $sessionData  Additional session data
     * @return bool
     */
    public function loginUsingId(int $userId, array $sessionData = []): bool
    {
        return $this->login($userId, $sessionData);
    }

    public function sessionUser(): ?array
    {
        if (!$this->checkSession()) {
            return null;
        }

        if ($this->sessionUserCache !== null) {
            return $this->sessionUserCache;
        }

        $userId = (int) \getSession($this->config['session_user_id']);
        if ($userId < 1) {
            return null;
        }

        $uc = $this->config['user_columns'];
        $selectCols = implode(', ', [
            $uc['id'], $uc['name'], $uc['preferred_name'], $uc['email'], $uc['username'], $uc['status']
        ]);

        $user = \db()->table($this->safeTable($this->config['users_table']))
            ->select($selectCols)
            ->where($uc['id'], $userId)
            ->safeOutput()
            ->fetch();

        if (empty($user)) {
            $this->logout(true);
            return null;
        }

        if (!$this->isUserStatusAllowed($user)) {
            $this->logout(true);
            return null;
        }

        $keys = $this->config['session_keys'];

        $this->sessionUserCache = array_merge($user, [
            'auth_type'   => 'session',
            'role_id'     => \getSession($keys['roleID']),
            'role_rank'   => \getSession($keys['roleRank']),
            'role_name'   => \getSession($keys['roleName']),
            'permissions' => \getSession($keys['permissions']) ?? [],
        ]);

        return $this->sessionUserCache;
    }

    // ─── Token Authentication ────────────────────────────────

    public function tokenUser(): ?array
    {
        if ($this->tokenUserCache !== null) {
            return $this->tokenUserCache;
        }

        $plainToken = $this->bearerToken();
        if (empty($plainToken)) {
            return null;
        }

        $tc = $this->config['token_columns'];
        $uc = $this->config['user_columns'];
        $tokenTable = $this->safeTable($this->config['token_table']);
        $hashedToken = hash('sha256', $plainToken);

        $tokenIdColumn = $this->safeColumn((string) ($tc['id'] ?? 'id'));
        $tokenUserIdColumn = $this->safeColumn((string) ($tc['user_id'] ?? 'user_id'));
        $tokenNameColumn = $this->safeColumn((string) ($tc['name'] ?? 'name'));
        $tokenAbilitiesColumn = $this->safeColumn((string) ($tc['abilities'] ?? 'abilities'));
        $tokenExpiresAtColumn = $this->safeColumn((string) ($tc['expires_at'] ?? 'expires_at'));
        $tokenTokenColumn = $this->safeColumn((string) ($tc['token'] ?? 'token'));
        $tokenLastUsedAtColumn = $this->safeColumn((string) ($tc['last_used_at'] ?? 'last_used_at'));
        $tokenUpdatedAtColumn = $this->safeColumn((string) ($tc['updated_at'] ?? 'updated_at'));

        $selectCols = implode(', ', [
            $tokenIdColumn, $tokenUserIdColumn, $tokenNameColumn, $tokenAbilitiesColumn, $tokenExpiresAtColumn
        ]);

        $tokenRecord = \db()->table($tokenTable)
            ->select($selectCols)
            ->where($tokenTokenColumn, $hashedToken)
            ->safeOutput()
            ->fetch();

        if (empty($tokenRecord)) {
            return null;
        }

        if (!$this->isFutureOrNull($tokenRecord[$tokenExpiresAtColumn] ?? null)) {
            return null;
        }

        // Update last_used_at timestamp
        \db()->table($tokenTable)
            ->where($tokenIdColumn, $tokenRecord[$tokenIdColumn])
            ->update([
                $tokenLastUsedAtColumn => \timestamp(),
                $tokenUpdatedAtColumn   => \timestamp(),
            ]);

        $userSelectCols = implode(', ', [
            $uc['id'], $uc['name'], $uc['preferred_name'], $uc['email'], $uc['username'], $uc['status']
        ]);

        $user = \db()->table($this->safeTable($this->config['users_table']))
            ->select($userSelectCols)
            ->where($uc['id'], (int) $tokenRecord[$tokenUserIdColumn])
            ->safeOutput()
            ->fetch();

        if (empty($user) || !$this->isUserStatusAllowed($user)) {
            return null;
        }

        $abilities = json_decode($tokenRecord[$tokenAbilitiesColumn] ?? '[]', true);

        $this->tokenUserCache = array_merge($user, [
            'auth_type'  => 'token',
            'token_id'   => (int) $tokenRecord[$tokenIdColumn],
            'token_name' => $tokenRecord[$tokenNameColumn],
            'abilities'  => is_array($abilities) ? $abilities : [],
            'expires_at' => $tokenRecord[$tokenExpiresAtColumn],
        ]);

        return $this->tokenUserCache;
    }

    public function oauthUser(): ?array
    {
        if ($this->oauthUserCache !== null) {
            return $this->oauthUserCache;
        }

        if (!$this->checkSession()) {
            return null;
        }

        $user = $this->sessionUser();
        if (empty($user)) {
            return null;
        }

        $oauthProviderKey = $this->config['session_keys']['oauthProvider'] ?? 'oauth_provider';
        $provider = (string) \getSession($oauthProviderKey);
        if ($provider === '') {
            return null;
        }

        $this->oauthUserCache = array_merge($user, [
            'auth_type' => 'oauth',
            'oauth_provider' => $provider,
        ]);

        return $this->oauthUserCache;
    }

    public function checkOAuth(): bool
    {
        return !empty($this->oauthUser());
    }

    public function oauth2User(): ?array
    {
        if ($this->oauth2UserCache !== null) {
            return $this->oauth2UserCache;
        }

        $oauth2Config = (array) ($this->config['oauth2'] ?? []);
        if (($oauth2Config['enabled'] ?? false) !== true) {
            return null;
        }

        $plainToken = $this->bearerToken();
        if ($plainToken === null) {
            return null;
        }

        $columns = (array) ($oauth2Config['columns'] ?? []);
        if (empty($columns)) {
            return null;
        }

        $oauth2IdColumn = $this->safeColumn((string) ($columns['id'] ?? 'id'));
        $oauth2UserIdColumn = $this->safeColumn((string) ($columns['user_id'] ?? 'user_id'));
        $oauth2NameColumn = $this->safeColumn((string) ($columns['name'] ?? 'name'));
        $oauth2TokenColumn = $this->safeColumn((string) ($columns['token'] ?? 'token'));
        $oauth2ScopesColumn = $this->safeColumn((string) ($columns['scopes'] ?? 'scopes'));
        $oauth2RevokedColumn = $this->safeColumn((string) ($columns['revoked'] ?? 'revoked'));
        $oauth2ExpiresAtColumn = $this->safeColumn((string) ($columns['expires_at'] ?? 'expires_at'));
        $oauth2LastUsedAtColumn = $this->safeColumn((string) ($columns['last_used_at'] ?? 'last_used_at'));
        $oauth2UpdatedAtColumn = $this->safeColumn((string) ($columns['updated_at'] ?? 'updated_at'));

        $table = $this->safeTable((string) ($this->config['oauth2_table'] ?? 'oauth2_access_tokens'));
        $hashTokens = ($oauth2Config['hash_tokens'] ?? true) === true;
        $tokenLookup = $hashTokens ? hash('sha256', $plainToken) : $plainToken;

        $selectCols = implode(', ', [
            $oauth2IdColumn,
            $oauth2UserIdColumn,
            $oauth2NameColumn,
            $oauth2ScopesColumn,
            $oauth2RevokedColumn,
            $oauth2ExpiresAtColumn,
        ]);

        $tokenRecord = \db()->table($table)
            ->select($selectCols)
            ->where($oauth2TokenColumn, $tokenLookup)
            ->safeOutput()
            ->fetch();

        if (empty($tokenRecord)) {
            return null;
        }

        if (!empty($tokenRecord[$oauth2RevokedColumn])) {
            return null;
        }

        if (!$this->isFutureOrNull($tokenRecord[$oauth2ExpiresAtColumn] ?? null)) {
            return null;
        }

        \db()->table($table)
            ->where($oauth2IdColumn, $tokenRecord[$oauth2IdColumn])
            ->update([
                $oauth2LastUsedAtColumn => \timestamp(),
                $oauth2UpdatedAtColumn => \timestamp(),
            ]);

        $uc = $this->config['user_columns'];
        $userSelectCols = implode(', ', [
            $uc['id'], $uc['name'], $uc['preferred_name'], $uc['email'], $uc['username'], $uc['status']
        ]);

        $user = \db()->table($this->safeTable($this->config['users_table']))
            ->select($userSelectCols)
            ->where($uc['id'], (int) $tokenRecord[$oauth2UserIdColumn])
            ->safeOutput()
            ->fetch();

        if (empty($user) || !$this->isUserStatusAllowed($user)) {
            return null;
        }

        $scopes = $this->toScopeList($tokenRecord[$oauth2ScopesColumn] ?? []);

        $this->oauth2UserCache = array_merge($user, [
            'auth_type' => 'oauth2',
            'oauth2_token_id' => $tokenRecord[$oauth2IdColumn] ?? null,
            'oauth2_token_name' => $tokenRecord[$oauth2NameColumn] ?? null,
            'oauth2_scopes' => $scopes,
            'abilities' => $scopes,
            'expires_at' => $tokenRecord[$oauth2ExpiresAtColumn] ?? null,
        ]);

        return $this->oauth2UserCache;
    }

    public function checkOAuth2(): bool
    {
        return !empty($this->oauth2User());
    }

    public function jwtUser(): ?array
    {
        if ($this->jwtUserCache !== null) {
            return $this->jwtUserCache;
        }

        $jwtConfig = (array) ($this->config['jwt'] ?? []);
        if (($jwtConfig['enabled'] ?? false) !== true) {
            return null;
        }

        $bearer = $this->bearerToken();
        if ($bearer === null) {
            return null;
        }

        $payload = $this->decodeJwt($bearer);
        if ($payload === null) {
            return null;
        }

        $claimName = (string) ($jwtConfig['user_id_claim'] ?? 'sub');
        $userId = (int) ($payload[$claimName] ?? 0);
        if ($userId < 1) {
            return null;
        }

        $uc = $this->config['user_columns'];
        $userSelectCols = implode(', ', [
            $uc['id'], $uc['name'], $uc['preferred_name'], $uc['email'], $uc['username'], $uc['status']
        ]);

        $user = \db()->table($this->safeTable($this->config['users_table']))
            ->select($userSelectCols)
            ->where($uc['id'], $userId)
            ->safeOutput()
            ->fetch();

        if (empty($user) || !$this->isUserStatusAllowed($user)) {
            return null;
        }

        $this->jwtUserCache = array_merge($user, [
            'auth_type' => 'jwt',
            'jwt_claims' => $payload,
        ]);

        return $this->jwtUserCache;
    }

    public function checkJwt(): bool
    {
        return !empty($this->jwtUser());
    }

    public function apiKeyUser(): ?array
    {
        if ($this->apiKeyUserCache !== null) {
            return $this->apiKeyUserCache;
        }

        $apiKeyConfig = (array) ($this->config['api_key'] ?? []);
        if (($apiKeyConfig['enabled'] ?? false) !== true) {
            return null;
        }

        $apiKey = $this->extractApiKey();
        if ($apiKey === null) {
            return null;
        }

        $columns = (array) ($apiKeyConfig['columns'] ?? []);
        if (empty($columns)) {
            return null;
        }

        $apiKeyIdColumn = $this->safeColumn((string) ($columns['id'] ?? 'id'));
        $apiKeyUserIdColumn = $this->safeColumn((string) ($columns['user_id'] ?? 'user_id'));
        $apiKeyNameColumn = $this->safeColumn((string) ($columns['name'] ?? 'name'));
        $apiKeyValueColumn = $this->safeColumn((string) ($columns['api_key'] ?? 'api_key'));
        $apiKeyAbilitiesColumn = $this->safeColumn((string) ($columns['abilities'] ?? 'abilities'));
        $apiKeyIsActiveColumn = $this->safeColumn((string) ($columns['is_active'] ?? 'is_active'));
        $apiKeyExpiresAtColumn = $this->safeColumn((string) ($columns['expires_at'] ?? 'expires_at'));
        $apiKeyLastUsedAtColumn = $this->safeColumn((string) ($columns['last_used_at'] ?? 'last_used_at'));
        $apiKeyUpdatedAtColumn = $this->safeColumn((string) ($columns['updated_at'] ?? 'updated_at'));

        $table = $this->safeTable((string) ($this->config['api_key_table'] ?? 'users_api_keys'));
        $hashedApiKey = hash('sha256', $apiKey);

        $selectCols = implode(', ', [
            $apiKeyIdColumn,
            $apiKeyUserIdColumn,
            $apiKeyNameColumn,
            $apiKeyAbilitiesColumn,
            $apiKeyExpiresAtColumn,
        ]);

        $keyRecord = \db()->table($table)
            ->select($selectCols)
            ->where($apiKeyValueColumn, $hashedApiKey)
            ->where($apiKeyIsActiveColumn, 1)
            ->safeOutput()
            ->fetch();

        if (empty($keyRecord)) {
            return null;
        }

        if (!$this->isFutureOrNull($keyRecord[$apiKeyExpiresAtColumn] ?? null)) {
            return null;
        }

        \db()->table($table)
            ->where($apiKeyIdColumn, $keyRecord[$apiKeyIdColumn])
            ->update([
                $apiKeyLastUsedAtColumn => \timestamp(),
                $apiKeyUpdatedAtColumn => \timestamp(),
            ]);

        $uc = $this->config['user_columns'];
        $userSelectCols = implode(', ', [
            $uc['id'], $uc['name'], $uc['preferred_name'], $uc['email'], $uc['username'], $uc['status']
        ]);

        $user = \db()->table($this->safeTable($this->config['users_table']))
            ->select($userSelectCols)
            ->where($uc['id'], (int) $keyRecord[$apiKeyUserIdColumn])
            ->safeOutput()
            ->fetch();

        if (empty($user) || !$this->isUserStatusAllowed($user)) {
            return null;
        }

        $abilities = json_decode($keyRecord[$apiKeyAbilitiesColumn] ?? '[]', true);

        $this->apiKeyUserCache = array_merge($user, [
            'auth_type' => 'api_key',
            'api_key_id' => (int) $keyRecord[$apiKeyIdColumn],
            'api_key_name' => $keyRecord[$apiKeyNameColumn] ?? null,
            'abilities' => is_array($abilities) ? $abilities : [],
            'expires_at' => $keyRecord[$apiKeyExpiresAtColumn] ?? null,
        ]);

        return $this->apiKeyUserCache;
    }

    public function checkApiKey(): bool
    {
        return !empty($this->apiKeyUser());
    }

    public function basicUser(): ?array
    {
        if ($this->basicUserCache !== null) {
            return $this->basicUserCache;
        }

        $basicConfig = (array) ($this->config['basic'] ?? []);
        if (($basicConfig['enabled'] ?? false) !== true) {
            return null;
        }

        [$identifier, $password] = $this->extractBasicCredentials();
        if ($identifier === null || $password === null) {
            return null;
        }

        $uc = $this->config['user_columns'];
        $identifierColumns = (array) ($basicConfig['identifier_columns'] ?? ['username', 'email']);
        $passwordCol = (string) ($uc['password'] ?? 'password');
        $userIdColumn = (string) ($uc['id'] ?? 'id');
        $policyCredentials = $this->policyCredentialsForIdentifier($identifier, $identifierColumns);

        if (!$this->canAttemptWithLoginPolicy($policyCredentials)) {
            return null;
        }

        $matchedUserId = 0;

        foreach ($identifierColumns as $columnAlias) {
            $columnAlias = (string) $columnAlias;
            $column = $uc[$columnAlias] ?? $columnAlias;
            $safeColumn = preg_replace('/[^a-zA-Z0-9_]/', '', $column);
            if ($safeColumn === '') {
                continue;
            }

            $user = \db()->table($this->safeTable($this->config['users_table']))
                ->where($safeColumn, $identifier)
                ->safeOutput()
                ->fetch();

            if (empty($user)) {
                continue;
            }

            $matchedUserId = max($matchedUserId, (int) ($user[$userIdColumn] ?? 0));

            if (!password_verify($password, (string) ($user[$passwordCol] ?? ''))) {
                continue;
            }

            if (!$this->isUserStatusAllowed($user)) {
                $this->registerLoginFailure($policyCredentials, $matchedUserId);
                return null;
            }

            $this->clearLoginFailures($policyCredentials, $matchedUserId);
            unset($user[$passwordCol]);
            $this->basicUserCache = array_merge($user, ['auth_type' => 'basic']);
            return $this->basicUserCache;
        }

        $this->registerLoginFailure($policyCredentials, $matchedUserId > 0 ? $matchedUserId : null);

        return null;
    }

    public function checkBasic(): bool
    {
        return !empty($this->basicUser());
    }

    public function digestUser(): ?array
    {
        if ($this->digestUserCache !== null) {
            return $this->digestUserCache;
        }

        $digestConfig = (array) ($this->config['digest'] ?? []);
        if (($digestConfig['enabled'] ?? false) !== true) {
            return null;
        }

        $digest = $this->extractDigestCredentials();
        if (empty($digest) || empty($digest['username'])) {
            return null;
        }

        $policyCredentials = $this->policyCredentialsForIdentifier((string) $digest['username'], ['username']);
        if (!$this->canAttemptWithLoginPolicy($policyCredentials)) {
            return null;
        }

        $expectedQop = (string) (($digestConfig['qop'] ?? 'auth') ?: 'auth');
        if (!isset($digest['qop']) || strtolower((string) $digest['qop']) !== strtolower($expectedQop)) {
            return null;
        }

        $expectedRealm = (string) (($digestConfig['realm'] ?? 'MythPHP API') ?: 'MythPHP API');
        if (!isset($digest['realm']) || (string) $digest['realm'] !== $expectedRealm) {
            return null;
        }

        $expectedOpaque = md5($expectedRealm);
        if (!isset($digest['opaque']) || !hash_equals($expectedOpaque, (string) $digest['opaque'])) {
            return null;
        }

        if (!$this->isDigestNonceValid((string) ($digest['nonce'] ?? ''))) {
            return null;
        }

        if (!$this->isDigestRequestUriValid((string) ($digest['uri'] ?? ''))) {
            return null;
        }

        if (!$this->isDigestNonceCounterValid((string) $digest['username'], (string) $digest['nonce'], (string) ($digest['nc'] ?? ''))) {
            return null;
        }

        $uc = $this->config['user_columns'];
        $usernameColumn = (string) ($digestConfig['username_column'] ?? ($uc['username'] ?? 'username'));
        $ha1Column = (string) ($digestConfig['ha1_column'] ?? ($uc['digest_ha1'] ?? 'digest_ha1'));

        $safeUsernameColumn = preg_replace('/[^a-zA-Z0-9_]/', '', $usernameColumn);
        $safeHa1Column = preg_replace('/[^a-zA-Z0-9_]/', '', $ha1Column);

        $user = \db()->table($this->safeTable($this->config['users_table']))
            ->where($safeUsernameColumn, (string) $digest['username'])
            ->safeOutput()
            ->fetch();

        if (empty($user)) {
            $this->registerLoginFailure($policyCredentials);
            return null;
        }

        $userIdColumn = (string) ($uc['id'] ?? 'id');
        $userId = (int) ($user[$userIdColumn] ?? 0);

        $ha1 = (string) ($user[$safeHa1Column] ?? '');
        if ($ha1 === '') {
            $this->registerLoginFailure($policyCredentials, $userId);
            return null;
        }

        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $ha2 = md5($method . ':' . (string) $digest['uri']);
        $validResponse = md5($ha1 . ':' . $digest['nonce'] . ':' . $digest['nc'] . ':' . $digest['cnonce'] . ':' . $digest['qop'] . ':' . $ha2);

        if (!hash_equals($validResponse, (string) ($digest['response'] ?? ''))) {
            $this->registerLoginFailure($policyCredentials, $userId);
            return null;
        }

        if (!$this->isUserStatusAllowed($user)) {
            $this->registerLoginFailure($policyCredentials, $userId);
            return null;
        }

        $this->clearLoginFailures($policyCredentials, $userId);
        unset($user[$safeHa1Column]);
        $this->digestUserCache = array_merge($user, ['auth_type' => 'digest']);
        return $this->digestUserCache;
    }

    public function checkDigest(): bool
    {
        return !empty($this->digestUser());
    }

    public function hasAbility(string $ability): bool
    {
        $ability = trim($ability);
        if ($ability === '') {
            return false;
        }

        $abilities = $this->collectRequestAbilities();
        if (in_array('*', $abilities, true)) {
            return true;
        }

        return in_array($ability, $abilities, true);
    }

    public function roles(?int $userId = null): array
    {
        $resolvedUserId = $this->resolveAclUserId($userId);
        if ($resolvedUserId < 1) {
            return [];
        }

        $rbac = (array) ($this->config['rbac'] ?? []);
        if (($rbac['enabled'] ?? true) !== true) {
            return [];
        }

        $tables = (array) ($rbac['tables'] ?? []);
        $roleCols = (array) ($rbac['role_columns'] ?? []);

        $roleIdColumn = $this->safeColumn((string) ($roleCols['id'] ?? 'id'));
        $roleNameColumn = $this->safeColumn((string) ($roleCols['name'] ?? 'role_name'), 'role_name');
        $roleRankColumn = $this->safeColumn((string) ($roleCols['rank'] ?? 'role_rank'), 'role_rank');
        $roleStatusColumn = $this->safeColumn((string) ($roleCols['status'] ?? 'role_status'), 'role_status');
        $rolesTable = $this->safeTable((string) ($tables['roles'] ?? 'master_roles'));

        $roleIds = $this->userRoleIds($resolvedUserId);
        if (empty($roleIds)) {
            return $this->sessionRoleFallback($resolvedUserId);
        }

        $query = \db()->table($rolesTable)
            ->select(implode(', ', [$roleIdColumn, $roleNameColumn, $roleRankColumn]))
            ->whereIn($roleIdColumn, $roleIds);

        if (($rbac['only_active_roles'] ?? true) === true) {
            $query->where($roleStatusColumn, 1);
        }

        $rows = $query->safeOutput()->get();
        if (!is_array($rows) || empty($rows)) {
            return $this->sessionRoleFallback($resolvedUserId);
        }

        $roles = [];
        foreach ($rows as $row) {
            $roles[] = [
                'id' => isset($row[$roleIdColumn]) ? (int) $row[$roleIdColumn] : null,
                'name' => (string) ($row[$roleNameColumn] ?? ''),
                'rank' => isset($row[$roleRankColumn]) ? (int) $row[$roleRankColumn] : null,
            ];
        }

        return $roles;
    }

    public function hasRole(string|int $role, ?int $userId = null): bool
    {
        $roleValue = is_string($role) ? trim($role) : $role;
        if ($roleValue === '' || $roleValue === 0) {
            return false;
        }

        foreach ($this->roles($userId) as $assignedRole) {
            if (is_int($roleValue) && (int) ($assignedRole['id'] ?? 0) === $roleValue) {
                return true;
            }

            if (is_string($roleValue) && strcasecmp((string) ($assignedRole['name'] ?? ''), $roleValue) === 0) {
                return true;
            }
        }

        return false;
    }

    public function hasAnyRole(array|string $roles, ?int $userId = null): bool
    {
        $roleList = is_array($roles) ? $roles : array_map('trim', explode(',', $roles));
        foreach ($roleList as $role) {
            if ($this->hasRole($role, $userId)) {
                return true;
            }
        }

        return false;
    }

    public function hasAllRoles(array $roles, ?int $userId = null): bool
    {
        if (empty($roles)) {
            return false;
        }

        foreach ($roles as $role) {
            if (!$this->hasRole($role, $userId)) {
                return false;
            }
        }

        return true;
    }

    public function permissions(?int $userId = null, bool $includeRequestAbilities = true): array
    {
        $resolvedUserId = $this->resolveAclUserId($userId);
        if ($resolvedUserId < 1) {
            return [];
        }

        $permissions = [];
        $isCurrentUser = $userId === null;
        $rbac = (array) ($this->config['rbac'] ?? []);

        if ($isCurrentUser && ($rbac['cache_session_permissions'] ?? true) === true) {
            $sessionKey = (string) ($this->config['session_keys']['permissions'] ?? 'permissions');
            $sessionPerms = \getSession($sessionKey);
            if (is_array($sessionPerms)) {
                $permissions = array_merge($permissions, $sessionPerms);
            }
        }

        if (($rbac['enabled'] ?? true) === true) {
            $tables = (array) ($rbac['tables'] ?? []);
            $permissionCols = (array) ($rbac['permission_columns'] ?? []);
            $abilityCols = (array) ($rbac['ability_columns'] ?? []);

            $permissionsTable = $this->safeTable((string) ($tables['permissions'] ?? 'system_permission'));
            $abilitiesTable = $this->safeTable((string) ($tables['abilities'] ?? 'system_abilities'));

            $permissionRoleColumn = $this->safeColumn((string) ($permissionCols['role_id'] ?? 'role_id'));
            $permissionAbilityColumn = $this->safeColumn((string) ($permissionCols['ability_id'] ?? 'abilities_id'));
            $abilityIdColumn = $this->safeColumn((string) ($abilityCols['id'] ?? 'id'));
            $abilitySlugColumn = $this->safeColumn((string) ($abilityCols['slug'] ?? 'abilities_slug'), 'abilities_slug');

            $roleIds = $this->userRoleIds($resolvedUserId);
            if (!empty($roleIds)) {
                $permissionRows = \db()->table($permissionsTable)
                    ->select($permissionAbilityColumn)
                    ->whereIn($permissionRoleColumn, $roleIds)
                    ->safeOutput()
                    ->get();

                $abilityIds = [];
                if (is_array($permissionRows)) {
                    foreach ($permissionRows as $row) {
                        $abilityId = (int) ($row[$permissionAbilityColumn] ?? 0);
                        if ($abilityId > 0) {
                            $abilityIds[] = $abilityId;
                        }
                    }
                }

                $abilityIds = array_values(array_unique($abilityIds));
                if (!empty($abilityIds)) {
                    $abilityRows = \db()->table($abilitiesTable)
                        ->select(implode(', ', [$abilityIdColumn, $abilitySlugColumn]))
                        ->whereIn($abilityIdColumn, $abilityIds)
                        ->safeOutput()
                        ->get();

                    if (is_array($abilityRows)) {
                        foreach ($abilityRows as $row) {
                            $slug = trim((string) ($row[$abilitySlugColumn] ?? ''));
                            if ($slug !== '') {
                                $permissions[] = $slug;
                            }
                        }
                    }
                }
            }
        }

        if ($includeRequestAbilities && $isCurrentUser) {
            $permissions = array_merge($permissions, $this->collectRequestAbilities());
        }

        return $this->normalizeStringList($permissions);
    }

    public function hasPermission(string $permission, ?int $userId = null): bool
    {
        $permission = trim($permission);
        if ($permission === '') {
            return false;
        }

        $permissions = $this->permissions($userId, $userId === null);
        if (in_array('*', $permissions, true)) {
            return true;
        }

        return in_array($permission, $permissions, true);
    }

    public function hasAnyPermission(array|string $permissions, ?int $userId = null): bool
    {
        $permissionList = is_array($permissions) ? $permissions : array_map('trim', explode(',', $permissions));
        foreach ($permissionList as $permission) {
            if ($this->hasPermission((string) $permission, $userId)) {
                return true;
            }
        }

        return false;
    }

    public function hasAllPermissions(array $permissions, ?int $userId = null): bool
    {
        if (empty($permissions)) {
            return false;
        }

        foreach ($permissions as $permission) {
            if (!$this->hasPermission((string) $permission, $userId)) {
                return false;
            }
        }

        return true;
    }

    public function can(string $permission, ?int $userId = null): bool
    {
        return $this->hasPermission($permission, $userId);
    }

    public function cannot(string $permission, ?int $userId = null): bool
    {
        return !$this->can($permission, $userId);
    }

    public function assignRole(int $userId, int|string $role, bool $isMain = false): bool
    {
        if ($userId < 1) {
            return false;
        }

        $roleId = $this->resolveRoleId($role);
        if ($roleId < 1) {
            return false;
        }

        $rbac = (array) ($this->config['rbac'] ?? []);
        if (($rbac['enabled'] ?? true) !== true) {
            return false;
        }

        $tables = (array) ($rbac['tables'] ?? []);
        $profileCols = (array) ($rbac['user_profile_columns'] ?? []);

        $profileTable = $this->safeTable((string) ($tables['user_profile'] ?? 'user_profile'));
        $userIdColumn = $this->safeColumn((string) ($profileCols['user_id'] ?? 'user_id'));
        $roleIdColumn = $this->safeColumn((string) ($profileCols['role_id'] ?? 'role_id'));
        $mainColumn = $this->safeColumn((string) ($profileCols['is_main'] ?? 'is_main'));
        $statusColumn = $this->safeColumn((string) ($profileCols['status'] ?? 'profile_status'));

        if ($isMain) {
            \db()->table($profileTable)
                ->where($userIdColumn, $userId)
                ->update([
                    $mainColumn => 0,
                    'updated_at' => \timestamp(),
                ]);
        }

        $existing = \db()->table($profileTable)
            ->where($userIdColumn, $userId)
            ->where($roleIdColumn, $roleId)
            ->safeOutput()
            ->fetch();

        if (!empty($existing)) {
            \db()->table($profileTable)
                ->where($userIdColumn, $userId)
                ->where($roleIdColumn, $roleId)
                ->update([
                    $statusColumn => 1,
                    $mainColumn => $isMain ? 1 : (int) ($existing[$mainColumn] ?? 0),
                    'updated_at' => \timestamp(),
                ]);

            return true;
        }

        \db()->table($profileTable)->insert([
            $userIdColumn => $userId,
            $roleIdColumn => $roleId,
            $mainColumn => $isMain ? 1 : 0,
            $statusColumn => 1,
            'created_at' => \timestamp(),
            'updated_at' => \timestamp(),
        ]);

        return true;
    }

    public function syncRoles(int $userId, array $roles): bool
    {
        if ($userId < 1 || empty($roles)) {
            return false;
        }

        $resolvedRoleIds = [];
        foreach ($roles as $role) {
            $roleId = $this->resolveRoleId($role);
            if ($roleId > 0) {
                $resolvedRoleIds[] = $roleId;
            }
        }

        $resolvedRoleIds = array_values(array_unique($resolvedRoleIds));
        if (empty($resolvedRoleIds)) {
            return false;
        }

        $rbac = (array) ($this->config['rbac'] ?? []);
        $tables = (array) ($rbac['tables'] ?? []);
        $profileCols = (array) ($rbac['user_profile_columns'] ?? []);

        $profileTable = $this->safeTable((string) ($tables['user_profile'] ?? 'user_profile'));
        $userIdColumn = $this->safeColumn((string) ($profileCols['user_id'] ?? 'user_id'));
        $roleIdColumn = $this->safeColumn((string) ($profileCols['role_id'] ?? 'role_id'));
        $mainColumn = $this->safeColumn((string) ($profileCols['is_main'] ?? 'is_main'));
        $statusColumn = $this->safeColumn((string) ($profileCols['status'] ?? 'profile_status'));

        \db()->table($profileTable)
            ->where($userIdColumn, $userId)
            ->delete();

        foreach ($resolvedRoleIds as $index => $roleId) {
            \db()->table($profileTable)->insert([
                $userIdColumn => $userId,
                $roleIdColumn => $roleId,
                $mainColumn => $index === 0 ? 1 : 0,
                $statusColumn => 1,
                'created_at' => \timestamp(),
                'updated_at' => \timestamp(),
            ]);
        }

        return true;
    }

    public function revokeRole(int $userId, int|string $role): bool
    {
        if ($userId < 1) {
            return false;
        }

        $roleId = $this->resolveRoleId($role);
        if ($roleId < 1) {
            return false;
        }

        $rbac = (array) ($this->config['rbac'] ?? []);
        $tables = (array) ($rbac['tables'] ?? []);
        $profileCols = (array) ($rbac['user_profile_columns'] ?? []);

        $profileTable = $this->safeTable((string) ($tables['user_profile'] ?? 'user_profile'));
        $userIdColumn = $this->safeColumn((string) ($profileCols['user_id'] ?? 'user_id'));
        $roleIdColumn = $this->safeColumn((string) ($profileCols['role_id'] ?? 'role_id'));

        \db()->table($profileTable)
            ->where($userIdColumn, $userId)
            ->where($roleIdColumn, $roleId)
            ->delete();

        return true;
    }

    public function grantPermissionsToRole(int|string $role, array|string $permissions): bool
    {
        $roleId = $this->resolveRoleId($role);
        if ($roleId < 1) {
            return false;
        }

        $abilityIds = $this->resolveAbilityIds($permissions);
        if (empty($abilityIds)) {
            return false;
        }

        $rbac = (array) ($this->config['rbac'] ?? []);
        $tables = (array) ($rbac['tables'] ?? []);
        $permissionCols = (array) ($rbac['permission_columns'] ?? []);

        $permissionsTable = $this->safeTable((string) ($tables['permissions'] ?? 'system_permission'));
        $permissionRoleColumn = $this->safeColumn((string) ($permissionCols['role_id'] ?? 'role_id'));
        $permissionAbilityColumn = $this->safeColumn((string) ($permissionCols['ability_id'] ?? 'abilities_id'));

        foreach ($abilityIds as $abilityId) {
            $exists = \db()->table($permissionsTable)
                ->where($permissionRoleColumn, $roleId)
                ->where($permissionAbilityColumn, $abilityId)
                ->safeOutput()
                ->fetch();

            if (!empty($exists)) {
                continue;
            }

            \db()->table($permissionsTable)->insert([
                $permissionRoleColumn => $roleId,
                $permissionAbilityColumn => $abilityId,
                'created_at' => \timestamp(),
                'updated_at' => \timestamp(),
            ]);
        }

        return true;
    }

    public function revokePermissionsFromRole(int|string $role, array|string $permissions): bool
    {
        $roleId = $this->resolveRoleId($role);
        if ($roleId < 1) {
            return false;
        }

        $abilityIds = $this->resolveAbilityIds($permissions);
        if (empty($abilityIds)) {
            return false;
        }

        $rbac = (array) ($this->config['rbac'] ?? []);
        $tables = (array) ($rbac['tables'] ?? []);
        $permissionCols = (array) ($rbac['permission_columns'] ?? []);

        $permissionsTable = $this->safeTable((string) ($tables['permissions'] ?? 'system_permission'));
        $permissionRoleColumn = $this->safeColumn((string) ($permissionCols['role_id'] ?? 'role_id'));
        $permissionAbilityColumn = $this->safeColumn((string) ($permissionCols['ability_id'] ?? 'abilities_id'));

        \db()->table($permissionsTable)
            ->where($permissionRoleColumn, $roleId)
            ->whereIn($permissionAbilityColumn, $abilityIds)
            ->delete();

        return true;
    }

    // ─── Token Management ────────────────────────────────────

    public function createToken(int $userId, string $name = 'Default Token', ?int $expiresAt = null, array $abilities = ['*']): ?string
    {
        if ($userId < 1) {
            return null;
        }

        $this->ensureTokenTable();

        $tc = $this->config['token_columns'];
        $tokenUserIdColumn = $this->safeColumn((string) ($tc['user_id'] ?? 'user_id'));
        $tokenNameColumn = $this->safeColumn((string) ($tc['name'] ?? 'name'));
        $tokenValueColumn = $this->safeColumn((string) ($tc['token'] ?? 'token'));
        $tokenAbilitiesColumn = $this->safeColumn((string) ($tc['abilities'] ?? 'abilities'));
        $tokenExpiresAtColumn = $this->safeColumn((string) ($tc['expires_at'] ?? 'expires_at'));
        $tokenCreatedAtColumn = $this->safeColumn((string) ($tc['created_at'] ?? 'created_at'));
        $tokenUpdatedAtColumn = $this->safeColumn((string) ($tc['updated_at'] ?? 'updated_at'));

        $plainToken = bin2hex(random_bytes(40));
        $hashedToken = hash('sha256', $plainToken);
        $expiresAtDate = $expiresAt ? date('Y-m-d H:i:s', $expiresAt) : null;
        $tokenTable = $this->safeTable($this->config['token_table']);

        $insert = \db()->table($tokenTable)->insert([
            $tokenUserIdColumn => $userId,
            $tokenNameColumn => $name,
            $tokenValueColumn => $hashedToken,
            $tokenAbilitiesColumn => json_encode($abilities),
            $tokenExpiresAtColumn => $expiresAtDate,
            $tokenCreatedAtColumn => \timestamp(),
            $tokenUpdatedAtColumn => \timestamp(),
        ]);

        if (!\is_array($insert) || !\isSuccess((int) ($insert['code'] ?? 500))) {
            return null;
        }

        return $plainToken;
    }

    public function revokeToken(string $plainToken): bool
    {
        if (empty($plainToken)) {
            return false;
        }

        $tc = $this->config['token_columns'];
        $tokenValueColumn = $this->safeColumn((string) ($tc['token'] ?? 'token'));
        $tokenTable = $this->safeTable($this->config['token_table']);
        $hashedToken = hash('sha256', $plainToken);

        \db()->table($tokenTable)
            ->where($tokenValueColumn, $hashedToken)
            ->delete();

        $this->tokenUserCache = null;

        return true;
    }

    public function revokeCurrentToken(): bool
    {
        $plainToken = $this->bearerToken();
        if (empty($plainToken)) {
            return false;
        }

        return $this->revokeToken($plainToken);
    }

    /**
     * Revoke all tokens for a given user
     */
    public function revokeAllTokens(int $userId): bool
    {
        if ($userId < 1) {
            return false;
        }

        $tc = $this->config['token_columns'];
        $tokenUserIdColumn = $this->safeColumn((string) ($tc['user_id'] ?? 'user_id'));
        $tokenTable = $this->safeTable($this->config['token_table']);

        \db()->table($tokenTable)
            ->where($tokenUserIdColumn, $userId)
            ->delete();

        $this->tokenUserCache = null;

        return true;
    }

    public function createApiKey(int $userId, string $name = 'Default API Key', ?int $expiresAt = null, array $abilities = ['*']): ?string
    {
        if ($userId < 1) {
            return null;
        }

        $apiKeyConfig = (array) ($this->config['api_key'] ?? []);
        if (($apiKeyConfig['enabled'] ?? false) !== true) {
            return null;
        }

        $this->ensureApiKeyTable();

        $columns = (array) ($apiKeyConfig['columns'] ?? []);
        if (empty($columns)) {
            return null;
        }

        $apiKeyUserIdColumn = $this->safeColumn((string) ($columns['user_id'] ?? 'user_id'));
        $apiKeyNameColumn = $this->safeColumn((string) ($columns['name'] ?? 'name'));
        $apiKeyValueColumn = $this->safeColumn((string) ($columns['api_key'] ?? 'api_key'));
        $apiKeyAbilitiesColumn = $this->safeColumn((string) ($columns['abilities'] ?? 'abilities'));
        $apiKeyIsActiveColumn = $this->safeColumn((string) ($columns['is_active'] ?? 'is_active'));
        $apiKeyExpiresAtColumn = $this->safeColumn((string) ($columns['expires_at'] ?? 'expires_at'));
        $apiKeyCreatedAtColumn = $this->safeColumn((string) ($columns['created_at'] ?? 'created_at'));
        $apiKeyUpdatedAtColumn = $this->safeColumn((string) ($columns['updated_at'] ?? 'updated_at'));

        $plainApiKey = bin2hex(random_bytes(32));
        $hashedApiKey = hash('sha256', $plainApiKey);
        $expiresAtDate = $expiresAt ? date('Y-m-d H:i:s', $expiresAt) : null;
        $table = $this->safeTable((string) ($this->config['api_key_table'] ?? 'users_api_keys'));

        $insert = \db()->table($table)->insert([
            $apiKeyUserIdColumn => $userId,
            $apiKeyNameColumn => $name,
            $apiKeyValueColumn => $hashedApiKey,
            $apiKeyAbilitiesColumn => json_encode($abilities),
            $apiKeyIsActiveColumn => 1,
            $apiKeyExpiresAtColumn => $expiresAtDate,
            $apiKeyCreatedAtColumn => \timestamp(),
            $apiKeyUpdatedAtColumn => \timestamp(),
        ]);

        if (!\is_array($insert) || !\isSuccess((int) ($insert['code'] ?? 500))) {
            return null;
        }

        return $plainApiKey;
    }

    public function revokeApiKey(string $plainApiKey): bool
    {
        if ($plainApiKey === '') {
            return false;
        }

        $apiKeyConfig = (array) ($this->config['api_key'] ?? []);
        $columns = (array) ($apiKeyConfig['columns'] ?? []);
        if (empty($columns)) {
            return false;
        }

        $apiKeyValueColumn = $this->safeColumn((string) ($columns['api_key'] ?? 'api_key'));
        $apiKeyIsActiveColumn = $this->safeColumn((string) ($columns['is_active'] ?? 'is_active'));
        $apiKeyUpdatedAtColumn = $this->safeColumn((string) ($columns['updated_at'] ?? 'updated_at'));

        $table = $this->safeTable((string) ($this->config['api_key_table'] ?? 'users_api_keys'));
        $hashedApiKey = hash('sha256', $plainApiKey);

        \db()->table($table)
            ->where($apiKeyValueColumn, $hashedApiKey)
            ->update([
                $apiKeyIsActiveColumn => 0,
                $apiKeyUpdatedAtColumn => \timestamp(),
            ]);

        $this->apiKeyUserCache = null;
        return true;
    }

    public function revokeCurrentApiKey(): bool
    {
        $plainApiKey = $this->extractApiKey();
        if ($plainApiKey === null) {
            return false;
        }

        return $this->revokeApiKey($plainApiKey);
    }

    public function createOAuth2Token(int $userId, string $name = 'Default OAuth2 Token', ?int $expiresAt = null, array $scopes = ['*']): ?string
    {
        if ($userId < 1) {
            return null;
        }

        $oauth2Config = (array) ($this->config['oauth2'] ?? []);
        if (($oauth2Config['enabled'] ?? false) !== true) {
            return null;
        }

        $this->ensureOAuth2Table();

        $columns = (array) ($oauth2Config['columns'] ?? []);
        if (empty($columns)) {
            return null;
        }

        $oauth2UserIdColumn = $this->safeColumn((string) ($columns['user_id'] ?? 'user_id'));
        $oauth2NameColumn = $this->safeColumn((string) ($columns['name'] ?? 'name'));
        $oauth2TokenColumn = $this->safeColumn((string) ($columns['token'] ?? 'token'));
        $oauth2ScopesColumn = $this->safeColumn((string) ($columns['scopes'] ?? 'scopes'));
        $oauth2RevokedColumn = $this->safeColumn((string) ($columns['revoked'] ?? 'revoked'));
        $oauth2ExpiresAtColumn = $this->safeColumn((string) ($columns['expires_at'] ?? 'expires_at'));
        $oauth2CreatedAtColumn = $this->safeColumn((string) ($columns['created_at'] ?? 'created_at'));
        $oauth2UpdatedAtColumn = $this->safeColumn((string) ($columns['updated_at'] ?? 'updated_at'));

        $plainToken = bin2hex(random_bytes(40));
        $storedToken = ($oauth2Config['hash_tokens'] ?? true) === true
            ? hash('sha256', $plainToken)
            : $plainToken;

        $expiresAtDate = $expiresAt ? date('Y-m-d H:i:s', $expiresAt) : null;
        $table = $this->safeTable((string) ($this->config['oauth2_table'] ?? 'oauth2_access_tokens'));

        $insert = \db()->table($table)->insert([
            $oauth2UserIdColumn => $userId,
            $oauth2NameColumn => $name,
            $oauth2TokenColumn => $storedToken,
            $oauth2ScopesColumn => json_encode(array_values($scopes)),
            $oauth2RevokedColumn => 0,
            $oauth2ExpiresAtColumn => $expiresAtDate,
            $oauth2CreatedAtColumn => \timestamp(),
            $oauth2UpdatedAtColumn => \timestamp(),
        ]);

        if (!\is_array($insert) || !\isSuccess((int) ($insert['code'] ?? 500))) {
            return null;
        }

        return $plainToken;
    }

    public function revokeOAuth2Token(string $plainToken): bool
    {
        $plainToken = trim($plainToken);
        if ($plainToken === '') {
            return false;
        }

        $oauth2Config = (array) ($this->config['oauth2'] ?? []);
        $columns = (array) ($oauth2Config['columns'] ?? []);
        if (empty($columns)) {
            return false;
        }

        $oauth2TokenColumn = $this->safeColumn((string) ($columns['token'] ?? 'token'));
        $oauth2RevokedColumn = $this->safeColumn((string) ($columns['revoked'] ?? 'revoked'));
        $oauth2UpdatedAtColumn = $this->safeColumn((string) ($columns['updated_at'] ?? 'updated_at'));

        $table = $this->safeTable((string) ($this->config['oauth2_table'] ?? 'oauth2_access_tokens'));
        $tokenValue = ($oauth2Config['hash_tokens'] ?? true) === true
            ? hash('sha256', $plainToken)
            : $plainToken;

        \db()->table($table)
            ->where($oauth2TokenColumn, $tokenValue)
            ->update([
                $oauth2RevokedColumn => 1,
                $oauth2UpdatedAtColumn => \timestamp(),
            ]);

        $this->oauth2UserCache = null;

        return true;
    }

    public function revokeCurrentOAuth2Token(): bool
    {
        $plainToken = $this->bearerToken();
        if ($plainToken === null) {
            return false;
        }

        return $this->revokeOAuth2Token($plainToken);
    }

    /**
     * Expose login-history recording for non-session login flows (e.g. API token login).
     */
    public function recordLoginHistory(int $userId, int $loginType = 1): void
    {
        if ($userId < 1) {
            return;
        }

        $policy = (array) ($this->config['systems_login_policy'] ?? []);
        if (($policy['enabled'] ?? true) !== true || ($policy['record_history'] ?? true) !== true) {
            return;
        }

        $table = $this->safeTable((string) ($policy['history_table'] ?? 'system_login_history'));
        $historyColumns = $this->historyPolicyColumns();
        $requiredColumns = [
            $historyColumns['user_id'],
            $historyColumns['ip_address'],
            $historyColumns['login_type'],
            $historyColumns['operating_system'],
            $historyColumns['browsers'],
            $historyColumns['time'],
            $historyColumns['user_agent'],
            $historyColumns['created_at'],
            $historyColumns['updated_at'],
        ];
        if (!$this->tableAndColumnsExist($table, $requiredColumns)) {
            return;
        }

        $ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200);

        try {
            \db()->table($table)->insert([
                $historyColumns['user_id'] => $userId,
                $historyColumns['ip_address'] => $this->clientIpAddress(),
                $historyColumns['login_type'] => $loginType,
                $historyColumns['operating_system'] => $this->detectOperatingSystem($ua),
                $historyColumns['browsers'] => $this->detectBrowser($ua),
                $historyColumns['time'] => \timestamp(),
                $historyColumns['user_agent'] => $ua,
                $historyColumns['created_at'] => \timestamp(),
                $historyColumns['updated_at'] => \timestamp(),
            ]);
        } catch (\Throwable $e) {
            // Avoid breaking authentication flow if audit logging fails.
        }
    }

    /**
     * Schema audit helper to verify required Auth tables/columns.
     */
    public function schemaAudit(): array
    {
        $report = [
            'ok' => true,
            'missing_tables' => [],
            'missing_columns' => [],
        ];

        $required = [];
        $uc = (array) ($this->config['user_columns'] ?? []);
        $baseUserColumns = [
            (string) ($uc['id'] ?? 'id'),
            (string) ($uc['name'] ?? 'name'),
            (string) ($uc['preferred_name'] ?? 'user_preferred_name'),
            (string) ($uc['email'] ?? 'email'),
            (string) ($uc['username'] ?? 'username'),
            (string) ($uc['password'] ?? 'password'),
        ];

        $policy = (array) ($this->config['systems_login_policy'] ?? []);
        if (($policy['enforce_user_status'] ?? true) === true) {
            $baseUserColumns[] = (string) ($policy['user_status_column'] ?? ($uc['status'] ?? 'user_status'));
        }

        $passwordRotation = (array) ($policy['password_rotation'] ?? []);
        if (($passwordRotation['enabled'] ?? false) === true) {
            $baseUserColumns[] = (string) ($passwordRotation['force_reset_column'] ?? ($uc['force_password_change'] ?? 'force_password_change'));

            if (($passwordRotation['require_password_changed_at'] ?? false) === true || max(0, (int) ($passwordRotation['max_age_days'] ?? 0)) > 0) {
                $baseUserColumns[] = (string) ($passwordRotation['password_changed_at_column'] ?? ($uc['password_changed_at'] ?? 'password_changed_at'));
            }
        }

        $digestConfig = (array) ($this->config['digest'] ?? []);
        if (($digestConfig['enabled'] ?? false) === true) {
            $baseUserColumns[] = (string) (($digestConfig['ha1_column'] ?? '') ?: ($uc['digest_ha1'] ?? 'digest_ha1'));
        }

        if (($this->config['socialite_enabled'] ?? true) === true) {
            $baseUserColumns[] = (string) ($uc['social_provider'] ?? 'social_provider');
            $baseUserColumns[] = (string) ($uc['social_provider_id'] ?? 'social_provider_id');
        }

        $required[$this->safeTable((string) ($this->config['users_table'] ?? 'users'))] = array_values(array_unique($baseUserColumns));

        $methods = array_merge(
            is_array($this->config['methods'] ?? null) ? (array) $this->config['methods'] : [],
            is_array($this->config['api_methods'] ?? null) ? (array) $this->config['api_methods'] : []
        );
        $methods = array_map(static fn($m) => strtolower(trim((string) $m)), $methods);

        if (in_array('token', $methods, true)) {
            $required[$this->safeTable((string) ($this->config['token_table'] ?? 'users_access_tokens'))] = array_values((array) ($this->config['token_columns'] ?? []));
        }

        $apiKeyConfig = (array) ($this->config['api_key'] ?? []);
        if (($apiKeyConfig['enabled'] ?? false) === true) {
            $required[$this->safeTable((string) ($this->config['api_key_table'] ?? 'users_api_keys'))] = array_values((array) ($apiKeyConfig['columns'] ?? []));
        }

        $oauth2Config = (array) ($this->config['oauth2'] ?? []);
        if (($oauth2Config['enabled'] ?? false) === true) {
            $required[$this->safeTable((string) ($this->config['oauth2_table'] ?? 'oauth2_access_tokens'))] = array_values((array) ($oauth2Config['columns'] ?? []));
        }

        $rbac = (array) ($this->config['rbac'] ?? []);
        if (($rbac['enabled'] ?? true) === true) {
            $tables = (array) ($rbac['tables'] ?? []);
            $required[$this->safeTable((string) ($tables['user_profile'] ?? 'user_profile'))] = array_values((array) ($rbac['user_profile_columns'] ?? []));
            $required[$this->safeTable((string) ($tables['roles'] ?? 'master_roles'))] = array_values((array) ($rbac['role_columns'] ?? []));
            $required[$this->safeTable((string) ($tables['permissions'] ?? 'system_permission'))] = array_values((array) ($rbac['permission_columns'] ?? []));
            $required[$this->safeTable((string) ($tables['abilities'] ?? 'system_abilities'))] = array_values((array) ($rbac['ability_columns'] ?? []));
        }

        if (($policy['enabled'] ?? true) === true && ($policy['record_attempts'] ?? true) === true) {
            $attemptColumns = $this->attemptPolicyColumns();
            $required[$this->safeTable((string) ($policy['attempts_table'] ?? 'system_login_attempt'))] = array_values($attemptColumns);
        }

        if (($policy['enabled'] ?? true) === true && ($policy['record_history'] ?? true) === true) {
            $historyColumns = $this->historyPolicyColumns();
            $required[$this->safeTable((string) ($policy['history_table'] ?? 'system_login_history'))] = array_values($historyColumns);
        }

        foreach ($required as $table => $columns) {
            if (!$this->tableAndColumnsExist($table, [])) {
                $report['ok'] = false;
                $report['missing_tables'][] = $table;
                continue;
            }

            foreach ($columns as $column) {
                $safeColumn = $this->safeColumn((string) $column);
                if (!$this->tableAndColumnsExist($table, [$safeColumn])) {
                    $report['ok'] = false;
                    $report['missing_columns'][$table] ??= [];
                    $report['missing_columns'][$table][] = $safeColumn;
                }
            }
        }

        return $report;
    }

    // ─── Logout ──────────────────────────────────────────────

    /**
     * Log the user out. Uses configurable session keys — no hardcoded values.
     */
    public function logout(bool $destroySession = false): void
    {
        $userId = (int) (\getSession($this->config['session_user_id']) ?? 0);
        $sessionId = $this->currentSessionId();

        if ($userId > 0 && $sessionId !== '') {
            $this->unregisterSessionForUser($userId, $sessionId);
        }

        $keys = $this->config['session_keys'];

        // Build list of session keys to clear from config
        $sessionKeysToClear = [
            $this->config['session_user_id'],
            $this->config['session_flag'],
        ];

        foreach ($keys as $keyName) {
            $sessionKeysToClear[] = $keyName;
        }

        \endSession(array_unique($sessionKeysToClear));

        if ($destroySession) {
            session_destroy();
        }

        $this->sessionUserCache = null;
        $this->tokenUserCache = null;
        $this->jwtUserCache = null;
        $this->apiKeyUserCache = null;
        $this->basicUserCache = null;
        $this->digestUserCache = null;
        $this->oauthUserCache = null;
        $this->oauth2UserCache = null;
    }

    // ─── Social Authentication (OAuth) ──────────────────────

    /**
     * Handle social/OAuth login. Finds or creates a user by provider + provider_id,
     * then logs them in via session.
     *
     * @param string $provider     Provider name (e.g. 'google', 'github', 'facebook')
     * @param array  $socialUser   Data from OAuth provider: [
     *     'provider_id' => '...',      // required — unique ID from provider
     *     'email'       => '...',      // required
     *     'name'        => '...',      // required
     *     'avatar'      => '...',      // optional
     * ]
     * @param callable|null $onCreateCallback  Optional callback for post-creation logic.
     *                                         Receives ($userId, $socialUser) as args.
     * @return array ['code' => int, 'message' => string, 'user_id' => int|null]
     */
    public function socialite(string $provider, array $socialUser, ?callable $onCreateCallback = null): array
    {
        if (empty($this->config['socialite_enabled'])) {
            return ['code' => 403, 'message' => 'Social login is disabled', 'user_id' => null];
        }

        $provider = preg_replace('/[^a-zA-Z0-9_-]/', '', $provider);
        $providerId = (string) ($socialUser['provider_id'] ?? '');
        $email = (string) ($socialUser['email'] ?? '');
        $name = (string) ($socialUser['name'] ?? '');

        if (empty($providerId) || empty($email)) {
            return ['code' => 400, 'message' => 'Missing provider_id or email', 'user_id' => null];
        }

        $uc = $this->config['user_columns'];
        $usersTable = $this->safeTable($this->config['users_table']);

        // 1. Try to find existing user by provider + provider_id
        $existing = \db()->table($usersTable)
            ->select($uc['id'])
            ->where($uc['social_provider'], $provider)
            ->where($uc['social_provider_id'], $providerId)
            ->safeOutput()
            ->fetch();

        if (!empty($existing)) {
            $userId = (int) $existing[$uc['id']];
            $this->login($userId, [
                ($this->config['session_keys']['oauthProvider'] ?? 'oauth_provider') => $provider,
            ]);

            return ['code' => 200, 'message' => 'Login successful', 'user_id' => $userId];
        }

        // 2. Try to match by email (link social to existing account)
        $byEmail = \db()->table($usersTable)
            ->select($uc['id'])
            ->where($uc['email'], $email)
            ->safeOutput()
            ->fetch();

        if (!empty($byEmail)) {
            $userId = (int) $byEmail[$uc['id']];

            \db()->table($usersTable)->where($uc['id'], $userId)->update([
                $uc['social_provider']    => $provider,
                $uc['social_provider_id'] => $providerId,
                'updated_at'              => \timestamp(),
            ]);

            $this->login($userId, [
                ($this->config['session_keys']['oauthProvider'] ?? 'oauth_provider') => $provider,
            ]);

            return ['code' => 200, 'message' => 'Account linked and logged in', 'user_id' => $userId];
        }

        // 3. Create new user
        $insertData = [
            $uc['name']               => $name,
            $uc['email']              => $email,
            $uc['username']           => $email,
            $uc['password']           => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
            $uc['social_provider']    => $provider,
            $uc['social_provider_id'] => $providerId,
            'created_at'              => \timestamp(),
            'updated_at'              => \timestamp(),
        ];

        $result = \db()->table($usersTable)->insert($insertData);

        if (empty($result) || !isset($result['id'])) {
            return ['code' => 500, 'message' => 'Failed to create user', 'user_id' => null];
        }

        $userId = (int) $result['id'];

        // Call optional post-creation callback
        if ($onCreateCallback !== null) {
            $onCreateCallback($userId, $socialUser);
        }

        $this->login($userId, [
            ($this->config['session_keys']['oauthProvider'] ?? 'oauth_provider') => $provider,
        ]);

        return ['code' => 200, 'message' => 'Account created and logged in', 'user_id' => $userId];
    }

    // ─── Request Helpers ─────────────────────────────────────

    public function bearerToken(): ?string
    {
        $authHeader = $this->authorizationHeader();
        if ($authHeader === null) {
            return null;
        }

        $prefixes = ['Bearer'];
        $oauth2Prefix = trim((string) ($this->config['oauth2']['header_prefix'] ?? 'Bearer'));
        if ($oauth2Prefix !== '' && strcasecmp($oauth2Prefix, 'Bearer') !== 0) {
            $prefixes[] = $oauth2Prefix;
        }

        $prefixPattern = implode('|', array_map(static function ($prefix) {
            return preg_quote($prefix, '/');
        }, $prefixes));

        if (!preg_match('/^(?:' . $prefixPattern . ')\s+(.+)$/i', $authHeader, $matches)) {
            return null;
        }

        $token = trim($matches[1]);
        return $token !== '' ? $token : null;
    }

    public function basicChallengeHeader(): string
    {
        $realm = (string) (($this->config['basic']['realm'] ?? 'MythPHP') ?: 'MythPHP');
        return 'Basic realm="' . str_replace('"', '', $realm) . '"';
    }

    public function digestChallengeHeader(): string
    {
        $digestConfig = (array) ($this->config['digest'] ?? []);
        $realm = (string) (($digestConfig['realm'] ?? 'MythPHP API') ?: 'MythPHP API');
        $qop = (string) (($digestConfig['qop'] ?? 'auth') ?: 'auth');
        $nonce = $this->generateDigestNonce();
        $opaque = md5($realm);

        return 'Digest realm="' . str_replace('"', '', $realm) . '", qop="' . str_replace('"', '', $qop) . '", nonce="' . $nonce . '", opaque="' . $opaque . '"';
    }

    // ─── Internal ────────────────────────────────────────────

    /**
     * Get the resolved auth configuration.
     */
    public function getConfig(?string $key = null): mixed
    {
        if ($key === null) {
            return $this->config;
        }

        return $this->config[$key] ?? null;
    }

    /**
     * Resolve API auth methods from API config with auth config fallback.
     * Reusable across middleware/controllers that need consistent API auth behavior.
     */
    public function apiMethods(array|string|null $methods = null): array
    {
        $configured = $methods;

        if ($configured === null) {
            $configured = config('api.auth.methods');
        }

        if ($configured === null) {
            $configured = $this->config['api_methods'] ?? ['token'];
        }

        if (is_string($configured)) {
            $configured = str_contains($configured, ',')
                ? array_map('trim', explode(',', $configured))
                : [trim($configured)];
        }

        if (!is_array($configured) || empty($configured)) {
            return ['token'];
        }

        $aliases = [
            'web' => 'session',
            'api' => 'token',
            'session' => 'session',
            'token' => 'token',
            'jwt' => 'jwt',
            'api_key' => 'api_key',
            'apikey' => 'api_key',
            'oauth' => 'oauth',
            'oauth2' => 'oauth2',
            'basic' => 'basic',
            'digest' => 'digest',
        ];

        $resolved = [];
        foreach ($configured as $method) {
            $name = strtolower(trim((string) $method));
            if ($name === '') {
                continue;
            }

            $mapped = $aliases[$name] ?? null;
            if ($mapped !== null) {
                $resolved[] = $mapped;
            }
        }

        return !empty($resolved) ? array_values(array_unique($resolved)) : ['token'];
    }

    /**
     * Runtime diagnostics for auth failures (safe for logs, no token values).
     */
    public function debugAuthState(array|string|null $methods = null): array
    {
        $methodList = $this->normalizeMethods($methods);
        $methodChecks = [];

        foreach ($methodList as $method) {
            $methodChecks[$method] = $this->checkMethod($method);
        }

        $security = (array) ($this->config['session_security'] ?? []);
        $fingerprintKey = (string) ($security['fingerprint_key'] ?? '_auth_fp');
        $storedFingerprint = (string) (\getSession($fingerprintKey) ?? '');
        $currentFingerprint = $this->clientFingerprint();

        return [
            'methods' => $methodList,
            'method_checks' => $methodChecks,
            'resolved_via' => $this->via($methodList),
            'session' => [
                'active' => session_status() === PHP_SESSION_ACTIVE,
                'id_present' => $this->currentSessionId() !== '',
                'flag' => (bool) \getSession($this->config['session_flag']),
                'user_id' => (int) (\getSession($this->config['session_user_id']) ?? 0),
                'fingerprint_key' => $fingerprintKey,
                'fingerprint_present' => $storedFingerprint !== '',
                'fingerprint_match' => $storedFingerprint !== '' ? hash_equals($storedFingerprint, $currentFingerprint) : false,
                'user_agent_mode' => (string) ($security['user_agent_mode'] ?? 'strict'),
            ],
            'request' => [
                'method' => strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')),
                'uri' => (string) ($_SERVER['REQUEST_URI'] ?? ''),
                'ip' => $this->clientIpAddress(),
                'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 180),
                'has_authorization_header' => $this->authorizationHeader() !== null,
                'has_bearer_token' => $this->bearerToken() !== null,
                'has_api_key' => $this->extractApiKey() !== null,
            ],
        ];
    }

    private function safeTable(string $tableName): string
    {
        $sanitized = preg_replace('/[^a-zA-Z0-9_]/', '', $tableName);
        return $sanitized ?: 'users_access_tokens';
    }

    private function authorizationHeader(): ?string
    {
        $authHeader = \request()->header('Authorization');
        if (!is_string($authHeader) || $authHeader === '') {
            return null;
        }

        return trim($authHeader);
    }

    private function extractApiKey(): ?string
    {
        $apiKeyConfig = (array) ($this->config['api_key'] ?? []);
        $headerName = (string) ($apiKeyConfig['header'] ?? 'X-API-KEY');
        $queryParam = (string) ($apiKeyConfig['query_param'] ?? 'api_key');

        $headerValue = \request()->header($headerName);
        if (is_string($headerValue) && trim($headerValue) !== '') {
            return trim($headerValue);
        }

        $authHeader = $this->authorizationHeader();
        if ($authHeader !== null && preg_match('/^ApiKey\s+(.+)$/i', $authHeader, $matches) === 1) {
            $value = trim((string) $matches[1]);
            if ($value !== '') {
                return $value;
            }
        }

        $allowQueryParam = ($apiKeyConfig['allow_query_param'] ?? false) === true;
        if ($allowQueryParam) {
            $queryValue = $_GET[$queryParam] ?? null;
            if (is_string($queryValue) && trim($queryValue) !== '') {
                return trim($queryValue);
            }
        }

        return null;
    }

    private function extractBasicCredentials(): array
    {
        $username = $_SERVER['PHP_AUTH_USER'] ?? null;
        $password = $_SERVER['PHP_AUTH_PW'] ?? null;

        if (is_string($username) && is_string($password)) {
            return [trim($username), $password];
        }

        $authHeader = $this->authorizationHeader();
        if ($authHeader === null || stripos($authHeader, 'Basic ') !== 0) {
            return [null, null];
        }

        $decoded = base64_decode(substr($authHeader, 6), true);
        if (!is_string($decoded) || !str_contains($decoded, ':')) {
            return [null, null];
        }

        [$id, $pw] = explode(':', $decoded, 2);
        $id = trim($id);
        if ($id === '') {
            return [null, null];
        }

        return [$id, $pw];
    }

    private function extractDigestCredentials(): array
    {
        $authHeader = $this->authorizationHeader();
        if ($authHeader === null || stripos($authHeader, 'Digest ') !== 0) {
            return [];
        }

        $digestString = trim(substr($authHeader, 7));
        $neededParts = ['nonce', 'nc', 'cnonce', 'qop', 'username', 'uri', 'response'];
        $data = [];

        preg_match_all('@(\w+)=([\"\']?)([^\",\']+)\2@', $digestString, $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            $data[$m[1]] = $m[3];
        }

        foreach ($neededParts as $part) {
            if (!isset($data[$part])) {
                return [];
            }
        }

        return $data;
    }

    private function decodeJwt(string $jwt): ?array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;
        $headerJson = $this->base64UrlDecode($encodedHeader);
        $payloadJson = $this->base64UrlDecode($encodedPayload);

        if ($headerJson === null || $payloadJson === null) {
            return null;
        }

        $header = json_decode($headerJson, true);
        $payload = json_decode($payloadJson, true);
        if (!is_array($header) || !is_array($payload)) {
            return null;
        }

        if (isset($header['typ']) && strtoupper((string) $header['typ']) !== 'JWT') {
            return null;
        }

        $jwtConfig = (array) ($this->config['jwt'] ?? []);
        $algo = strtoupper((string) ($jwtConfig['algo'] ?? 'HS256'));
        if ($algo !== 'HS256') {
            return null;
        }

        $headerAlgo = strtoupper((string) ($header['alg'] ?? ''));
        if ($headerAlgo === '' || $headerAlgo !== $algo) {
            return null;
        }

        $secret = (string) ($jwtConfig['secret'] ?? '');
        if ($secret === '') {
            $secret = (string) (getenv('APP_KEY') ?: '');
        }

        if ($secret === '') {
            return null;
        }

        $signingInput = $encodedHeader . '.' . $encodedPayload;
        $expected = hash_hmac('sha256', $signingInput, $secret, true);
        $actual = $this->base64UrlDecode($encodedSignature);
        if ($actual === null || !hash_equals($expected, $actual)) {
            return null;
        }

        $now = time();
        $leeway = max(0, (int) ($jwtConfig['leeway'] ?? 60));

        if (isset($payload['nbf']) && is_numeric($payload['nbf']) && $now + $leeway < (int) $payload['nbf']) {
            return null;
        }

        if (isset($payload['exp']) && is_numeric($payload['exp']) && $now - $leeway >= (int) $payload['exp']) {
            return null;
        }

        return $payload;
    }

    private function base64UrlDecode(string $input): ?string
    {
        $remainder = strlen($input) % 4;
        if ($remainder > 0) {
            $input .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($input, '-_', '+/'), true);
        return is_string($decoded) ? $decoded : null;
    }

    private function digestNonceSecret(): string
    {
        $secret = (string) (($this->config['digest']['nonce_secret'] ?? '') ?: '');
        if ($secret === '') {
            $secret = (string) (getenv('APP_KEY') ?: '');
        }

        return $secret !== '' ? $secret : 'mythphp-digest-secret';
    }

    private function generateDigestNonce(): string
    {
        $timestamp = (string) time();
        $hash = hash_hmac('sha256', $timestamp, $this->digestNonceSecret());
        return rtrim(strtr(base64_encode($timestamp . ':' . $hash), '+/', '-_'), '=');
    }

    private function isDigestNonceValid(string $nonce): bool
    {
        $decoded = $this->base64UrlDecode($nonce);
        if ($decoded === null || !str_contains($decoded, ':')) {
            return false;
        }

        [$timestamp, $hash] = explode(':', $decoded, 2);
        if (!ctype_digit($timestamp) || $hash === '') {
            return false;
        }

        $issuedAt = (int) $timestamp;
        $ttl = max(30, (int) (($this->config['digest']['nonce_ttl'] ?? 300)));
        $futureSkew = max(0, (int) (($this->config['digest']['nonce_future_skew'] ?? 30)));

        if ($issuedAt > time() + $futureSkew) {
            return false;
        }

        if (time() - $issuedAt > $ttl) {
            return false;
        }

        $expectedHash = hash_hmac('sha256', $timestamp, $this->digestNonceSecret());
        return hash_equals($expectedHash, $hash);
    }

    private function isDigestRequestUriValid(string $digestUri): bool
    {
        if ($digestUri === '') {
            return false;
        }

        $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        if ($requestUri === '') {
            return false;
        }

        return hash_equals($requestUri, $digestUri);
    }

    private function isDigestNonceCounterValid(string $username, string $nonce, string $nc): bool
    {
        if (!preg_match('/^[0-9a-fA-F]{8}$/', $nc)) {
            return false;
        }

        $counter = hexdec($nc);
        if ($counter < 1) {
            return false;
        }

        $cacheKey = 'auth_digest_nc_' . sha1($username . '|' . $nonce);
        $lastCounter = $this->digestNcMemory[$cacheKey] ?? 0;

        if (function_exists('cache')) {
            try {
                $cachedCounter = cache()->get($cacheKey, 0);
                if (is_numeric($cachedCounter)) {
                    $lastCounter = max((int) $lastCounter, (int) $cachedCounter);
                }
            } catch (\Throwable $e) {
                // Fall back to in-memory counter only.
            }
        }

        if ($counter <= $lastCounter) {
            return false;
        }

        $this->digestNcMemory[$cacheKey] = $counter;

        if (function_exists('cache')) {
            try {
                $ttl = max(30, (int) (($this->config['digest']['nonce_ttl'] ?? 300)));
                cache()->put($cacheKey, $counter, $ttl);
            } catch (\Throwable $e) {
                // In-memory protection still applies for this request lifecycle.
            }
        }

        return true;
    }

    /**
     * Normalize auth methods into ordered internal method names.
     *
     * Accepted aliases:
     * - web => session
     * - api => token
     */
    private function normalizeMethods(array|string|null $methods = null): array
    {
        $rawMethods = $methods;
        $defaultMethods = ['session'];

        if ($rawMethods === null) {
            $rawMethods = $this->config['methods'] ?? $defaultMethods;
        }

        if (is_string($rawMethods)) {
            $rawMethods = str_contains($rawMethods, ',')
                ? array_map('trim', explode(',', $rawMethods))
                : [trim($rawMethods)];
        }

        if (!is_array($rawMethods) || empty($rawMethods)) {
            $rawMethods = $defaultMethods;
        }

        $aliases = [
            'web' => 'session',
            'api' => 'token',
            'session' => 'session',
            'token' => 'token',
            'jwt' => 'jwt',
            'api_key' => 'api_key',
            'apikey' => 'api_key',
            'oauth' => 'oauth',
            'oauth2' => 'oauth2',
            'basic' => 'basic',
            'digest' => 'digest',
        ];

        $normalized = [];
        foreach ($rawMethods as $method) {
            $name = strtolower(trim((string) $method));
            if ($name === '') {
                continue;
            }

            $resolved = $aliases[$name] ?? null;
            if ($resolved !== null) {
                $normalized[] = $resolved;
            }
        }

        if (empty($normalized)) {
            return $defaultMethods;
        }

        return array_values(array_unique($normalized));
    }

    private function checkMethod(string $method): bool
    {
        return match ($method) {
            'session' => $this->checkSession(),
            'token' => $this->checkToken(),
            'jwt' => $this->checkJwt(),
            'api_key' => $this->checkApiKey(),
            'oauth' => $this->checkOAuth(),
            'oauth2' => $this->checkOAuth2(),
            'basic' => $this->checkBasic(),
            'digest' => $this->checkDigest(),
            default => false,
        };
    }

    private function userByMethod(string $method): ?array
    {
        return match ($method) {
            'session' => $this->sessionUser(),
            'token' => $this->tokenUser(),
            'jwt' => $this->jwtUser(),
            'api_key' => $this->apiKeyUser(),
            'oauth' => $this->oauthUser(),
            'oauth2' => $this->oauth2User(),
            'basic' => $this->basicUser(),
            'digest' => $this->digestUser(),
            default => null,
        };
    }

    private function ensureTokenTable(): void
    {
        $tc = array_map(fn($col) => preg_replace('/[^a-zA-Z0-9_]/', '', $col), $this->config['token_columns']);
        $tokenTable = $this->safeTable($this->config['token_table']);

        \db()->query(
            "CREATE TABLE IF NOT EXISTS {$tokenTable} (
                {$tc['id']} BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                {$tc['user_id']} BIGINT UNSIGNED NOT NULL,
                {$tc['name']} VARCHAR(255) NOT NULL,
                {$tc['token']} VARCHAR(255) NOT NULL UNIQUE,
                {$tc['abilities']} TEXT,
                {$tc['expires_at']} DATETIME NULL,
                {$tc['last_used_at']} DATETIME NULL,
                {$tc['created_at']} DATETIME DEFAULT CURRENT_TIMESTAMP,
                {$tc['updated_at']} DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )"
        );
    }

    private function ensureApiKeyTable(): void
    {
        $apiKeyConfig = (array) ($this->config['api_key'] ?? []);
        $columns = array_map(fn($col) => preg_replace('/[^a-zA-Z0-9_]/', '', (string) $col), (array) ($apiKeyConfig['columns'] ?? []));
        if (empty($columns)) {
            return;
        }

        $table = $this->safeTable((string) ($this->config['api_key_table'] ?? 'users_api_keys'));

        \db()->query(
            "CREATE TABLE IF NOT EXISTS {$table} (
                {$columns['id']} BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                {$columns['user_id']} BIGINT UNSIGNED NOT NULL,
                {$columns['name']} VARCHAR(255) NOT NULL,
                {$columns['api_key']} VARCHAR(255) NOT NULL UNIQUE,
                {$columns['abilities']} TEXT,
                {$columns['is_active']} TINYINT(1) NOT NULL DEFAULT 1,
                {$columns['expires_at']} DATETIME NULL,
                {$columns['last_used_at']} DATETIME NULL,
                {$columns['created_at']} DATETIME DEFAULT CURRENT_TIMESTAMP,
                {$columns['updated_at']} DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_api_keys_user ({$columns['user_id']}),
                INDEX idx_api_keys_active ({$columns['is_active']}),
                INDEX idx_api_keys_expiry ({$columns['expires_at']})
            )"
        );
    }

    private function ensureOAuth2Table(): void
    {
        $oauth2Config = (array) ($this->config['oauth2'] ?? []);
        $columns = array_map(fn($col) => preg_replace('/[^a-zA-Z0-9_]/', '', (string) $col), (array) ($oauth2Config['columns'] ?? []));
        if (empty($columns)) {
            return;
        }

        $table = $this->safeTable((string) ($this->config['oauth2_table'] ?? 'oauth2_access_tokens'));

        \db()->query(
            "CREATE TABLE IF NOT EXISTS {$table} (
                {$columns['id']} BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                {$columns['user_id']} BIGINT UNSIGNED NOT NULL,
                {$columns['name']} VARCHAR(255) NULL,
                {$columns['token']} VARCHAR(255) NOT NULL UNIQUE,
                {$columns['scopes']} TEXT,
                {$columns['revoked']} TINYINT(1) NOT NULL DEFAULT 0,
                {$columns['expires_at']} DATETIME NULL,
                {$columns['last_used_at']} DATETIME NULL,
                {$columns['created_at']} DATETIME DEFAULT CURRENT_TIMESTAMP,
                {$columns['updated_at']} DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_oauth2_tokens_user ({$columns['user_id']}),
                INDEX idx_oauth2_tokens_revoked ({$columns['revoked']}),
                INDEX idx_oauth2_tokens_expiry ({$columns['expires_at']})
            )"
        );
    }

    private function resolveAclUserId(?int $userId = null): int
    {
        if ($userId !== null && $userId > 0) {
            return $userId;
        }

        return (int) ($this->id(['session', 'token', 'jwt', 'api_key', 'oauth2', 'oauth', 'basic', 'digest']) ?? 0);
    }

    private function userRoleIds(int $userId): array
    {
        if ($userId < 1) {
            return [];
        }

        $rbac = (array) ($this->config['rbac'] ?? []);
        if (($rbac['enabled'] ?? true) !== true) {
            return [];
        }

        $tables = (array) ($rbac['tables'] ?? []);
        $profileCols = (array) ($rbac['user_profile_columns'] ?? []);

        $profileTable = $this->safeTable((string) ($tables['user_profile'] ?? 'user_profile'));
        $userIdColumn = $this->safeColumn((string) ($profileCols['user_id'] ?? 'user_id'));
        $roleIdColumn = $this->safeColumn((string) ($profileCols['role_id'] ?? 'role_id'));
        $statusColumn = $this->safeColumn((string) ($profileCols['status'] ?? 'profile_status'));

        $query = \db()->table($profileTable)
            ->select($roleIdColumn)
            ->where($userIdColumn, $userId);

        if (($rbac['only_active_profiles'] ?? true) === true) {
            $query->where($statusColumn, 1);
        }

        $rows = $query->safeOutput()->get();
        if (!is_array($rows)) {
            return [];
        }

        $roleIds = [];
        foreach ($rows as $row) {
            $roleId = (int) ($row[$roleIdColumn] ?? 0);
            if ($roleId > 0) {
                $roleIds[] = $roleId;
            }
        }

        return array_values(array_unique($roleIds));
    }

    private function sessionRoleFallback(int $resolvedUserId): array
    {
        if ($resolvedUserId !== $this->resolveAclUserId(null)) {
            return [];
        }

        if (!$this->checkSession()) {
            return [];
        }

        $keys = (array) ($this->config['session_keys'] ?? []);
        $roleId = (int) \getSession((string) ($keys['roleID'] ?? 'roleID'));
        $roleName = (string) \getSession((string) ($keys['roleName'] ?? 'roleName'));
        $roleRank = (int) \getSession((string) ($keys['roleRank'] ?? 'roleRank'));

        if ($roleId < 1 && $roleName === '') {
            return [];
        }

        return [[
            'id' => $roleId > 0 ? $roleId : null,
            'name' => $roleName,
            'rank' => $roleRank,
        ]];
    }

    private function safeColumn(string $column, string $fallback = 'id'): string
    {
        $sanitized = preg_replace('/[^a-zA-Z0-9_]/', '', $column);
        return $sanitized ?: $fallback;
    }

    /**
     * Check if a user row's status is within the allowed active statuses.
     * Respects the `systems_login_policy.enforce_user_status` setting.
     */
    private function isUserStatusAllowed(array $user): bool
    {
        $policy = (array) ($this->config['systems_login_policy'] ?? []);
        if (($policy['enforce_user_status'] ?? true) !== true) {
            return true;
        }

        $uc = $this->config['user_columns'];
        $statusColumn = $this->safeColumn(
            (string) ($policy['user_status_column'] ?? ($uc['status'] ?? 'user_status')),
            'user_status'
        );

        if (!array_key_exists($statusColumn, $user)) {
            return true;
        }

        $allowed = array_map('intval', (array) ($policy['allowed_user_status'] ?? [1]));
        return in_array((int) ($user[$statusColumn] ?? 0), $allowed, true);
    }

    private function canAttemptWithLoginPolicy(array $credentials): bool
    {
        $policy = (array) ($this->config['systems_login_policy'] ?? []);
        if (($policy['enabled'] ?? true) !== true) {
            return true;
        }

        $lockState = $this->resolveLoginLockState($credentials);
        if (($lockState['locked'] ?? false) === true) {
            $lockUntil = (int) ($lockState['locked_until_ts'] ?? 0);
            $this->setLastAttemptStatus('login_locked', 'Too many login attempts. Please try again later.', 423, [
                'locked_until' => $lockUntil > 0 ? date('Y-m-d H:i:s', $lockUntil) : null,
                'retry_after' => max(1, (int) ($lockState['retry_after'] ?? 1)),
            ]);
            return false;
        }

        return true;
    }

    private function registerLoginFailure(array $credentials, ?int $userId = null): void
    {
        $policy = (array) ($this->config['systems_login_policy'] ?? []);
        if (($policy['enabled'] ?? true) !== true) {
            return;
        }

        if (($policy['record_attempts'] ?? true) === true) {
            $table = $this->safeTable((string) ($policy['attempts_table'] ?? 'system_login_attempt'));
            $attemptColumns = $this->attemptPolicyColumns();
            $requiredColumns = [
                $attemptColumns['user_id'],
                $attemptColumns['identifier'],
                $attemptColumns['ip_address'],
                $attemptColumns['time'],
                $attemptColumns['user_agent'],
                $attemptColumns['created_at'],
                $attemptColumns['updated_at'],
            ];
            if ($this->tableAndColumnsExist($table, $requiredColumns)) {
                try {
                    \db()->table($table)->insert([
                        $attemptColumns['user_id'] => $userId,
                        $attemptColumns['identifier'] => $this->loginPolicyIdentifier($credentials),
                        $attemptColumns['ip_address'] => $this->clientIpAddress(),
                        $attemptColumns['time'] => \timestamp(),
                        $attemptColumns['user_agent'] => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200),
                        $attemptColumns['created_at'] => \timestamp(),
                        $attemptColumns['updated_at'] => \timestamp(),
                    ]);
                } catch (\Throwable $e) {
                    // Do not break login flow if attempt logging fails.
                }
            }
        }

        if ($userId !== null && $userId > 0) {
            $this->applyAutomaticBan($userId, 0);
        }
    }

    private function attemptPolicyColumns(): array
    {
        $policy = (array) ($this->config['systems_login_policy'] ?? []);
        $columns = (array) ($policy['attempts_columns'] ?? []);

        return [
            'user_id' => $this->safeColumn((string) ($columns['user_id'] ?? 'user_id')),
            'identifier' => $this->safeColumn((string) ($columns['identifier'] ?? 'identifier')),
            'ip_address' => $this->safeColumn((string) ($columns['ip_address'] ?? 'ip_address')),
            'time' => $this->safeColumn((string) ($columns['time'] ?? 'time')),
            'user_agent' => $this->safeColumn((string) ($columns['user_agent'] ?? 'user_agent')),
            'created_at' => $this->safeColumn((string) ($columns['created_at'] ?? 'created_at')),
            'updated_at' => $this->safeColumn((string) ($columns['updated_at'] ?? 'updated_at')),
        ];
    }

    private function historyPolicyColumns(): array
    {
        $policy = (array) ($this->config['systems_login_policy'] ?? []);
        $columns = (array) ($policy['history_columns'] ?? []);

        return [
            'user_id' => $this->safeColumn((string) ($columns['user_id'] ?? 'user_id')),
            'ip_address' => $this->safeColumn((string) ($columns['ip_address'] ?? 'ip_address')),
            'login_type' => $this->safeColumn((string) ($columns['login_type'] ?? 'login_type')),
            'operating_system' => $this->safeColumn((string) ($columns['operating_system'] ?? 'operating_system')),
            'browsers' => $this->safeColumn((string) ($columns['browsers'] ?? 'browsers')),
            'time' => $this->safeColumn((string) ($columns['time'] ?? 'time')),
            'user_agent' => $this->safeColumn((string) ($columns['user_agent'] ?? 'user_agent')),
            'created_at' => $this->safeColumn((string) ($columns['created_at'] ?? 'created_at')),
            'updated_at' => $this->safeColumn((string) ($columns['updated_at'] ?? 'updated_at')),
        ];
    }

    private function clearLoginFailures(array $credentials, ?int $userId = null): void
    {
        $policy = (array) ($this->config['systems_login_policy'] ?? []);
        if (($policy['enabled'] ?? true) !== true) {
            return;
        }

        $table = $this->safeTable((string) ($policy['attempts_table'] ?? 'system_login_attempt'));
        $attemptColumns = $this->attemptPolicyColumns();
        $requiredColumns = [$attemptColumns['time']];

        $identifier = $this->loginPolicyIdentifier($credentials);
        $trackByIdentifier = ($policy['track_by_identifier'] ?? true) === true && $identifier !== '';
        $trackByIp = ($policy['track_by_ip'] ?? true) === true;

        if ($userId !== null && $userId > 0) {
            $requiredColumns[] = $attemptColumns['user_id'];
        }

        if ($trackByIdentifier) {
            $requiredColumns[] = $attemptColumns['identifier'];
        }

        if ($trackByIp && !$trackByIdentifier) {
            $requiredColumns[] = $attemptColumns['ip_address'];
        }

        if (!$this->tableAndColumnsExist($table, array_values(array_unique($requiredColumns)))) {
            return;
        }

        try {
            if ($userId !== null && $userId > 0) {
                \db()->table($table)
                    ->where($attemptColumns['user_id'], $userId)
                    ->delete();
            }

            if ($trackByIdentifier) {
                \db()->table($table)
                    ->where($attemptColumns['identifier'], $identifier)
                    ->delete();
            } elseif ($trackByIp) {
                \db()->table($table)
                    ->where($attemptColumns['ip_address'], $this->clientIpAddress())
                    ->delete();
            }
        } catch (\Throwable $e) {
            // Ignore login-attempt cleanup errors.
        }
    }

    private function loginPolicyIdentifier(array $credentials): string
    {
        $policy = (array) ($this->config['systems_login_policy'] ?? []);
        $fields = (array) ($policy['identifier_fields'] ?? ['email', 'username']);

        foreach ($fields as $field) {
            $name = strtolower(trim((string) $field));
            if ($name === '') {
                continue;
            }

            if (!array_key_exists($name, $credentials)) {
                continue;
            }

            $value = strtolower(trim((string) $credentials[$name]));
            if ($value !== '') {
                return $name . ':' . $value;
            }
        }

        return '';
    }

    private function policyCredentialsForIdentifier(string $identifier, array $candidateFields = ['username', 'email']): array
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return [];
        }

        $normalizedFields = array_values(array_filter(array_map(static function ($field) {
            return strtolower(trim((string) $field));
        }, $candidateFields), static function ($field) {
            return $field !== '';
        }));

        $preferredField = filter_var($identifier, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';
        if (!in_array($preferredField, $normalizedFields, true)) {
            $preferredField = $normalizedFields[0] ?? $preferredField;
        }

        return [$preferredField => $identifier];
    }

    private function clientIpAddress(): string
    {
        $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        return $ip !== '' ? $ip : 'unknown';
    }

    private function resetLastAttemptStatus(): void
    {
        $this->lastAttemptStatus = [];
    }

    private function setLastAttemptStatus(string $reason, string $message, int $httpCode = 401, array $context = []): void
    {
        $this->lastAttemptStatus = [
            'reason' => $reason,
            'message' => $message,
            'http_code' => $httpCode,
            'context' => $context,
        ];
    }

    private function passesPasswordRotationPolicy(array $user): bool
    {
        $policy = (array) ($this->config['systems_login_policy'] ?? []);
        $rotation = (array) ($policy['password_rotation'] ?? []);
        if (($rotation['enabled'] ?? false) !== true) {
            return true;
        }

        $usersTable = $this->safeTable((string) ($this->config['users_table'] ?? 'users'));
        $uc = (array) ($this->config['user_columns'] ?? []);
        $forceResetColumn = $this->safeColumn(
            (string) ($rotation['force_reset_column'] ?? ($uc['force_password_change'] ?? 'force_password_change')),
            'force_password_change'
        );

        if ($this->tableAndColumnsExist($usersTable, [$forceResetColumn]) && !empty($user[$forceResetColumn])) {
            $this->setLastAttemptStatus('password_change_required', 'Your password must be changed before you can continue.', 403, [
                'force_password_change' => true,
            ]);
            return false;
        }

        $maxAgeDays = max(0, (int) ($rotation['max_age_days'] ?? 90));
        if ($maxAgeDays < 1) {
            return true;
        }

        $changedAtColumn = $this->safeColumn(
            (string) ($rotation['password_changed_at_column'] ?? ($uc['password_changed_at'] ?? 'password_changed_at')),
            'password_changed_at'
        );
        $hasChangedAtColumn = $this->tableAndColumnsExist($usersTable, [$changedAtColumn]);
        $requireChangedAt = ($rotation['require_password_changed_at'] ?? false) === true;

        if (!$hasChangedAtColumn) {
            return !$requireChangedAt;
        }

        $changedAt = trim((string) ($user[$changedAtColumn] ?? ''));
        if ($changedAt === '') {
            if ($requireChangedAt) {
                $this->setLastAttemptStatus('password_change_required', 'Your password must be updated before you can continue.', 403, [
                    'password_changed_at_missing' => true,
                ]);
                return false;
            }

            return true;
        }

        $changedAtTimestamp = strtotime($changedAt);
        if ($changedAtTimestamp === false) {
            return !$requireChangedAt;
        }

        $maxAgeSeconds = $maxAgeDays * 86400;
        if (($changedAtTimestamp + $maxAgeSeconds) < time()) {
            $this->setLastAttemptStatus('password_expired', 'Your password has expired and must be changed.', 403, [
                'password_expired' => true,
                'max_age_days' => $maxAgeDays,
                'password_changed_at' => $changedAt,
            ]);
            return false;
        }

        return true;
    }

    private function applyAutomaticBan(?int $userId, int $observedAttempts): void
    {
        $policy = (array) ($this->config['systems_login_policy'] ?? []);
        if (($policy['ban_enabled'] ?? false) !== true || $userId === null || $userId < 1) {
            return;
        }

        $banAfterFailures = max(1, (int) ($policy['ban_after_failures'] ?? 15));
        $recentFailures = $this->countRecentLoginAttemptsForUser($userId);
        if ($recentFailures < $banAfterFailures && $observedAttempts < $banAfterFailures) {
            return;
        }

        $usersTable = $this->safeTable((string) ($this->config['users_table'] ?? 'users'));
        $uc = (array) ($this->config['user_columns'] ?? []);
        $statusColumn = $this->safeColumn((string) ($policy['user_status_column'] ?? ($uc['status'] ?? 'user_status')), 'user_status');

        if (!$this->tableAndColumnsExist($usersTable, [$statusColumn])) {
            return;
        }

        try {
            \db()->table($usersTable)
                ->where($this->safeColumn((string) ($uc['id'] ?? 'id'), 'id'), $userId)
                ->update([
                    $statusColumn => (int) ($policy['ban_user_status'] ?? 2),
                    'updated_at' => \timestamp(),
                ]);
        } catch (\Throwable $e) {
            // Do not break authentication flow if auto-ban persistence fails.
        }
    }

    private function resolveLoginLockState(array $credentials): array
    {
        $policy = (array) ($this->config['systems_login_policy'] ?? []);
        $maxAttempts = max(1, (int) ($policy['max_attempts'] ?? 5));
        $decaySeconds = max(60, (int) ($policy['decay_seconds'] ?? 600));
        $lockoutSeconds = max(60, (int) ($policy['lockout_seconds'] ?? 900));
        $windowSeconds = $decaySeconds + $lockoutSeconds;

        $timestamps = $this->recentLoginAttemptTimestamps($credentials, $windowSeconds);
        if (count($timestamps) < $maxAttempts) {
            return ['locked' => false];
        }

        $now = time();
        $lockedUntil = 0;
        $lastIndex = count($timestamps) - $maxAttempts;

        for ($index = 0; $index <= $lastIndex; $index++) {
            $windowStart = $timestamps[$index];
            $windowEnd = $timestamps[$index + $maxAttempts - 1];

            if (($windowEnd - $windowStart) > $decaySeconds) {
                continue;
            }

            $candidateLockUntil = $windowEnd + $lockoutSeconds;
            if ($candidateLockUntil > $lockedUntil) {
                $lockedUntil = $candidateLockUntil;
            }
        }

        if ($lockedUntil <= $now) {
            return ['locked' => false];
        }

        return [
            'locked' => true,
            'locked_until_ts' => $lockedUntil,
            'retry_after' => $lockedUntil - $now,
        ];
    }

    private function recentLoginAttemptTimestamps(array $credentials, int $windowSeconds): array
    {
        $policy = (array) ($this->config['systems_login_policy'] ?? []);
        $attemptColumns = $this->attemptPolicyColumns();
        $table = $this->safeTable((string) ($policy['attempts_table'] ?? 'system_login_attempt'));

        $requiredColumns = [$attemptColumns['time']];
        $identifier = $this->loginPolicyIdentifier($credentials);
        $trackByIdentifier = ($policy['track_by_identifier'] ?? true) === true && $identifier !== '';
        $trackByIp = ($policy['track_by_ip'] ?? true) === true;

        if ($trackByIdentifier) {
            $requiredColumns[] = $attemptColumns['identifier'];
        }

        if ($trackByIp) {
            $requiredColumns[] = $attemptColumns['ip_address'];
        }

        if (!$this->tableAndColumnsExist($table, $requiredColumns)) {
            return [];
        }

        if (!$trackByIdentifier && !$trackByIp) {
            return [];
        }

        try {
            $query = \db()->table($table)
                ->select($attemptColumns['time'])
                ->where($attemptColumns['time'], 'IS NOT NULL');

            $cutoff = date('Y-m-d H:i:s', time() - max(60, $windowSeconds));
            $query->where($attemptColumns['time'], '>=', $cutoff);

            if ($trackByIdentifier) {
                $query->where($attemptColumns['identifier'], $identifier);
            }

            if ($trackByIp) {
                $query->where($attemptColumns['ip_address'], $this->clientIpAddress());
            }

            $rows = $query->orderBy($attemptColumns['time'], 'ASC')->safeOutput()->get();
            if (!is_array($rows)) {
                return [];
            }

            $timestamps = [];
            foreach ($rows as $row) {
                $timestamp = strtotime((string) ($row[$attemptColumns['time']] ?? ''));
                if ($timestamp !== false) {
                    $timestamps[] = $timestamp;
                }
            }

            sort($timestamps);
            return $timestamps;
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function countRecentLoginAttemptsForUser(int $userId): int
    {
        if ($userId < 1) {
            return 0;
        }

        $policy = (array) ($this->config['systems_login_policy'] ?? []);
        $attemptColumns = $this->attemptPolicyColumns();
        $table = $this->safeTable((string) ($policy['attempts_table'] ?? 'system_login_attempt'));
        $requiredColumns = [$attemptColumns['user_id'], $attemptColumns['time']];

        if (!$this->tableAndColumnsExist($table, $requiredColumns)) {
            return 0;
        }

        try {
            $cutoff = date('Y-m-d H:i:s', time() - max(60, (int) ($policy['decay_seconds'] ?? 600)));
            $rows = \db()->table($table)
                ->select($attemptColumns['time'])
                ->where($attemptColumns['user_id'], $userId)
                ->where($attemptColumns['time'], 'IS NOT NULL')
                ->where($attemptColumns['time'], '>=', $cutoff)
                ->safeOutput()
                ->get();

            return is_array($rows) ? count($rows) : 0;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function resolveLoginType(array $sessionData): int
    {
        $oauthKey = (string) ($this->config['session_keys']['oauthProvider'] ?? 'oauth_provider');
        if (isset($sessionData[$oauthKey]) && trim((string) $sessionData[$oauthKey]) !== '') {
            return 2;
        }

        return 1;
    }

    private function detectOperatingSystem(string $userAgent): string
    {
        $ua = strtolower($userAgent);
        return match (true) {
            str_contains($ua, 'windows') => 'Windows',
            str_contains($ua, 'mac os') || str_contains($ua, 'macintosh') => 'macOS',
            str_contains($ua, 'android') => 'Android',
            str_contains($ua, 'iphone') || str_contains($ua, 'ipad') || str_contains($ua, 'ios') => 'iOS',
            str_contains($ua, 'linux') => 'Linux',
            default => 'Unknown',
        };
    }

    private function detectBrowser(string $userAgent): string
    {
        $ua = strtolower($userAgent);
        return match (true) {
            str_contains($ua, 'edg/') => 'Edge',
            str_contains($ua, 'chrome/') && !str_contains($ua, 'edg/') => 'Chrome',
            str_contains($ua, 'firefox/') => 'Firefox',
            str_contains($ua, 'safari/') && !str_contains($ua, 'chrome/') => 'Safari',
            str_contains($ua, 'opr/') || str_contains($ua, 'opera/') => 'Opera',
            default => 'Unknown',
        };
    }

    private function tableAndColumnsExist(string $table, array $columns = []): bool
    {
        $safeTable = $this->safeTable($table);
        $cacheKey = $safeTable . '|' . implode(',', $columns);
        if (isset($this->schemaCheckCache[$cacheKey])) {
            return $this->schemaCheckCache[$cacheKey];
        }

        try {
            if (!\Core\Database\Schema\Schema::hasTable($safeTable)) {
                return $this->schemaCheckCache[$cacheKey] = false;
            }

            foreach ($columns as $column) {
                $safeColumn = $this->safeColumn((string) $column);
                if (!\Core\Database\Schema\Schema::hasColumn($safeTable, $safeColumn)) {
                    return $this->schemaCheckCache[$cacheKey] = false;
                }
            }
        } catch (\Throwable $e) {
            return $this->schemaCheckCache[$cacheKey] = false;
        }

        return $this->schemaCheckCache[$cacheKey] = true;
    }

    private function isFutureOrNull(mixed $dateTimeValue): bool
    {
        if ($dateTimeValue === null || $dateTimeValue === '') {
            return true;
        }

        $expiresAt = strtotime((string) $dateTimeValue);
        if ($expiresAt === false) {
            return false;
        }

        return $expiresAt > time();
    }

    private function validateSessionUserAccess(int $userId): bool
    {
        if ($userId < 1) {
            return false;
        }

        $policy = (array) ($this->config['systems_login_policy'] ?? []);
        if (($policy['enforce_user_status'] ?? true) !== true) {
            return true;
        }

        $uc = (array) ($this->config['user_columns'] ?? []);
        $usersTable = $this->safeTable((string) ($this->config['users_table'] ?? 'users'));
        $idColumn = $this->safeColumn((string) ($uc['id'] ?? 'id'), 'id');
        $statusColumn = $this->safeColumn(
            (string) ($policy['user_status_column'] ?? ($uc['status'] ?? 'user_status')),
            'user_status'
        );

        if ($this->sessionUserCache !== null && isset($this->sessionUserCache[$idColumn])) {
            return $this->isUserStatusAllowed($this->sessionUserCache);
        }

        if (!$this->tableAndColumnsExist($usersTable, [$idColumn, $statusColumn])) {
            return true;
        }

        try {
            $user = \db()->table($usersTable)
                ->select(implode(', ', [$idColumn, $statusColumn]))
                ->where($idColumn, $userId)
                ->safeOutput()
                ->fetch();
        } catch (\Throwable $e) {
            return true;
        }

        if (empty($user) || !$this->isUserStatusAllowed($user)) {
            $this->logout(true);
            return false;
        }

        return true;
    }

    private function validateSessionFingerprint(): bool
    {
        $security = (array) ($this->config['session_security'] ?? []);
        if (($security['enabled'] ?? true) !== true) {
            return true;
        }

        $fingerprintKey = (string) ($security['fingerprint_key'] ?? '_auth_fp');
        $stored = \getSession($fingerprintKey);
        if (!is_string($stored) || $stored === '') {
            $this->logout(true);
            return false;
        }

        $current = $this->clientFingerprint();
        if (!hash_equals($stored, $current)) {
            $this->logout(true);
            return false;
        }

        return true;
    }

    private function clientFingerprint(): string
    {
        $security = (array) ($this->config['session_security'] ?? []);

        $parts = ['v1'];
        if (($security['bind_user_agent'] ?? true) === true) {
            $userAgent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
            $userAgentMode = strtolower(trim((string) ($security['user_agent_mode'] ?? 'strict')));
            if ($userAgentMode !== 'strict') {
                $userAgent = $this->normalizeUserAgentForFingerprint($userAgent, $userAgentMode);
            }

            $parts[] = $userAgent;
        }

        if (($security['bind_ip'] ?? false) === true) {
            $parts[] = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        }

        return hash('sha256', implode('|', $parts));
    }

    private function normalizeUserAgentForFingerprint(string $userAgent, string $mode = 'normalized'): string
    {
        $ua = strtolower($userAgent);

        $platform = match (true) {
            str_contains($ua, 'windows') => 'windows',
            str_contains($ua, 'android') => 'android',
            str_contains($ua, 'iphone') || str_contains($ua, 'ipad') || str_contains($ua, 'ios') => 'ios',
            str_contains($ua, 'mac os') || str_contains($ua, 'macintosh') => 'macos',
            str_contains($ua, 'linux') => 'linux',
            default => 'other',
        };

        $device = (str_contains($ua, 'mobile') || str_contains($ua, 'android') || str_contains($ua, 'iphone')) ? 'mobile' : 'desktop';

        $browser = 'other';
        $major = '0';

        if (preg_match('/edg\/(\d+)/i', $userAgent, $m) === 1) {
            $browser = 'edge';
            $major = (string) $m[1];
        } elseif (preg_match('/chrome\/(\d+)/i', $userAgent, $m) === 1) {
            $browser = 'chrome';
            $major = (string) $m[1];
        } elseif (preg_match('/firefox\/(\d+)/i', $userAgent, $m) === 1) {
            $browser = 'firefox';
            $major = (string) $m[1];
        } elseif (preg_match('/version\/(\d+).+safari/i', $userAgent, $m) === 1) {
            $browser = 'safari';
            $major = (string) $m[1];
        } elseif (preg_match('/safari\/(\d+)/i', $userAgent, $m) === 1) {
            $browser = 'safari';
            $major = (string) $m[1];
        } elseif (preg_match('/(?:opr|opera)\/(\d+)/i', $userAgent, $m) === 1) {
            $browser = 'opera';
            $major = (string) $m[1];
        }

        if ($mode === 'family') {
            return implode('|', ['uaf', $browser, $major]);
        }

        return implode('|', ['uan', $platform, $device, $browser, $major]);
    }

    private function validateSessionConcurrency(int $userId): bool
    {
        $concurrency = (array) ($this->config['session_concurrency'] ?? []);
        if (($concurrency['enabled'] ?? false) !== true || ($concurrency['enforce_on_check'] ?? true) !== true) {
            return true;
        }

        $sid = $this->currentSessionId();
        if ($userId < 1 || $sid === '') {
            return false;
        }

        $registry = $this->getSessionRegistry($userId, $ok);
        if (!$ok) {
            return ($concurrency['fail_open_if_cache_unavailable'] ?? true) === true;
        }

        if (empty($registry)) {
            return ($concurrency['fail_open_if_cache_unavailable'] ?? true) === true;
        }

        if (!isset($registry[$sid]) || !is_array($registry[$sid])) {
            $this->logout(true);
            return false;
        }

        if (($concurrency['store_fingerprint'] ?? true) === true) {
            $storedFp = (string) ($registry[$sid]['fp'] ?? '');
            if ($storedFp === '' || !hash_equals($storedFp, $this->clientFingerprint())) {
                $this->logout(true);
                return false;
            }
        }

        $registry[$sid]['last_seen_at'] = time();
        $this->setSessionRegistry($userId, $registry);

        return true;
    }

    private function registerSessionForUser(int $userId): bool
    {
        $concurrency = (array) ($this->config['session_concurrency'] ?? []);
        if (($concurrency['enabled'] ?? false) !== true) {
            return true;
        }

        $sid = $this->currentSessionId();
        if ($userId < 1 || $sid === '') {
            return false;
        }

        $registry = $this->getSessionRegistry($userId, $ok);
        if (!$ok) {
            return ($concurrency['fail_open_if_cache_unavailable'] ?? true) === true;
        }

        $maxDevices = max(0, (int) ($concurrency['max_devices'] ?? 0));
        $now = time();

        if (!isset($registry[$sid]) || !is_array($registry[$sid])) {
            if ($maxDevices > 0 && count($registry) >= $maxDevices) {
                $denyNewLogin = ($concurrency['deny_new_login_when_limit_reached'] ?? false) === true;

                if ($denyNewLogin) {
                    return false;
                }

                $invalidateOldest = ($concurrency['invalidate_oldest'] ?? true) === true;
                if ($invalidateOldest) {
                    uasort($registry, static function ($a, $b) {
                        $aSeen = (int) ($a['last_seen_at'] ?? 0);
                        $bSeen = (int) ($b['last_seen_at'] ?? 0);
                        return $aSeen <=> $bSeen;
                    });

                    while ($maxDevices > 0 && count($registry) >= $maxDevices) {
                        $oldestSid = array_key_first($registry);
                        if ($oldestSid === null) {
                            break;
                        }

                        unset($registry[$oldestSid]);
                    }
                }
            }
        }

        $registry[$sid] = [
            'sid' => $sid,
            'fp' => ($concurrency['store_fingerprint'] ?? true) === true ? $this->clientFingerprint() : '',
            'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            'ua' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512),
            'issued_at' => isset($registry[$sid]['issued_at']) ? (int) $registry[$sid]['issued_at'] : $now,
            'last_seen_at' => $now,
        ];

        return $this->setSessionRegistry($userId, $registry);
    }

    private function unregisterSessionForUser(int $userId, string $sid): void
    {
        if ($userId < 1 || $sid === '') {
            return;
        }

        $concurrency = (array) ($this->config['session_concurrency'] ?? []);
        if (($concurrency['enabled'] ?? false) !== true) {
            return;
        }

        $registry = $this->getSessionRegistry($userId, $ok);
        if (!$ok || empty($registry)) {
            return;
        }

        unset($registry[$sid]);
        $this->setSessionRegistry($userId, $registry);
    }

    private function getSessionRegistry(int $userId, ?bool &$ok = null): array
    {
        $ok = false;
        $key = $this->sessionRegistryCacheKey($userId);
        if ($key === null || !function_exists('cache')) {
            return [];
        }

        try {
            $raw = cache()->get($key, []);
            $ok = true;

            if (!is_array($raw)) {
                return [];
            }

            $ttl = max(60, (int) (($this->config['session_concurrency']['ttl'] ?? 2592000)));
            $cutoff = time() - $ttl;
            $clean = [];

            foreach ($raw as $sid => $entry) {
                if (!is_string($sid) || $sid === '' || !is_array($entry)) {
                    continue;
                }

                $lastSeenAt = (int) ($entry['last_seen_at'] ?? 0);
                if ($lastSeenAt > 0 && $lastSeenAt < $cutoff) {
                    continue;
                }

                $clean[$sid] = $entry;
            }

            return $clean;
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function setSessionRegistry(int $userId, array $registry): bool
    {
        $key = $this->sessionRegistryCacheKey($userId);
        if ($key === null || !function_exists('cache')) {
            return false;
        }

        try {
            if (empty($registry)) {
                return cache()->forget($key);
            }

            $ttl = max(60, (int) (($this->config['session_concurrency']['ttl'] ?? 2592000)));
            return cache()->put($key, $registry, $ttl);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function sessionRegistryCacheKey(int $userId): ?string
    {
        if ($userId < 1) {
            return null;
        }

        $prefix = trim((string) ($this->config['session_concurrency']['cache_key_prefix'] ?? 'auth_sessions_'));
        if ($prefix === '') {
            $prefix = 'auth_sessions_';
        }

        return $prefix . $userId;
    }

    private function currentSessionId(): string
    {
        $sid = session_id();
        return is_string($sid) ? trim($sid) : '';
    }

    private function normalizeStringList(array $items): array
    {
        $normalized = [];
        foreach ($items as $item) {
            $value = trim((string) $item);
            if ($value !== '') {
                $normalized[] = $value;
            }
        }

        return array_values(array_unique($normalized));
    }

    private function toScopeList(mixed $rawScopes): array
    {
        if (is_array($rawScopes)) {
            return $this->normalizeStringList($rawScopes);
        }

        if (!is_string($rawScopes)) {
            return [];
        }

        $rawScopes = trim($rawScopes);
        if ($rawScopes === '') {
            return [];
        }

        if ($rawScopes[0] === '[') {
            $decoded = json_decode($rawScopes, true);
            if (is_array($decoded)) {
                return $this->normalizeStringList($decoded);
            }
        }

        if (str_contains($rawScopes, ',')) {
            return $this->normalizeStringList(array_map('trim', explode(',', $rawScopes)));
        }

        return $this->normalizeStringList(preg_split('/\s+/', $rawScopes) ?: []);
    }

    private function collectRequestAbilities(): array
    {
        $abilities = [];
        foreach (['token', 'api_key', 'jwt', 'oauth2'] as $method) {
            $user = $this->userByMethod($method);
            if (empty($user)) {
                continue;
            }

            $abilities = array_merge($abilities, $this->abilitiesFromUser($user));
        }

        return $this->normalizeStringList($abilities);
    }

    private function abilitiesFromUser(array $user): array
    {
        $abilities = $user['abilities'] ?? [];
        if (is_string($abilities)) {
            $abilities = $this->toScopeList($abilities);
        }

        if (!is_array($abilities) && isset($user['jwt_claims']['scope']) && is_string($user['jwt_claims']['scope'])) {
            $abilities = $this->toScopeList($user['jwt_claims']['scope']);
        }

        if (!is_array($abilities) && isset($user['jwt_claims']['scopes']) && is_array($user['jwt_claims']['scopes'])) {
            $abilities = $user['jwt_claims']['scopes'];
        }

        if (!is_array($abilities) && isset($user['oauth2_scopes'])) {
            $abilities = $this->toScopeList($user['oauth2_scopes']);
        }

        if (!is_array($abilities)) {
            $abilities = [];
        }

        return $this->normalizeStringList($abilities);
    }

    private function resolveRoleId(int|string $role): int
    {
        if (is_int($role) && $role > 0) {
            return $role;
        }

        $roleName = trim((string) $role);
        if ($roleName === '') {
            return 0;
        }

        if (ctype_digit($roleName)) {
            return (int) $roleName;
        }

        $rbac = (array) ($this->config['rbac'] ?? []);
        $tables = (array) ($rbac['tables'] ?? []);
        $roleCols = (array) ($rbac['role_columns'] ?? []);

        $rolesTable = $this->safeTable((string) ($tables['roles'] ?? 'master_roles'));
        $roleIdColumn = $this->safeColumn((string) ($roleCols['id'] ?? 'id'));
        $roleNameColumn = $this->safeColumn((string) ($roleCols['name'] ?? 'role_name'), 'role_name');

        $row = \db()->table($rolesTable)
            ->select($roleIdColumn)
            ->where($roleNameColumn, $roleName)
            ->safeOutput()
            ->fetch();

        return (int) ($row[$roleIdColumn] ?? 0);
    }

    private function resolveAbilityIds(array|string $permissions): array
    {
        $values = is_array($permissions)
            ? $permissions
            : array_map('trim', explode(',', (string) $permissions));

        $values = $this->normalizeStringList(array_map('strval', $values));
        if (empty($values)) {
            return [];
        }

        $ids = [];
        $names = [];
        foreach ($values as $value) {
            if (ctype_digit($value)) {
                $ids[] = (int) $value;
            } else {
                $names[] = $value;
            }
        }

        $rbac = (array) ($this->config['rbac'] ?? []);
        $tables = (array) ($rbac['tables'] ?? []);
        $abilityCols = (array) ($rbac['ability_columns'] ?? []);

        $abilitiesTable = $this->safeTable((string) ($tables['abilities'] ?? 'system_abilities'));
        $abilityIdColumn = $this->safeColumn((string) ($abilityCols['id'] ?? 'id'));
        $abilitySlugColumn = $this->safeColumn((string) ($abilityCols['slug'] ?? 'abilities_slug'), 'abilities_slug');

        if (!empty($names)) {
            $rows = \db()->table($abilitiesTable)
                ->select($abilityIdColumn)
                ->whereIn($abilitySlugColumn, $names)
                ->safeOutput()
                ->get();

            if (is_array($rows)) {
                foreach ($rows as $row) {
                    $abilityId = (int) ($row[$abilityIdColumn] ?? 0);
                    if ($abilityId > 0) {
                        $ids[] = $abilityId;
                    }
                }
            }
        }

        return array_values(array_unique(array_filter($ids, fn($id) => $id > 0)));
    }
}