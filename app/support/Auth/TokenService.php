<?php

namespace App\Support\Auth;

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
        $selectColumns = implode(', ', [$tokenIdColumn, $tokenUserIdColumn, $tokenNameColumn, $tokenAbilitiesColumn, $tokenExpiresAtColumn]);

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

        $this->touchTokenRecord($tokenTable, $tokenIdColumn, $tokenRecord[$tokenIdColumn] ?? null, [
            $tokenLastUsedAtColumn => $this->now(),
            $tokenUpdatedAtColumn => $this->now(),
        ]);

        $abilities = json_decode((string) ($tokenRecord[$tokenAbilitiesColumn] ?? '[]'), true);

        return array_merge($user, [
            'auth_type' => 'token',
            'token_id' => (int) ($tokenRecord[$tokenIdColumn] ?? 0),
            'token_name' => $tokenRecord[$tokenNameColumn] ?? null,
            'abilities' => is_array($abilities) ? $abilities : [],
            'expires_at' => $tokenRecord[$tokenExpiresAtColumn] ?? null,
        ]);
    }

    public function createToken(int $userId, string $name, ?int $expiresAt, array $abilities, callable $safeColumn, callable $safeTable): ?string
    {
        if ($userId < 1) {
            return null;
        }

        $this->ensureTokenTable($safeTable);

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

    protected function ensureTokenTable(callable $safeTable): void
    {
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