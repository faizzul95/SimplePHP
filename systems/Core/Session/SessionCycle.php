<?php

declare(strict_types=1);

namespace Core\Session;

/**
 * Per-request session boundaries for long-running workers.
 *
 * Under php-fpm the session lifecycle is free: the process ends, PHP writes and
 * closes the session, and the next request starts clean. A worker process has no
 * such boundary — bootstrap.php runs once, session_start() runs once, and $_SESSION
 * is then shared by every request that worker ever handles. That is a cross-user
 * data leak, not a performance nit.
 *
 * PHP also retains the session id internally after session_write_close(), so a
 * plain session_start() on the next request resurrects the previous caller's
 * session even when they sent no cookie. bindIncomingId() is what prevents that.
 */
final class SessionCycle
{
    /**
     * A PHP session id: alphanumeric plus the two characters allowed by
     * session.sid_bits_per_character=6, bounded so a hostile cookie cannot be used
     * as an unbounded key.
     */
    private const ID_PATTERN = '/^[A-Za-z0-9,\-]{16,256}$/';

    /**
     * Close the current session and forget it.
     *
     * Call at the end of every worker cycle, and at the start as a safety net in
     * case the previous cycle threw before reaching its own cleanup.
     */
    public static function end(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            // Writes the data and releases the lock. Without this the file or
            // Redis lock is held for the whole worker lifetime, not the request.
            @session_write_close();
        }

        $_SESSION = [];
    }

    /**
     * Point PHP at the session id this request actually presented.
     *
     * With a valid incoming cookie the id is adopted. Without one a fresh id is
     * generated, which is the important half: it stops session_start() from
     * silently reusing the id left behind by the previous request on this worker.
     */
    public static function bindIncomingId(): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }

        $name = session_name();
        $incoming = is_string($name) && $name !== '' ? (string) ($_COOKIE[$name] ?? '') : '';

        if ($incoming !== '' && preg_match(self::ID_PATTERN, $incoming) === 1) {
            @session_id($incoming);
            return;
        }

        @session_id(self::freshId());
    }

    /**
     * Reset the session for a new request cycle: close whatever is open, then bind
     * the incoming id so the next session_start() resolves the right session.
     */
    public static function reset(): void
    {
        self::end();
        self::bindIncomingId();
    }

    private static function freshId(): string
    {
        if (function_exists('session_create_id')) {
            $generated = @session_create_id();

            if (is_string($generated) && $generated !== '') {
                return $generated;
            }
        }

        return bin2hex(random_bytes(24));
    }
}
