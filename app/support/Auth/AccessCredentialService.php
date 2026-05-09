<?php

namespace App\Support\Auth;

class AccessCredentialService
{
    public function __construct(private array $config = [])
    {
    }

    public function jwtUser(
        callable $bearerToken,
        callable $decodeJwt,
        callable $findConfiguredUserRecord,
        callable $isUserStatusAllowed
    ): ?array {
        $jwtConfig = (array) ($this->config['jwt'] ?? []);
        if (($jwtConfig['enabled'] ?? false) !== true) {
            return null;
        }

        $bearer = $bearerToken();
        if (!is_string($bearer) || $bearer === '') {
            return null;
        }

        $payload = $decodeJwt($bearer);
        if (!is_array($payload) || $payload === []) {
            return null;
        }

        $claimName = (string) ($jwtConfig['user_id_claim'] ?? 'sub');
        $userId = (int) ($payload[$claimName] ?? 0);
        if ($userId < 1) {
            return null;
        }

        $user = $this->findAccessUser($userId, $findConfiguredUserRecord);
        if ($user === null || !$isUserStatusAllowed($user)) {
            return null;
        }

        return array_merge($user, [
            'auth_type' => 'jwt',
            'jwt_claims' => $payload,
        ]);
    }

    public function basicUser(
        callable $extractBasicCredentials,
        callable $canAttemptWithLoginPolicy,
        callable $policyCredentialsForIdentifier,
        callable $findUserByIdentifierColumn,
        callable $isUserStatusAllowed,
        callable $registerLoginFailure,
        callable $clearLoginFailures
    ): ?array {
        $basicConfig = (array) ($this->config['basic'] ?? []);
        if (($basicConfig['enabled'] ?? false) !== true) {
            return null;
        }

        [$identifier, $password] = $extractBasicCredentials();
        if (!is_string($identifier) || $identifier === '' || !is_string($password) || $password === '') {
            return null;
        }

        $uc = (array) ($this->config['user_columns'] ?? []);
        $identifierColumns = (array) ($basicConfig['identifier_columns'] ?? ['username', 'email']);
        $passwordColumn = (string) ($uc['password'] ?? 'password');
        $userIdColumn = (string) ($uc['id'] ?? 'id');
        $policyCredentials = $policyCredentialsForIdentifier($identifier, $identifierColumns);

        if (!$canAttemptWithLoginPolicy($policyCredentials)) {
            return null;
        }

        $matchedUserId = 0;

        foreach ($identifierColumns as $columnAlias) {
            $columnAlias = (string) $columnAlias;
            $column = $uc[$columnAlias] ?? $columnAlias;
            $safeColumn = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $column);
            if (!is_string($safeColumn) || $safeColumn === '') {
                continue;
            }

            $user = $findUserByIdentifierColumn($safeColumn, $identifier);
            if (!is_array($user) || $user === []) {
                \Core\Security\Hasher::dummyVerify($password);
                continue;
            }

            $matchedUserId = max($matchedUserId, (int) ($user[$userIdColumn] ?? 0));

            if (!\Core\Security\Hasher::verify($password, (string) ($user[$passwordColumn] ?? ''))) {
                continue;
            }

            // SEC-02: transparent rehash — upgrade bcrypt → Argon2id on next successful login
            if (\Core\Security\Hasher::needsRehash((string) ($user[$passwordColumn] ?? ''))) {
                try {
                    db()->table($uc['table'] ?? 'users')
                        ->where($userIdColumn, $user[$userIdColumn])
                        ->update([$passwordColumn => \Core\Security\Hasher::make($password)]);
                } catch (\Throwable) {
                    // Non-critical — don't break the login flow
                }
            }

            if (!$isUserStatusAllowed($user)) {
                $registerLoginFailure($policyCredentials, $matchedUserId);
                return null;
            }

            $clearLoginFailures($policyCredentials, $matchedUserId);
            unset($user[$passwordColumn]);

            return array_merge($user, ['auth_type' => 'basic']);
        }

        $registerLoginFailure($policyCredentials, $matchedUserId > 0 ? $matchedUserId : null);

        return null;
    }

    public function digestUser(
        callable $extractDigestCredentials,
        callable $canAttemptWithLoginPolicy,
        callable $policyCredentialsForIdentifier,
        callable $isDigestNonceValid,
        callable $isDigestRequestUriValid,
        callable $isDigestNonceCounterValid,
        callable $findDigestUserByUsername,
        callable $isUserStatusAllowed,
        callable $registerLoginFailure,
        callable $clearLoginFailures,
        callable $requestMethod
    ): ?array {
        $digestConfig = (array) ($this->config['digest'] ?? []);
        if (($digestConfig['enabled'] ?? false) !== true) {
            return null;
        }

        $digest = $extractDigestCredentials();
        if (!is_array($digest) || empty($digest['username'])) {
            return null;
        }

        $username = (string) ($digest['username'] ?? '');
        $policyCredentials = $policyCredentialsForIdentifier($username, ['username']);
        if (!$canAttemptWithLoginPolicy($policyCredentials)) {
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

        $nonce = (string) ($digest['nonce'] ?? '');
        $uri = (string) ($digest['uri'] ?? '');
        $nonceCounter = (string) ($digest['nc'] ?? '');
        $cnonce = (string) ($digest['cnonce'] ?? '');
        $response = (string) ($digest['response'] ?? '');
        if ($cnonce === '' || $response === '') {
            return null;
        }

        if (!$isDigestNonceValid($nonce)) {
            return null;
        }

        if (!$isDigestRequestUriValid($uri)) {
            return null;
        }

        if (!$isDigestNonceCounterValid($username, $nonce, $nonceCounter)) {
            return null;
        }

        $uc = (array) ($this->config['user_columns'] ?? []);
        $usernameColumn = (string) ($digestConfig['username_column'] ?? ($uc['username'] ?? 'username'));
        $ha1Column = (string) ($digestConfig['ha1_column'] ?? ($uc['digest_ha1'] ?? 'digest_ha1'));
        $safeUsernameColumn = preg_replace('/[^a-zA-Z0-9_]/', '', $usernameColumn);
        $safeHa1Column = preg_replace('/[^a-zA-Z0-9_]/', '', $ha1Column);

        if (!is_string($safeUsernameColumn) || $safeUsernameColumn === '' || !is_string($safeHa1Column) || $safeHa1Column === '') {
            return null;
        }

        $user = $findDigestUserByUsername($safeUsernameColumn, $username);
        if (!is_array($user) || $user === []) {
            $registerLoginFailure($policyCredentials, null);
            return null;
        }

        $userIdColumn = (string) ($uc['id'] ?? 'id');
        $userId = (int) ($user[$userIdColumn] ?? 0);
        $ha1 = (string) ($user[$safeHa1Column] ?? '');
        if ($ha1 === '') {
            $registerLoginFailure($policyCredentials, $userId);
            return null;
        }

        $method = strtoupper(trim((string) $requestMethod()));
        if ($method === '') {
            $method = 'GET';
        }

        $ha2 = md5($method . ':' . $uri);
        $validResponse = md5($ha1 . ':' . $nonce . ':' . $nonceCounter . ':' . $cnonce . ':' . (string) $digest['qop'] . ':' . $ha2);
        if (!hash_equals($validResponse, $response)) {
            $registerLoginFailure($policyCredentials, $userId);
            return null;
        }

        if (!$isUserStatusAllowed($user)) {
            $registerLoginFailure($policyCredentials, $userId);
            return null;
        }

        $clearLoginFailures($policyCredentials, $userId);
        unset($user[$safeHa1Column]);

        return array_merge($user, ['auth_type' => 'digest']);
    }

    public function oauth2User(
        callable $bearerToken,
        callable $safeColumn,
        callable $safeTable,
        callable $findOAuth2TokenRecord,
        callable $touchOAuth2TokenRecord,
        callable $findConfiguredUserRecord,
        callable $isFutureOrNull,
        callable $isUserStatusAllowed,
        callable $toScopeList,
        callable $currentTimestamp
    ): ?array {
        $oauth2Config = (array) ($this->config['oauth2'] ?? []);
        if (($oauth2Config['enabled'] ?? false) !== true) {
            return null;
        }

        $plainToken = $bearerToken();
        if (!is_string($plainToken) || $plainToken === '') {
            return null;
        }

        $columns = (array) ($oauth2Config['columns'] ?? []);
        if ($columns === []) {
            return null;
        }

        $oauth2IdColumn = $safeColumn((string) ($columns['id'] ?? 'id'));
        $oauth2UserIdColumn = $safeColumn((string) ($columns['user_id'] ?? 'user_id'));
        $oauth2NameColumn = $safeColumn((string) ($columns['name'] ?? 'name'));
        $oauth2TokenColumn = $safeColumn((string) ($columns['token'] ?? 'token'));
        $oauth2ScopesColumn = $safeColumn((string) ($columns['scopes'] ?? 'scopes'));
        $oauth2RevokedColumn = $safeColumn((string) ($columns['revoked'] ?? 'revoked'));
        $oauth2ExpiresAtColumn = $safeColumn((string) ($columns['expires_at'] ?? 'expires_at'));
        $oauth2LastUsedAtColumn = $safeColumn((string) ($columns['last_used_at'] ?? 'last_used_at'));
        $oauth2UpdatedAtColumn = $safeColumn((string) ($columns['updated_at'] ?? 'updated_at'));

        $table = $safeTable((string) ($this->config['oauth2_table'] ?? 'oauth2_access_tokens'));
        $hashTokens = ($oauth2Config['hash_tokens'] ?? true) === true;
        $tokenLookup = $hashTokens ? hash('sha256', $plainToken) : $plainToken;
        $selectColumns = implode(', ', [
            $oauth2IdColumn,
            $oauth2UserIdColumn,
            $oauth2NameColumn,
            $oauth2ScopesColumn,
            $oauth2RevokedColumn,
            $oauth2ExpiresAtColumn,
        ]);

        $tokenRecord = $findOAuth2TokenRecord($table, $oauth2TokenColumn, $tokenLookup, $selectColumns);
        if (!is_array($tokenRecord) || $tokenRecord === []) {
            return null;
        }

        if (!empty($tokenRecord[$oauth2RevokedColumn])) {
            return null;
        }

        if (!$isFutureOrNull($tokenRecord[$oauth2ExpiresAtColumn] ?? null)) {
            return null;
        }

        $user = $this->findAccessUser((int) ($tokenRecord[$oauth2UserIdColumn] ?? 0), $findConfiguredUserRecord);
        if ($user === null || !$isUserStatusAllowed($user)) {
            return null;
        }

        $accessedAt = $currentTimestamp();
        $touchOAuth2TokenRecord($table, $oauth2IdColumn, $tokenRecord[$oauth2IdColumn] ?? null, [
            $oauth2LastUsedAtColumn => $accessedAt,
            $oauth2UpdatedAtColumn => $accessedAt,
        ]);

        $scopes = $toScopeList($tokenRecord[$oauth2ScopesColumn] ?? []);

        return array_merge($user, [
            'auth_type' => 'oauth2',
            'oauth2_token_id' => $tokenRecord[$oauth2IdColumn] ?? null,
            'oauth2_token_name' => $tokenRecord[$oauth2NameColumn] ?? null,
            'oauth2_scopes' => $scopes,
            'abilities' => $scopes,
            'expires_at' => $tokenRecord[$oauth2ExpiresAtColumn] ?? null,
        ]);
    }

    public function apiKeyUser(
        callable $extractApiKey,
        callable $safeColumn,
        callable $safeTable,
        callable $findApiKeyRecord,
        callable $touchApiKeyRecord,
        callable $findConfiguredUserRecord,
        callable $isFutureOrNull,
        callable $isUserStatusAllowed,
        callable $currentTimestamp
    ): ?array {
        $apiKeyConfig = (array) ($this->config['api_key'] ?? []);
        if (($apiKeyConfig['enabled'] ?? false) !== true) {
            return null;
        }

        $apiKey = $extractApiKey();
        if (!is_string($apiKey) || $apiKey === '') {
            return null;
        }

        $columns = (array) ($apiKeyConfig['columns'] ?? []);
        if ($columns === []) {
            return null;
        }

        $apiKeyIdColumn = $safeColumn((string) ($columns['id'] ?? 'id'));
        $apiKeyUserIdColumn = $safeColumn((string) ($columns['user_id'] ?? 'user_id'));
        $apiKeyNameColumn = $safeColumn((string) ($columns['name'] ?? 'name'));
        $apiKeyValueColumn = $safeColumn((string) ($columns['api_key'] ?? 'api_key'));
        $apiKeyAbilitiesColumn = $safeColumn((string) ($columns['abilities'] ?? 'abilities'));
        $apiKeyIsActiveColumn = $safeColumn((string) ($columns['is_active'] ?? 'is_active'));
        $apiKeyExpiresAtColumn = $safeColumn((string) ($columns['expires_at'] ?? 'expires_at'));
        $apiKeyLastUsedAtColumn = $safeColumn((string) ($columns['last_used_at'] ?? 'last_used_at'));
        $apiKeyUpdatedAtColumn = $safeColumn((string) ($columns['updated_at'] ?? 'updated_at'));

        $table = $safeTable((string) ($this->config['api_key_table'] ?? 'users_api_keys'));
        $hashedApiKey = hash('sha256', $apiKey);
        $selectColumns = implode(', ', [
            $apiKeyIdColumn,
            $apiKeyUserIdColumn,
            $apiKeyNameColumn,
            $apiKeyAbilitiesColumn,
            $apiKeyExpiresAtColumn,
        ]);

        $keyRecord = $findApiKeyRecord($table, $apiKeyValueColumn, $hashedApiKey, $apiKeyIsActiveColumn, $selectColumns);
        if (!is_array($keyRecord) || $keyRecord === []) {
            return null;
        }

        if (!$isFutureOrNull($keyRecord[$apiKeyExpiresAtColumn] ?? null)) {
            return null;
        }

        $user = $this->findAccessUser((int) ($keyRecord[$apiKeyUserIdColumn] ?? 0), $findConfiguredUserRecord);
        if ($user === null || !$isUserStatusAllowed($user)) {
            return null;
        }

        $accessedAt = $currentTimestamp();
        $touchApiKeyRecord($table, $apiKeyIdColumn, $keyRecord[$apiKeyIdColumn] ?? null, [
            $apiKeyLastUsedAtColumn => $accessedAt,
            $apiKeyUpdatedAtColumn => $accessedAt,
        ]);

        $abilities = json_decode((string) ($keyRecord[$apiKeyAbilitiesColumn] ?? '[]'), true);

        return array_merge($user, [
            'auth_type' => 'api_key',
            'api_key_id' => (int) ($keyRecord[$apiKeyIdColumn] ?? 0),
            'api_key_name' => $keyRecord[$apiKeyNameColumn] ?? null,
            'abilities' => is_array($abilities) ? $abilities : [],
            'expires_at' => $keyRecord[$apiKeyExpiresAtColumn] ?? null,
        ]);
    }

    private function findAccessUser(int $userId, callable $findConfiguredUserRecord): ?array
    {
        if ($userId < 1) {
            return null;
        }

        $uc = (array) ($this->config['user_columns'] ?? []);
        $userSelectColumns = implode(', ', [
            (string) ($uc['id'] ?? 'id'),
            (string) ($uc['name'] ?? 'name'),
            (string) ($uc['preferred_name'] ?? 'user_preferred_name'),
            (string) ($uc['email'] ?? 'email'),
            (string) ($uc['username'] ?? 'username'),
            (string) ($uc['status'] ?? 'user_status'),
        ]);

        $user = $findConfiguredUserRecord($userId, $userSelectColumns);

        return is_array($user) && $user !== [] ? $user : null;
    }
}