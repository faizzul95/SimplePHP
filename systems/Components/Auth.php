<?php

namespace Components;

class Auth
{
    private array $config;
    private ?array $tokenUserCache = null;
    private ?array $sessionUserCache = null;

    /**
     * Fallback defaults — override via app/config/auth.php
     */
    private const DEFAULTS = [
        'session_flag'      => 'isLoggedIn',
        'session_user_id'   => 'userID',
        'users_table'       => 'users',
        'token_table'       => 'users_access_tokens',
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
            'social_provider'    => 'social_provider',
            'social_provider_id' => 'social_provider_id',
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

        $this->config = array_merge($defaults, $config);
    }

    // ─── Authentication State ────────────────────────────────

    public function check(): bool
    {
        return $this->checkSession() || $this->checkToken();
    }

    public function guest(): bool
    {
        return !$this->check();
    }

    public function via(): ?string
    {
        if ($this->checkSession()) {
            return 'session';
        }

        if ($this->checkToken()) {
            return 'token';
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

    public function id(): ?int
    {
        if ($this->checkSession()) {
            return (int) \getSession($this->config['session_user_id']);
        }

        $tokenUser = $this->tokenUser();
        if (!empty($tokenUser['id'])) {
            return (int) $tokenUser['id'];
        }

        return null;
    }

    public function user(): ?array
    {
        if ($this->checkSession()) {
            return $this->sessionUser();
        }

        return $this->tokenUser();
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

    public function hasAbility(string $ability): bool
    {
        $tokenUser = $this->tokenUser();
        if (empty($tokenUser)) {
            return false;
        }

        $abilities = $tokenUser['abilities'] ?? [];
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
            $this->login($userId);

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

            $this->login($userId);

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

        $this->login($userId);

        return ['code' => 200, 'message' => 'Account created and logged in', 'user_id' => $userId];
    }

    // ─── Request Helpers ─────────────────────────────────────

    public function bearerToken(): ?string
    {
        $authHeader = \request()->header('Authorization');
        if (!is_string($authHeader) || empty($authHeader)) {
            return null;
        }

        if (!preg_match('/Bearer\s+(.+)$/i', $authHeader, $matches)) {
            return null;
        }

        $token = trim($matches[1]);
        return $token !== '' ? $token : null;
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
}