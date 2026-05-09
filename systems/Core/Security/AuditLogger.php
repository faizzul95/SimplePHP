<?php

declare(strict_types=1);

namespace Core\Security;

/**
 * Security Audit Logger — dual-write (DB + append-only flat file).
 *
 * DB write is best-effort. A DB failure NEVER suppresses the file write.
 * File path: storage/logs/audit.log (one JSON object per line, NDJSON format).
 *
 */
final class AuditLogger
{
    // ── Event type constants ────────────────────────────────────────────────
    public const E_LOGIN_SUCCESS     = 'auth.login.success';
    public const E_LOGIN_FAILED      = 'auth.login.failed';
    public const E_LOGOUT            = 'auth.logout';
    public const E_PASSWORD_CHANGED  = 'auth.password.changed';
    public const E_PASSWORD_RESET    = 'auth.password.reset';
    public const E_TOKEN_REVOKED     = 'auth.token.revoked';
    public const E_PERMISSION_DENIED = 'authz.permission.denied';
    public const E_IDOR_ATTEMPT      = 'security.idor.attempt';
    public const E_CSRF_FAILURE      = 'security.csrf.failure';
    public const E_RATE_LIMITED      = 'security.rate_limited';
    public const E_BRUTE_FORCE       = 'security.brute_force';
    public const E_PWNED_PASSWORD    = 'security.pwned_password';
    public const E_SUSPICIOUS_INPUT  = 'security.suspicious_input';
    public const E_FILE_UPLOAD       = 'file.upload';
    public const E_FILE_DOWNLOAD     = 'file.download';
    public const E_SLOW_QUERY        = 'db.slow_query';
    public const E_PROFILE_SWITCH    = 'rbac.profile.switched';
    public const E_ROLE_GRANTED      = 'rbac.role.granted';
    public const E_ROLE_REVOKED      = 'rbac.role.revoked';
    public const E_ADMIN_ACTION      = 'admin.action';
    public const E_DATA_EXPORT       = 'data.export';
    public const E_SESSION_FIXATION  = 'security.session_fixation';

    private const LOG_FILE = ROOT_DIR . 'storage/logs/audit.log';

    /** Per-request ID — reset by WorkerState::flush() between Octane cycles. */
    private static string $requestId = '';

    /** Reset per-request state (called by WorkerState::flush()). */
    public static function reset(): void
    {
        self::$requestId = '';
    }

    /**
     * Dual-write: structured DB row + append-only flat file.
     * DB write is best-effort — a DB failure never suppresses the file write.
     *
     * @param string      $eventType   One of the E_* constants
     * @param array       $context     Free-form key-value pairs (never include raw passwords or PII)
     * @param string      $severity    'info' | 'warning' | 'error' | 'critical'
     * @param string|null $resourceType  e.g. 'user', 'order', 'file'
     * @param int|null    $resourceId
     * @param int|null    $ownerId
     * @param bool        $blocked
     * @param string|null $blockReason
     */
    public static function log(
        string  $eventType,
        array   $context      = [],
        string  $severity     = 'info',
        ?string $resourceType = null,
        ?int    $resourceId   = null,
        ?int    $ownerId      = null,
        bool    $blocked      = false,
        ?string $blockReason  = null,
    ): void {
        $userId    = $_SESSION['user_id'] ?? null;
        $ip        = self::resolveIp();
        $requestId = self::getRequestId();

        // 1. Write to security_audit_log table (best-effort — never throws)
        try {
            db()->table('security_audit_log')->insert([
                'user_id'         => $userId,
                'ip_address'      => $ip,
                'user_agent'      => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 512),
                'request_id'      => $requestId,
                'event_type'      => $eventType,
                'severity'        => $severity,
                'resource_type'   => $resourceType,
                'resource_id'     => $resourceId,
                'owner_id'        => $ownerId,
                'is_idor_suspect' => ($eventType === self::E_IDOR_ATTEMPT) ? 1 : 0,
                'endpoint'        => substr($_SERVER['REQUEST_URI'] ?? '', 0, 512),
                'http_method'     => $_SERVER['REQUEST_METHOD'] ?? null,
                'context'         => json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'blocked'         => $blocked ? 1 : 0,
                'block_reason'    => $blockReason,
                'occurred_at'     => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable) {
            // DB unavailable — flat file is the fallback source of truth
        }

        // 2. Always write to flat file (atomic append — FILE_APPEND + LOCK_EX)
        $entry = json_encode([
            'ts'          => date('c'),
            'level'       => $severity,
            'event'       => $eventType,
            'user_id'     => $userId,
            'ip'          => $ip,
            'request_id'  => $requestId,
            'resource'    => $resourceType ? "{$resourceType}:{$resourceId}" : null,
            'blocked'     => $blocked,
            'block_reason'=> $blockReason,
            'context'     => $context,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $dir = dirname(self::LOG_FILE);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        @file_put_contents(self::LOG_FILE, $entry . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    // ── Semantic helpers ────────────────────────────────────────────────────

    public static function loginSuccess(int $userId): void
    {
        self::log(self::E_LOGIN_SUCCESS, ['user_id' => $userId]);
    }

    public static function loginFailed(string $email): void
    {
        // Hash the email — never store plaintext PII in audit logs
        self::log(self::E_LOGIN_FAILED, ['email_hash' => hash('sha256', strtolower($email))], 'warning');
    }

    public static function logout(int $userId): void
    {
        self::log(self::E_LOGOUT, ['user_id' => $userId]);
    }

    public static function passwordChanged(int $userId): void
    {
        self::log(self::E_PASSWORD_CHANGED, ['user_id' => $userId], 'warning');
    }

    public static function permissionDenied(string $permission, ?int $userId = null): void
    {
        self::log(
            self::E_PERMISSION_DENIED,
            ['permission' => $permission, 'user_id' => $userId],
            'warning'
        );
    }

    public static function idor(int $userId, string $resource, int $resourceId, int $ownerId): void
    {
        self::log(
            self::E_IDOR_ATTEMPT,
            ['attempted_by' => $userId],
            'error',
            $resource,
            $resourceId,
            $ownerId,
            true,
            'Resource owned by different user'
        );
    }

    public static function csrfFailure(string $route): void
    {
        self::log(
            self::E_CSRF_FAILURE,
            ['route' => $route],
            'error',
            blocked: true,
            blockReason: 'CSRF token mismatch'
        );
    }

    public static function bruteForce(string $identifier, int $attempts): void
    {
        self::log(
            self::E_BRUTE_FORCE,
            ['identifier_hash' => hash('sha256', $identifier), 'attempts' => $attempts],
            'critical',
            blocked: true,
            blockReason: 'Rate limit exceeded'
        );
    }

    public static function pwnedPassword(int $userId, string $context = ''): void
    {
        self::log(self::E_PWNED_PASSWORD, ['user_id' => $userId, 'context' => $context], 'critical');
    }

    public static function fileUploaded(string $path, string $originalName): void
    {
        self::log(self::E_FILE_UPLOAD, [
            'stored_path'   => $path,
            'original_name' => $originalName,
        ]);
    }

    public static function suspiciousInput(string $field, string $reason): void
    {
        self::log(
            self::E_SUSPICIOUS_INPUT,
            ['field' => $field, 'reason' => $reason],
            'error'
        );
    }

    public static function dataExport(int $userId, string $model, int $rowCount): void
    {
        self::log(
            self::E_DATA_EXPORT,
            ['model' => $model, 'row_count' => $rowCount, 'user_id' => $userId],
            'warning'
        );
    }

    public static function adminAction(int $adminId, string $action, array $context = []): void
    {
        self::log(
            self::E_ADMIN_ACTION,
            array_merge(['admin_id' => $adminId, 'action' => $action], $context),
            'warning'
        );
    }

    // ── Infrastructure ──────────────────────────────────────────────────────

    /**
     * Resolve the real client IP, honouring trusted-proxy X-Forwarded-For.
     * Public so RateLimiter can reuse it.
     */
    public static function resolveIp(): string
    {
        $trustedProxies = config('security.trusted.proxies', []);
        $remoteAddr     = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        if (!empty($trustedProxies) && in_array($remoteAddr, (array) $trustedProxies, true)) {
            $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
            $ips       = array_map('trim', explode(',', $forwarded));
            $candidate = filter_var($ips[0], FILTER_VALIDATE_IP);
            return $candidate !== false ? $candidate : $remoteAddr;
        }

        return $remoteAddr;
    }

    private static function getRequestId(): string
    {
        if (self::$requestId === '') {
            self::$requestId = $_SERVER['HTTP_X_REQUEST_ID'] ?? bin2hex(random_bytes(8));
        }
        return self::$requestId;
    }
}
