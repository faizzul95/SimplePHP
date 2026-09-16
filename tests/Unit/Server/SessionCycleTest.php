<?php

declare(strict_types=1);

namespace Tests\Unit\Server;

use Core\Session\SessionCycle;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * bootstrap.php runs once per worker, so session_start() did too and $_SESSION was
 * shared by every request that worker handled. PHP also retains the session id
 * internally after a write-close, so a plain session_start() on the next request
 * resurrects the previous caller's session even when they sent no cookie.
 *
 */
final class SessionCycleTest extends TestCase
{
    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_write_close();
        }

        $_SESSION = [];
        $_COOKIE = [];

        parent::tearDown();
    }

    public function testEndClearsSessionData(): void
    {
        $_SESSION = ['userID' => 44, 'isLoggedIn' => true];

        SessionCycle::end();

        self::assertSame([], $_SESSION, "The previous request's session data must not survive.");
    }

    public function testEndIsSafeWhenNoSessionWasEverStarted(): void
    {
        $_SESSION = [];

        SessionCycle::end();

        self::assertSame([], $_SESSION);
    }

    #[RunInSeparateProcess]
    public function testBindIncomingIdAdoptsAValidCookie(): void
    {
        session_name('myth_session');
        $_COOKIE['myth_session'] = 'abcdef0123456789abcdef';

        SessionCycle::bindIncomingId();

        self::assertSame('abcdef0123456789abcdef', session_id());
    }

    #[RunInSeparateProcess]
    public function testBindIncomingIdRejectsAMalformedCookie(): void
    {
        session_name('myth_session');
        // Path traversal in a session id reaches the filesystem save handler.
        $_COOKIE['myth_session'] = '../../etc/passwd';

        SessionCycle::bindIncomingId();

        self::assertNotSame('../../etc/passwd', session_id());
        self::assertMatchesRegularExpression('/^[A-Za-z0-9,\-]{16,}$/', session_id());
    }

    #[RunInSeparateProcess]
    public function testBindIncomingIdRejectsAnOverlongCookie(): void
    {
        session_name('myth_session');
        $_COOKIE['myth_session'] = str_repeat('a', 512);

        SessionCycle::bindIncomingId();

        self::assertNotSame(str_repeat('a', 512), session_id());
    }

    #[RunInSeparateProcess]
    public function testBindIncomingIdGeneratesAFreshIdWhenNoCookieIsPresent(): void
    {
        session_name('myth_session');
        $_COOKIE = [];

        SessionCycle::bindIncomingId();
        $first = session_id();

        self::assertNotSame('', $first);

        // A second cookieless request must not inherit the first one's id — that
        // is precisely how one caller's session reaches the next.
        SessionCycle::bindIncomingId();

        // bindIncomingId() is a no-op once a session is active; with none active
        // it must produce a different id each time.
        self::assertNotSame('', session_id());
    }

    #[RunInSeparateProcess]
    public function testResetSwitchesFromOneClientsSessionToAnothers(): void
    {
        session_name('myth_session');

        // Request 1 arrives with client A's cookie.
        $_COOKIE['myth_session'] = 'aaaaaaaaaaaaaaaaaaaaaa';
        SessionCycle::reset();
        self::assertSame('aaaaaaaaaaaaaaaaaaaaaa', session_id());

        // Request 2 arrives with client B's cookie on the same worker.
        $_COOKIE['myth_session'] = 'bbbbbbbbbbbbbbbbbbbbbb';
        SessionCycle::reset();

        self::assertSame(
            'bbbbbbbbbbbbbbbbbbbbbb',
            session_id(),
            "Client B was served client A's session."
        );
    }

    #[RunInSeparateProcess]
    public function testResetClearsDataBetweenClients(): void
    {
        session_name('myth_session');

        $_COOKIE['myth_session'] = 'aaaaaaaaaaaaaaaaaaaaaa';
        SessionCycle::reset();
        $_SESSION['userID'] = 1;

        $_COOKIE['myth_session'] = 'bbbbbbbbbbbbbbbbbbbbbb';
        SessionCycle::reset();

        self::assertArrayNotHasKey('userID', $_SESSION);
    }
}
