<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use Components\CSRF;
use PHPUnit\Framework\TestCase;

/**
 * Double-submit CSRF compares the request against a *cookie*, which assumes an
 * attacker cannot write that cookie. On a shared parent domain the assumption is
 * false: any subdomain — a vendor-hosted status page, a compromised staging box —
 * can set a cookie on the parent and therefore supply both halves of the pair.
 *
 * The expected token now comes from the session, which nothing outside the
 * process can write. The cookie stays as the transport the JS client reads.
 */
final class CsrfSessionBindingTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $serverBackup = [];

    /** @var array<string, mixed> */
    private array $cookieBackup = [];

    /**
     * Whether setUp() opened the session itself.
     *
     * An active session is process-wide state. Leaving one open made
     * AuthSessionLifecycleTest's "session startup fails" case unreachable —
     * green in file order, red under --order-by=random.
     */
    private bool $startedSession = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->serverBackup = $_SERVER;
        $this->cookieBackup = $_COOKIE;

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/account/update';
        $_SERVER['HTTP_HOST'] = 'example.test';
        $_SERVER['HTTP_ORIGIN'] = 'https://example.test';
        $_SERVER['HTTPS'] = 'on';

        /*
        | A real session, because the binding is the thing under test and
        | skipping when there is none would leave the security assertions
        | unexecuted — a green suite proving nothing.
        |
        | CLI has no session by default, so one is started here against a temp
        | save path. Headers are never sent in this context, so session_start()
        | succeeds.
        */
        if (session_status() !== PHP_SESSION_ACTIVE) {
            $savePath = sys_get_temp_dir() . '/myth-csrf-sessions';
            if (!is_dir($savePath)) {
                mkdir($savePath, 0775, true);
            }

            session_save_path($savePath);
            $this->startedSession = @session_start();
        }

        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        // Hand the process back as it was found.
        if ($this->startedSession && session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        $this->startedSession = false;

        $_SERVER = $this->serverBackup;
        $_COOKIE = $this->cookieBackup;
        $_SESSION = [];
        unset($_POST['csrf_token']);

        parent::tearDown();
    }

    /** Fail rather than skip: an unexecuted security assertion proves nothing. */
    private function requireSession(): void
    {
        self::assertSame(
            PHP_SESSION_ACTIVE,
            session_status(),
            'These assertions are about session binding; without a session they would be vacuous.'
        );
    }

    /** @param array<string, mixed> $overrides */
    private function csrf(array $overrides = []): CSRF
    {
        return new CSRF(array_merge([
            'csrf_protection' => true,
            'csrf_token_name' => 'csrf_token',
            'csrf_cookie_name' => 'csrf_cookie',
            'csrf_expire' => 7200,
            'csrf_regenerate' => false,
            'csrf_origin_check' => false,
            'csrf_exclude_uris' => [],
            'csrf_include_uris' => [],
        ], $overrides));
    }

    private function token(): string
    {
        return str_repeat('a1b2', 16);
    }

    /** Record a token the way setTokenCookie() does. */
    private function withSessionToken(string $token): void
    {
        $_SESSION['_csrf_token'] = $token;
        $_SESSION['_csrf_token_time'] = time();
    }

    private function present(string $token): void
    {
        $_POST['csrf_token'] = $token;
        $_COOKIE['csrf_cookie'] = $token;
        $_COOKIE['csrf_cookie_time'] = (string) time();
    }

    // ─── The attack ──────────────────────────────────────────────────

    /**
     * The whole point. The attacker sets both halves from a sibling subdomain;
     * neither matches what the server recorded, so the request is refused.
     */
    public function testAForgedCookieAndFieldPairIsRejectedWhenASessionTokenExists(): void
    {
        $this->requireSession();

        $this->withSessionToken($this->token());

        // Attacker-chosen value, consistent across both halves.
        $forged = str_repeat('dead', 16);
        $this->present($forged);

        self::assertFalse($this->csrf()->validate('/account/update'));
    }

    public function testTheRealTokenStillPasses(): void
    {
        $this->requireSession();

        $this->withSessionToken($this->token());
        $this->present($this->token());

        self::assertTrue($this->csrf()->validate('/account/update'));
    }

    /**
     * Only the submitted field is attacker-controlled here — the cookie is
     * genuine. It must still fail, which double-submit also caught.
     */
    public function testAMismatchedFieldIsRejected(): void
    {
        $this->requireSession();

        $this->withSessionToken($this->token());
        $this->present($this->token());
        $_POST['csrf_token'] = str_repeat('beef', 16);

        self::assertFalse($this->csrf()->validate('/account/update'));
    }

    // ─── Behaviour that must not change ──────────────────────────────

    /** Reads are never checked, session or not. */
    public function testAReadRequestIsNotChecked(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';

        self::assertTrue($this->csrf()->validate('/account'));
    }

    public function testProtectionCanBeTurnedOff(): void
    {
        self::assertTrue($this->csrf(['csrf_protection' => false])->validate('/account/update'));
    }

    public function testAnExcludedUriSkipsTheCheck(): void
    {
        $csrf = $this->csrf(['csrf_exclude_uris' => ['account/*']]);

        self::assertTrue($csrf->validate('account/update'));
    }

    /** A missing field is refused whatever the cookie says. */
    public function testAnAbsentTokenIsRejected(): void
    {
        $_COOKIE['csrf_cookie'] = $this->token();
        $_COOKIE['csrf_cookie_time'] = (string) time();

        self::assertFalse($this->csrf()->validate('/account/update'));
    }

    public function testAMalformedTokenIsRejected(): void
    {
        $_POST['csrf_token'] = 'not-a-token';
        $_COOKIE['csrf_cookie'] = 'not-a-token';
        $_COOKIE['csrf_cookie_time'] = (string) time();

        self::assertFalse($this->csrf()->validate('/account/update'));
    }

    public function testAnExpiredTokenIsRejected(): void
    {
        $this->present($this->token());
        $_COOKIE['csrf_cookie_time'] = (string) (time() - 100000);

        self::assertFalse($this->csrf(['csrf_expire' => 60])->validate('/account/update'));
    }

    // ─── The stateless fallback ──────────────────────────────────────

    /**
     * With nothing recorded server-side there is nothing to bind to, so the
     * cookie comparison remains — unchanged from before, and no weaker than it
     * was. This is the path a request takes before anything has issued a token.
     * Stateless API routes skip CSRF entirely and use request signing instead.
     */
    public function testWithNoRecordedTokenTheCookieComparisonStillApplies(): void
    {
        unset($_SESSION['_csrf_token']);
        $this->present($this->token());

        self::assertTrue($this->csrf()->validate('/account/update'));
    }

    public function testWithNoRecordedTokenAMismatchIsStillRejected(): void
    {
        unset($_SESSION['_csrf_token']);
        $this->present($this->token());
        $_POST['csrf_token'] = str_repeat('beef', 16);

        self::assertFalse($this->csrf()->validate('/account/update'));
    }

    // ─── Token issuance records the session copy ─────────────────────

    /** A token that is never recorded server-side cannot be validated against. */
    public function testIssuingATokenRecordsItInTheSession(): void
    {
        $this->requireSession();

        $issued = $this->csrf()->init();

        self::assertSame($issued, $_SESSION['_csrf_token'] ?? null);
    }

    /**
     * Login calls session_regenerate_id(), which can leave a live cookie with no
     * session copy. Adopting it keeps the token already rendered into the open
     * page working, instead of failing the user's next submit.
     */
    public function testACookieThatOutlivedItsSessionIsAdoptedAndRecorded(): void
    {
        $this->requireSession();

        $_COOKIE['csrf_cookie'] = $this->token();
        $_COOKIE['csrf_cookie_time'] = (string) time();

        self::assertSame($this->token(), $this->csrf()->init());
        self::assertSame($this->token(), $_SESSION['_csrf_token'] ?? null);
    }

    /** getToken() must hand out what the server will accept, not what the jar holds. */
    public function testGetTokenPrefersTheSessionCopy(): void
    {
        $this->requireSession();

        $this->withSessionToken($this->token());
        $_COOKIE['csrf_cookie'] = str_repeat('dead', 16);

        self::assertSame($this->token(), $this->csrf()->getToken());
    }
}
