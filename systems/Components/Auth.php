<?php

namespace Components;

class Auth
{
    private array $config;
    private ?array $tokenUserCache = null;
    private ?array $sessionUserCache = null;
    private ?array $jwtUserCache = null;
    private ?array $apiKeyUserCache = null;
    private ?array $basicUserCache = null;
    private ?array $digestUserCache = null;
    private ?array $oauthUserCache = null;
    private array $digestNcMemory = [];

    /**
     * Fallback defaults — override via app/config/auth.php
     */
    private const DEFAULTS = [
        'session_flag'      => 'isLoggedIn',
        'session_user_id'   => 'userID',
        'methods'           => ['session', 'token'],
        'users_table'       => 'users',
        'token_table'       => 'users_access_tokens',
        'api_key_table'     => 'users_api_keys',
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
        foreach (['session_keys', 'token_columns', 'user_columns'] as $nestedKey) {
            if (isset($config[$nestedKey]) && is_array($config[$nestedKey])) {
                $config[$nestedKey] = array_merge($defaults[$nestedKey], $config[$nestedKey]);
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
        return (bool) \getSession($this->config['session_flag']) && !empty(\getSession($this->config['session_user_id']));
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
        $password = $credentials['password'] ?? '';
        unset($credentials['password']);

        if (empty($credentials) || empty($password)) {
            return false;
        }

        $uc = $this->config['user_columns'];
        $query = \db()->table($this->safeTable($this->config['users_table']));

        foreach ($credentials as $column => $value) {
            $safeColumn = preg_replace('/[^a-zA-Z0-9_]/', '', $column);
            $query->where($safeColumn, $value);
        }

        $user = $query->safeOutput()->fetch();

        $passwordCol = $uc['password'] ?? 'password';
        if (empty($user) || !password_verify((string) $password, (string) ($user[$passwordCol] ?? ''))) {
            return false;
        }

        // Strip the password hash from the returned user — callers should never need it
        unset($user[$passwordCol]);

        return $user;
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

        // Regenerate session ID to prevent fixation attacks
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        $keys = $this->config['session_keys'];

        // Build base session data with configurable keys
        $baseSession = [
            $this->config['session_user_id'] => $userId,
            $this->config['session_flag'] => true,
        ];

        // Merge user-provided session data
        foreach ($sessionData as $key => $value) {
            $baseSession[$key] = $value;
        }

        \startSession($baseSession);

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
            $uc['id'], $uc['name'], $uc['preferred_name'], $uc['email'], $uc['username']
        ]);

        $user = \db()->table($this->safeTable($this->config['users_table']))
            ->select($selectCols)
            ->where($uc['id'], $userId)
            ->safeOutput()
            ->fetch();

        if (empty($user)) {
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

        $selectCols = implode(', ', [
            $tc['id'], $tc['user_id'], $tc['name'], $tc['abilities'], $tc['expires_at']
        ]);

        $tokenRecord = \db()->table($tokenTable)
            ->select($selectCols)
            ->where($tc['token'], $hashedToken)
            ->whereRaw("({$tc['expires_at']} IS NULL OR {$tc['expires_at']} > NOW())")
            ->safeOutput()
            ->fetch();

        if (empty($tokenRecord)) {
            return null;
        }

        // Update last_used_at timestamp
        \db()->table($tokenTable)
            ->where($tc['id'], $tokenRecord[$tc['id']])
            ->update([
                $tc['last_used_at'] => \timestamp(),
                $tc['updated_at']   => \timestamp(),
            ]);

        $userSelectCols = implode(', ', [
            $uc['id'], $uc['name'], $uc['preferred_name'], $uc['email'], $uc['username']
        ]);

        $user = \db()->table($this->safeTable($this->config['users_table']))
            ->select($userSelectCols)
            ->where($uc['id'], (int) $tokenRecord[$tc['user_id']])
            ->safeOutput()
            ->fetch();

        if (empty($user)) {
            return null;
        }

        $abilities = json_decode($tokenRecord[$tc['abilities']] ?? '[]', true);

        $this->tokenUserCache = array_merge($user, [
            'auth_type'  => 'token',
            'token_id'   => (int) $tokenRecord[$tc['id']],
            'token_name' => $tokenRecord[$tc['name']],
            'abilities'  => is_array($abilities) ? $abilities : [],
            'expires_at' => $tokenRecord[$tc['expires_at']],
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
            $uc['id'], $uc['name'], $uc['preferred_name'], $uc['email'], $uc['username']
        ]);

        $user = \db()->table($this->safeTable($this->config['users_table']))
            ->select($userSelectCols)
            ->where($uc['id'], $userId)
            ->safeOutput()
            ->fetch();

        if (empty($user)) {
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

        $table = $this->safeTable((string) ($this->config['api_key_table'] ?? 'users_api_keys'));
        $hashedApiKey = hash('sha256', $apiKey);

        $selectCols = implode(', ', [
            $columns['id'],
            $columns['user_id'],
            $columns['name'],
            $columns['abilities'],
            $columns['expires_at'],
        ]);

        $keyRecord = \db()->table($table)
            ->select($selectCols)
            ->where($columns['api_key'], $hashedApiKey)
            ->where($columns['is_active'], 1)
            ->whereRaw("({$columns['expires_at']} IS NULL OR {$columns['expires_at']} > NOW())")
            ->safeOutput()
            ->fetch();

        if (empty($keyRecord)) {
            return null;
        }

        \db()->table($table)
            ->where($columns['id'], $keyRecord[$columns['id']])
            ->update([
                $columns['last_used_at'] => \timestamp(),
                $columns['updated_at'] => \timestamp(),
            ]);

        $uc = $this->config['user_columns'];
        $userSelectCols = implode(', ', [
            $uc['id'], $uc['name'], $uc['preferred_name'], $uc['email'], $uc['username']
        ]);

        $user = \db()->table($this->safeTable($this->config['users_table']))
            ->select($userSelectCols)
            ->where($uc['id'], (int) $keyRecord[$columns['user_id']])
            ->safeOutput()
            ->fetch();

        if (empty($user)) {
            return null;
        }

        $abilities = json_decode($keyRecord[$columns['abilities']] ?? '[]', true);

        $this->apiKeyUserCache = array_merge($user, [
            'auth_type' => 'api_key',
            'api_key_id' => (int) $keyRecord[$columns['id']],
            'api_key_name' => $keyRecord[$columns['name']] ?? null,
            'abilities' => is_array($abilities) ? $abilities : [],
            'expires_at' => $keyRecord[$columns['expires_at']] ?? null,
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

            if (!password_verify($password, (string) ($user[$passwordCol] ?? ''))) {
                continue;
            }

            unset($user[$passwordCol]);
            $this->basicUserCache = array_merge($user, ['auth_type' => 'basic']);
            return $this->basicUserCache;
        }

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
            return null;
        }

        $ha1 = (string) ($user[$safeHa1Column] ?? '');
        if ($ha1 === '') {
            return null;
        }

        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $ha2 = md5($method . ':' . (string) $digest['uri']);
        $validResponse = md5($ha1 . ':' . $digest['nonce'] . ':' . $digest['nc'] . ':' . $digest['cnonce'] . ':' . $digest['qop'] . ':' . $ha2);

        if (!hash_equals($validResponse, (string) ($digest['response'] ?? ''))) {
            return null;
        }

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
        $user = $this->user(['token', 'api_key', 'jwt']);
        if (empty($user)) {
            return false;
        }

        $abilities = $user['abilities'] ?? [];
        if (!is_array($abilities) && isset($user['jwt_claims']['scope']) && is_string($user['jwt_claims']['scope'])) {
            $abilities = preg_split('/\s+/', trim($user['jwt_claims']['scope'])) ?: [];
        }

        if (!is_array($abilities) && isset($user['jwt_claims']['scopes']) && is_array($user['jwt_claims']['scopes'])) {
            $abilities = $user['jwt_claims']['scopes'];
        }

        if (!is_array($abilities)) {
            $abilities = [];
        }

        if (in_array('*', $abilities, true)) {
            return true;
        }

        return in_array($ability, $abilities, true);
    }

    // ─── Token Management ────────────────────────────────────

    public function createToken(int $userId, string $name = 'Default Token', ?int $expiresAt = null, array $abilities = ['*']): ?string
    {
        if ($userId < 1) {
            return null;
        }

        $this->ensureTokenTable();

        $tc = $this->config['token_columns'];
        $plainToken = bin2hex(random_bytes(40));
        $hashedToken = hash('sha256', $plainToken);
        $expiresAtDate = $expiresAt ? date('Y-m-d H:i:s', $expiresAt) : null;
        $tokenTable = $this->safeTable($this->config['token_table']);

        \db()->table($tokenTable)->insert([
            $tc['user_id']    => $userId,
            $tc['name']       => $name,
            $tc['token']      => $hashedToken,
            $tc['abilities']  => json_encode($abilities),
            $tc['expires_at'] => $expiresAtDate,
            $tc['created_at'] => \timestamp(),
            $tc['updated_at'] => \timestamp(),
        ]);

        return $plainToken;
    }

    public function revokeToken(string $plainToken): bool
    {
        if (empty($plainToken)) {
            return false;
        }

        $tc = $this->config['token_columns'];
        $tokenTable = $this->safeTable($this->config['token_table']);
        $hashedToken = hash('sha256', $plainToken);

        \db()->table($tokenTable)
            ->where($tc['token'], $hashedToken)
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
        $tokenTable = $this->safeTable($this->config['token_table']);

        \db()->table($tokenTable)
            ->where($tc['user_id'], $userId)
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

        $plainApiKey = bin2hex(random_bytes(32));
        $hashedApiKey = hash('sha256', $plainApiKey);
        $expiresAtDate = $expiresAt ? date('Y-m-d H:i:s', $expiresAt) : null;
        $table = $this->safeTable((string) ($this->config['api_key_table'] ?? 'users_api_keys'));

        \db()->table($table)->insert([
            $columns['user_id'] => $userId,
            $columns['name'] => $name,
            $columns['api_key'] => $hashedApiKey,
            $columns['abilities'] => json_encode($abilities),
            $columns['is_active'] => 1,
            $columns['expires_at'] => $expiresAtDate,
            $columns['created_at'] => \timestamp(),
            $columns['updated_at'] => \timestamp(),
        ]);

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

        $table = $this->safeTable((string) ($this->config['api_key_table'] ?? 'users_api_keys'));
        $hashedApiKey = hash('sha256', $plainApiKey);

        \db()->table($table)
            ->where($columns['api_key'], $hashedApiKey)
            ->update([
                $columns['is_active'] => 0,
                $columns['updated_at'] => \timestamp(),
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

    // ─── Logout ──────────────────────────────────────────────

    /**
     * Log the user out. Uses configurable session keys — no hardcoded values.
     */
    public function logout(bool $destroySession = false): void
    {
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

        if (!preg_match('/Bearer\s+(.+)$/i', $authHeader, $matches)) {
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

        if ($rawMethods === null) {
            $rawMethods = $this->config['methods'] ?? ['session', 'token'];
        }

        if (is_string($rawMethods)) {
            $rawMethods = str_contains($rawMethods, ',')
                ? array_map('trim', explode(',', $rawMethods))
                : [trim($rawMethods)];
        }

        if (!is_array($rawMethods) || empty($rawMethods)) {
            $rawMethods = ['session', 'token'];
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
            return ['session', 'token'];
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
}