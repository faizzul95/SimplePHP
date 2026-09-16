<?php

namespace Core\Auth;

class TokenService
{
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    public function tokenUser(
        callable $bearerToken,
        callable $safeColumn,
        callable $safeTable,
        callable $isFutureOrNull,
        callable $isUserStatusAllowed
    ): ?array {
        $plainToken = $bearerToken();
        if (!is_string($plainToken) || $plainToken === '') {
            return null;
        }

        $tc = (array) ($this->config['token_columns'] ?? []);
        $uc = (array) ($this->config['user_columns'] ?? []);
        $tokenTable = $safeTable((string) ($this->config['token_table'] ?? 'users_access_tokens'));

        $tokenIdColumn = $safeColumn((string) ($tc['id'] ?? 'id'));
        $tokenUserIdColumn = $safeColumn((string) ($tc['user_id'] ?? 'user_id'));
        $tokenNameColumn = $safeColumn((string) ($tc['name'] ?? 'name'));
        $tokenAbilitiesColumn = $safeColumn((string) ($tc['abilities'] ?? 'abilities'));
        $tokenExpiresAtColumn = $safeColumn((string) ($tc['expires_at'] ?? 'expires_at'));
        $tokenTokenColumn = $safeColumn((string) ($tc['token'] ?? 'token'));
        $tokenLastUsedAtColumn = $safeColumn((string) ($tc['last_used_at'] ?? 'last_used_at'));
        $tokenUpdatedAtColumn = $safeColumn((string) ($tc['updated_at'] ?? 'updated_at'));
        // last_used_at is selected so touchLastUsedAt() can decide whether the write
        // is actually needed, rather than issuing one on every authenticated request.
        $selectColumns = implode(', ', [
            $tokenIdColumn,
            $tokenUserIdColumn,
            $tokenNameColumn,
            $tokenAbilitiesColumn,
            $tokenExpiresAtColumn,
            $tokenLastUsedAtColumn,
        ]);

        $tokenRecord = null;
        $parsedToken = $this->parsePublicToken($plainToken);
        if ($parsedToken !== null) {
            $tokenRecord = $this->findTokenRecordByIdAndHash(
                $tokenTable,
                $tokenIdColumn,
                $tokenTokenColumn,
                $parsedToken['id'],
                hash('sha256', $parsedToken['secret']),
                $selectColumns
            );
        }

        if (empty($tokenRecord)) {
            $tokenRecord = $this->findTokenRecord(
                $tokenTable,
                $tokenTokenColumn,
                hash('sha256', $plainToken),
                $selectColumns
            );
        }

        if (empty($tokenRecord)) {
            return null;
        }

        if (!$isFutureOrNull($tokenRecord[$tokenExpiresAtColumn] ?? null)) {
            return null;
        }

        $user = $this->findUserRecord(
            $safeTable((string) ($this->config['users_table'] ?? 'users')),
            $safeColumn((string) ($uc['id'] ?? 'id'), 'id'),
            (int) ($tokenRecord[$tokenUserIdColumn] ?? 0),
            implode(', ', [
                (string) ($uc['id'] ?? 'id'),
                (string) ($uc['name'] ?? 'name'),
                (string) ($uc['preferred_name'] ?? 'user_preferred_name'),
                (string) ($uc['email'] ?? 'email'),
                (string) ($uc['username'] ?? 'username'),
                (string) ($uc['status'] ?? 'user_status'),
            ])
        );

        if (empty($user) || !$isUserStatusAllowed($user)) {
            return null;
        }

        $this->touchLastUsedAt(
            $tokenTable,
            $tokenIdColumn,
            $tokenRecord[$tokenIdColumn] ?? null,
            $tokenLastUsedAtColumn,
            $tokenUpdatedAtColumn,
            $tokenRecord[$tokenLastUsedAtColumn] ?? null
        );

        $abilities = json_decode((string) ($tokenRecord[$tokenAbilitiesColumn] ?? '[]'), true);

        return array_merge($user, [
            'auth_type' => 'token',
            'token_id' => (int) ($tokenRecord[$tokenIdColumn] ?? 0),
            'token_name' => $tokenRecord[$tokenNameColumn] ?? null,
            'abilities' => is_array($abilities) ? $abilities : [],
            'expires_at' => $tokenRecord[$tokenExpiresAtColumn] ?? null,
        ]);
    }

    /**
     * Issue a personal access token.
     *
     * @throws \RuntimeException When the active-token limit is reached and the
     *                           policy is to deny rather than revoke.
     */
    public function createToken(int $userId, string $name, ?int $expiresAt, array $abilities, callable $safeColumn, callable $safeTable): ?string
    {
        if ($userId < 1) {
            return null;
        }

        $this->ensureTokenTable($safeTable);

        // Session concurrency (auth.session_concurrency) caps how many *browsers*
        // a user may be signed in from, but said nothing about tokens — so
        // "single-device login" held for the web and not for the mobile API,
        // where a user could accumulate unlimited tokens. This applies the same
        // policy to the token surface.
        $this->enforceActiveTokenLimit($userId, $safeColumn, $safeTable);

        $tc = (array) ($this->config['token_columns'] ?? []);
        $plainToken = $this->generatePlainToken();
        $hashedToken = hash('sha256', $plainToken);

        $insert = $this->insertTokenRecord(
            $safeTable((string) ($this->config['token_table'] ?? 'users_access_tokens')),
            [
                $safeColumn((string) ($tc['user_id'] ?? 'user_id')) => $userId,
                $safeColumn((string) ($tc['name'] ?? 'name')) => $name,
                $safeColumn((string) ($tc['token'] ?? 'token')) => $hashedToken,
                $safeColumn((string) ($tc['abilities'] ?? 'abilities')) => json_encode($abilities),
                $safeColumn((string) ($tc['expires_at'] ?? 'expires_at')) => $expiresAt ? date('Y-m-d H:i:s', $expiresAt) : null,
                $safeColumn((string) ($tc['created_at'] ?? 'created_at')) => $this->now(),
                $safeColumn((string) ($tc['updated_at'] ?? 'updated_at')) => $this->now(),
            ]
        );

        if (!$this->isWriteSuccess($insert, false)) {
            return null;
        }

        return $this->formatPublicToken((int) ($insert['id'] ?? 0), $plainToken);
    }

    public function revokeToken(string $plainToken, callable $safeColumn, callable $safeTable): bool
    {
        if ($plainToken === '') {
            return false;
        }

        $tc = (array) ($this->config['token_columns'] ?? []);
        $tokenTable = $safeTable((string) ($this->config['token_table'] ?? 'users_access_tokens'));
        $tokenIdColumn = $safeColumn((string) ($tc['id'] ?? 'id'));
        $tokenTokenColumn = $safeColumn((string) ($tc['token'] ?? 'token'));

        $result = null;
        $parsedToken = $this->parsePublicToken($plainToken);
        if ($parsedToken !== null) {
            $result = $this->deleteTokenRecordByIdAndHash(
                $tokenTable,
                $tokenIdColumn,
                $tokenTokenColumn,
                $parsedToken['id'],
                hash('sha256', $parsedToken['secret'])
            );
        }

        if (!$this->isWriteSuccess($result, true)) {
            $result = $this->deleteTokenRecord(
                $tokenTable,
                $tokenTokenColumn,
                hash('sha256', $plainToken)
            );
        }

        return $this->isWriteSuccess($result, true);
    }

    public function revokeAllTokens(int $userId, callable $safeColumn, callable $safeTable): bool
    {
        if ($userId < 1) {
            return false;
        }

        $tc = (array) ($this->config['token_columns'] ?? []);
        $this->deleteTokensForUser(
            $safeTable((string) ($this->config['token_table'] ?? 'users_access_tokens')),
            $safeColumn((string) ($tc['user_id'] ?? 'user_id')),
            $userId
        );

        return true;
    }

    public function tokensForUser(int $userId, callable $safeColumn, callable $safeTable): array
    {
        if ($userId < 1) {
            return [];
        }

        $tc = (array) ($this->config['token_columns'] ?? []);
        $tokenTable = $safeTable((string) ($this->config['token_table'] ?? 'users_access_tokens'));
        $tokenIdColumn = $safeColumn((string) ($tc['id'] ?? 'id'));
        $tokenUserIdColumn = $safeColumn((string) ($tc['user_id'] ?? 'user_id'));
        $tokenNameColumn = $safeColumn((string) ($tc['name'] ?? 'name'));
        $tokenAbilitiesColumn = $safeColumn((string) ($tc['abilities'] ?? 'abilities'));
        $tokenExpiresAtColumn = $safeColumn((string) ($tc['expires_at'] ?? 'expires_at'));
        $tokenLastUsedAtColumn = $safeColumn((string) ($tc['last_used_at'] ?? 'last_used_at'));
        $tokenCreatedAtColumn = $safeColumn((string) ($tc['created_at'] ?? 'created_at'));

        $rows = $this->findTokensForUserRecord(
            $tokenTable,
            $tokenUserIdColumn,
            $userId,
            implode(', ', [
                $tokenIdColumn,
                $tokenNameColumn,
                $tokenAbilitiesColumn,
                $tokenExpiresAtColumn,
                $tokenLastUsedAtColumn,
                $tokenCreatedAtColumn,
            ]),
            $tokenCreatedAtColumn
        );

        $tokens = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $abilities = json_decode((string) ($row[$tokenAbilitiesColumn] ?? '[]'), true);
            $tokens[] = [
                'id' => (int) ($row[$tokenIdColumn] ?? 0),
                'name' => $row[$tokenNameColumn] ?? null,
                'abilities' => is_array($abilities) ? $abilities : [],
                'expires_at' => $row[$tokenExpiresAtColumn] ?? null,
                'last_used_at' => $row[$tokenLastUsedAtColumn] ?? null,
                'created_at' => $row[$tokenCreatedAtColumn] ?? null,
            ];
        }

        return $tokens;
    }

    public function currentToken(string $plainToken, callable $safeColumn, callable $safeTable): ?array
    {
        if ($plainToken === '') {
            return null;
        }

        $tc = (array) ($this->config['token_columns'] ?? []);
        $tokenTable = $safeTable((string) ($this->config['token_table'] ?? 'users_access_tokens'));
        $tokenIdColumn = $safeColumn((string) ($tc['id'] ?? 'id'));
        $tokenUserIdColumn = $safeColumn((string) ($tc['user_id'] ?? 'user_id'));
        $tokenNameColumn = $safeColumn((string) ($tc['name'] ?? 'name'));
        $tokenAbilitiesColumn = $safeColumn((string) ($tc['abilities'] ?? 'abilities'));
        $tokenExpiresAtColumn = $safeColumn((string) ($tc['expires_at'] ?? 'expires_at'));
        $tokenLastUsedAtColumn = $safeColumn((string) ($tc['last_used_at'] ?? 'last_used_at'));
        $tokenCreatedAtColumn = $safeColumn((string) ($tc['created_at'] ?? 'created_at'));
        $tokenTokenColumn = $safeColumn((string) ($tc['token'] ?? 'token'));
        $selectColumns = implode(', ', [
            $tokenIdColumn,
            $tokenUserIdColumn,
            $tokenNameColumn,
            $tokenAbilitiesColumn,
            $tokenExpiresAtColumn,
            $tokenLastUsedAtColumn,
            $tokenCreatedAtColumn,
        ]);

        $record = null;
        $parsedToken = $this->parsePublicToken($plainToken);
        if ($parsedToken !== null) {
            $record = $this->findTokenRecordByIdAndHash(
                $tokenTable,
                $tokenIdColumn,
                $tokenTokenColumn,
                $parsedToken['id'],
                hash('sha256', $parsedToken['secret']),
                $selectColumns
            );
        }

        if (empty($record)) {
            $record = $this->findTokenRecord(
                $tokenTable,
                $tokenTokenColumn,
                hash('sha256', $plainToken),
                $selectColumns
            );
        }

        if (empty($record)) {
            return null;
        }

        $abilities = json_decode((string) ($record[$tokenAbilitiesColumn] ?? '[]'), true);

        return [
            'id' => (int) ($record[$tokenIdColumn] ?? 0),
            'user_id' => (int) ($record[$tokenUserIdColumn] ?? 0),
            'name' => $record[$tokenNameColumn] ?? null,
            'abilities' => is_array($abilities) ? $abilities : [],
            'expires_at' => $record[$tokenExpiresAtColumn] ?? null,
            'last_used_at' => $record[$tokenLastUsedAtColumn] ?? null,
            'created_at' => $record[$tokenCreatedAtColumn] ?? null,
        ];
    }

    public function rotateToken(string $plainToken, string $name, ?int $expiresAt, array $abilities, callable $safeColumn, callable $safeTable): ?string
    {
        $current = $this->currentToken($plainToken, $safeColumn, $safeTable);
        if ($current === null) {
            return null;
        }

        $replacementName = trim($name) !== '' ? $name : (string) ($current['name'] ?? 'Default Token');
        $replacementExpiresAt = $expiresAt;
        if ($replacementExpiresAt === null) {
            $existingExpiresAt = trim((string) ($current['expires_at'] ?? ''));
            $replacementExpiresAt = $existingExpiresAt !== '' ? (strtotime($existingExpiresAt) ?: null) : null;
        }

        $replacementAbilities = $abilities !== [] ? $abilities : (array) ($current['abilities'] ?? ['*']);
        $replacement = $this->createToken((int) ($current['user_id'] ?? 0), $replacementName, $replacementExpiresAt, $replacementAbilities, $safeColumn, $safeTable);
        if ($replacement === null) {
            return null;
        }

        if ($this->revokeToken($plainToken, $safeColumn, $safeTable)) {
            return $replacement;
        }

        $this->revokeToken($replacement, $safeColumn, $safeTable);

        return null;
    }

    /**
     * Refresh last_used_at only when it is stale enough to be worth a write.
     *
     * This used to fire unconditionally on every authenticated request, which
     * turned every token-authenticated GET into a write: a row lock per active
     * token per request, and — because the builder marks the connection sticky
     * after a write — the rest of the request pinned to the primary instead of a
     * read replica. The write scaled with request volume rather than with how
     * often anyone actually reads "last used".
     *
     * Precision comes from auth.token.last_used_precision (seconds, default 60).
     * Set it to 0 to restore the write-every-time behaviour.
     */
    protected function touchLastUsedAt(
        string $tokenTable,
        string $tokenIdColumn,
        mixed $tokenId,
        string $lastUsedAtColumn,
        string $updatedAtColumn,
        mixed $currentLastUsedAt
    ): void {
        if ($tokenId === null) {
            return;
        }

        $precision = $this->lastUsedPrecision();

        if ($precision > 0 && $this->isFresh($currentLastUsedAt, $precision)) {
            return;
        }

        $now = $this->now();

        $this->touchTokenRecord($tokenTable, $tokenIdColumn, $tokenId, [
            $lastUsedAtColumn => $now,
            $updatedAtColumn => $now,
        ]);
    }

    protected function lastUsedPrecision(): int
    {
        $configured = $this->config['token']['last_used_precision'] ?? null;

        if ($configured === null && function_exists('config')) {
            $configured = \config('auth.token.last_used_precision', 60);
        }

        return max(0, (int) ($configured ?? 60));
    }

    /** Whether $timestamp is recent enough that re-writing it buys nothing. */
    protected function isFresh(mixed $timestamp, int $precision): bool
    {
        if (!is_string($timestamp) || trim($timestamp) === '') {
            return false;
        }

        $parsed = strtotime($timestamp);

        if ($parsed === false) {
            return false;
        }

        // A clock skew or a future value should not pin the column forever.
        $age = time() - $parsed;

        return $age >= 0 && $age < $precision;
    }

    /**
     * Keep a user's active tokens within `auth.token.max_active_per_user`.
     *
     *   0 (default) — unlimited, the historical behaviour
     *   1           — single-device: issuing a new token retires the previous one
     *   N           — at most N concurrent tokens
     *
     * `auth.token.on_limit` chooses what happens at the cap:
     *   'revoke_oldest' (default) — the least recently used token is deleted, so
     *                               logging in on a new phone signs the old one out
     *   'deny'                    — the new login is refused
     *
     * Oldest is measured by last use, not by creation: the device someone actually
     * stopped using is the one to retire, which is not necessarily the one they
     * registered first.
     */
    protected function enforceActiveTokenLimit(int $userId, callable $safeColumn, callable $safeTable): void
    {
        $limit = $this->maxActiveTokensPerUser();

        if ($limit < 1) {
            return;
        }

        $tc = (array) ($this->config['token_columns'] ?? []);
        $tokenTable = $safeTable((string) ($this->config['token_table'] ?? 'users_access_tokens'));
        $idColumn = $safeColumn((string) ($tc['id'] ?? 'id'));
        $userIdColumn = $safeColumn((string) ($tc['user_id'] ?? 'user_id'));
        $lastUsedColumn = $safeColumn((string) ($tc['last_used_at'] ?? 'last_used_at'));
        $createdColumn = $safeColumn((string) ($tc['created_at'] ?? 'created_at'));

        $existing = $this->activeTokensForLimit($tokenTable, $userIdColumn, $userId, $idColumn, $lastUsedColumn, $createdColumn);
        $activeCount = count($existing);

        // The token about to be created takes one slot, so the cap is reached at
        // $limit, not $limit + 1.
        if ($activeCount < $limit) {
            return;
        }

        if ($this->tokenLimitPolicy() === 'deny') {
            throw new \RuntimeException('token_device_limit_reached');
        }

        // Retire enough of the least-recently-used tokens to leave one slot free.
        $surplus = ($activeCount - $limit) + 1;

        foreach (array_slice($existing, 0, $surplus) as $row) {
            $tokenId = (int) ($row[$idColumn] ?? 0);

            if ($tokenId > 0) {
                $this->deleteTokenById($tokenTable, $idColumn, $tokenId);
            }
        }
    }

    protected function maxActiveTokensPerUser(): int
    {
        $configured = $this->config['token']['max_active_per_user'] ?? null;

        if ($configured === null && function_exists('config')) {
            $configured = \config('auth.token.max_active_per_user', 0);
        }

        return max(0, (int) ($configured ?? 0));
    }

    /** @return 'revoke_oldest'|'deny' */
    protected function tokenLimitPolicy(): string
    {
        $configured = $this->config['token']['on_limit'] ?? null;

        if ($configured === null && function_exists('config')) {
            $configured = \config('auth.token.on_limit', 'revoke_oldest');
        }

        return strtolower(trim((string) ($configured ?? 'revoke_oldest'))) === 'deny'
            ? 'deny'
            : 'revoke_oldest';
    }

    /**
     * A user's tokens, least recently used first.
     *
     * @return list<array<string, mixed>>
     */
    protected function activeTokensForLimit(
        string $tokenTable,
        string $userIdColumn,
        int $userId,
        string $idColumn,
        string $lastUsedColumn,
        string $createdColumn
    ): array {
        $rows = \db()->table($tokenTable)
            ->select($idColumn . ', ' . $lastUsedColumn . ', ' . $createdColumn)
            ->where($userIdColumn, $userId)
            // NULL last_used_at means never used, which makes it the best
            // candidate to retire; COALESCE to created_at keeps the order total.
            ->orderByRaw('COALESCE(`' . $lastUsedColumn . '`, `' . $createdColumn . '`) ASC')
            ->get();

        return is_array($rows) ? array_values($rows) : [];
    }

    protected function deleteTokenById(string $tokenTable, string $idColumn, int $tokenId): void
    {
        \db()->table($tokenTable)->where($idColumn, $tokenId)->delete();
    }

    protected function ensureTokenTable(callable $safeTable): void
    {
        if (!$this->autoMigrateEnabled()) {
            return;
        }

        $tc = array_map(static fn($column) => preg_replace('/[^a-zA-Z0-9_]/', '', (string) $column), (array) ($this->config['token_columns'] ?? []));
        $tokenTable = $safeTable((string) ($this->config['token_table'] ?? 'users_access_tokens'));

        $this->runQuery(
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

    /**
     * Whether createToken() should run CREATE TABLE IF NOT EXISTS first.
     *
     * The table is created by 20260308_005_create_users_access_tokens_table.php,
     * so in a migrated database this is a redundant DDL round-trip — and a
     * metadata lock — on every single login. Kept as a convenience for scratch
     * development databases only.
     */
    protected function autoMigrateEnabled(): bool
    {
        $configured = $this->config['token']['auto_migrate'] ?? null;

        if ($configured === null && function_exists('config')) {
            $configured = \config('auth.token.auto_migrate', false);
        }

        return (bool) $configured;
    }

    protected function generatePlainToken(): string
    {
        return bin2hex(random_bytes(40));
    }

    protected function now(): string
    {
        return function_exists('timestamp') ? \timestamp() : date('Y-m-d H:i:s');
    }

    protected function isWriteSuccess(mixed $result, bool $requireAffectedRows): bool
    {
        if (!is_array($result)) {
            return false;
        }

        $code = (int) ($result['code'] ?? 500);
        $success = function_exists('isSuccess') ? \isSuccess($code) : ($code >= 200 && $code < 300);
        if (!$success) {
            return false;
        }

        return !$requireAffectedRows || (int) ($result['affected_rows'] ?? 0) > 0;
    }

    protected function findTokenRecord(string $tokenTable, string $tokenColumn, string $hashedToken, string $selectColumns): ?array
    {
        $record = \db()->table($tokenTable)
            ->select($selectColumns)
            ->where($tokenColumn, $hashedToken)
            ->safeOutput()
            ->fetch();

        return is_array($record) ? $record : null;
    }

    protected function findTokenRecordByIdAndHash(
        string $tokenTable,
        string $tokenIdColumn,
        string $tokenColumn,
        int $tokenId,
        string $hashedToken,
        string $selectColumns
    ): ?array {
        if ($tokenId < 1 || $hashedToken === '') {
            return null;
        }

        $record = \db()->table($tokenTable)
            ->select($selectColumns)
            ->where($tokenIdColumn, $tokenId)
            ->where($tokenColumn, $hashedToken)
            ->safeOutput()
            ->fetch();

        return is_array($record) ? $record : null;
    }

    protected function touchTokenRecord(string $tokenTable, string $tokenIdColumn, mixed $tokenId, array $updates): void
    {
        \db()->table($tokenTable)
            ->where($tokenIdColumn, $tokenId)
            ->update($updates);
    }

    protected function findUserRecord(string $usersTable, string $userIdColumn, int $userId, string $selectColumns): ?array
    {
        $user = \db()->table($usersTable)
            ->select($selectColumns)
            ->where($userIdColumn, $userId)
            ->safeOutput()
            ->fetch();

        return is_array($user) ? $user : null;
    }

    protected function findTokensForUserRecord(string $tokenTable, string $tokenUserIdColumn, int $userId, string $selectColumns, string $orderByColumn): array
    {
        $rows = \db()->table($tokenTable)
            ->select($selectColumns)
            ->where($tokenUserIdColumn, $userId)
            ->orderBy($orderByColumn, 'DESC')
            ->safeOutput()
            ->get();

        return is_array($rows) ? $rows : [];
    }

    protected function insertTokenRecord(string $tokenTable, array $payload): mixed
    {
        return \db()->table($tokenTable)->insert($payload);
    }

    protected function deleteTokenRecord(string $tokenTable, string $tokenColumn, string $hashedToken): mixed
    {
        return \db()->table($tokenTable)
            ->where($tokenColumn, $hashedToken)
            ->delete();
    }

    protected function deleteTokenRecordByIdAndHash(string $tokenTable, string $tokenIdColumn, string $tokenColumn, int $tokenId, string $hashedToken): mixed
    {
        if ($tokenId < 1 || $hashedToken === '') {
            return ['code' => 422, 'affected_rows' => 0];
        }

        return \db()->table($tokenTable)
            ->where($tokenIdColumn, $tokenId)
            ->where($tokenColumn, $hashedToken)
            ->delete();
    }

    protected function deleteTokensForUser(string $tokenTable, string $userIdColumn, int $userId): void
    {
        \db()->table($tokenTable)
            ->where($userIdColumn, $userId)
            ->delete();
    }

    protected function runQuery(string $sql): void
    {
        \db()->query($sql);
    }

    protected function formatPublicToken(int $tokenId, string $plainToken): string
    {
        if ($tokenId < 1 || $plainToken === '') {
            return $plainToken;
        }

        return $tokenId . '|' . $plainToken;
    }

    protected function parsePublicToken(string $plainToken): ?array
    {
        $parts = explode('|', $plainToken, 2);
        if (count($parts) !== 2) {
            return null;
        }

        $tokenId = (int) trim((string) ($parts[0] ?? '0'));
        $secret = trim((string) ($parts[1] ?? ''));
        if ($tokenId < 1 || $secret === '') {
            return null;
        }

        return [
            'id' => $tokenId,
            'secret' => $secret,
        ];
    }
}