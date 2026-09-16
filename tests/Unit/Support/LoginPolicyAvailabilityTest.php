<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use Components\Auth;
use PHPUnit\Framework\TestCase;

/**
 * An Auth whose attempt-history read can be made to fail on demand.
 */
final class FlakyAttemptStoreAuth extends Auth
{
    public bool $storeFails = false;

    /** @var list<int> Timestamps to report when the store is healthy. */
    public array $timestamps = [];

    /** @param array<string, mixed> $config */
    public function __construct(array $config = [])
    {
        parent::__construct($config);
    }

    protected function recentLoginAttemptBuckets(array $credentials, int $windowSeconds): array
    {
        if ($this->storeFails) {
            // Exactly what the real method does when the query throws: report no
            // attempts, and set the flag that says the answer is not trustworthy.
            $property = new \ReflectionProperty(Auth::class, 'loginAttemptStoreFailed');
            $property->setAccessible(true);
            $property->setValue($this, true);

            return ['ip' => []];
        }

        return ['ip' => $this->timestamps];
    }

    /** @param array<string, mixed> $credentials */
    public function probeCanAttempt(array $credentials = []): bool
    {
        return $this->canAttemptWithLoginPolicy($credentials);
    }
}

/**
 * The login lockout is backed by the attempts table. Reading it used to swallow
 * every error into an empty array — which reads as "no recent failures", which
 * reads as "not locked out". So any fault on that table disabled brute-force
 * protection completely, with nothing written anywhere to say so.
 *
 * `AUTH_LOGIN_POLICY_FAIL_OPEN` was in the config and in .env.example the whole
 * time and was never read by anything, so an operator setting it to false
 * believed they had closed a door that did not exist.
 */
final class LoginPolicyAvailabilityTest extends TestCase
{
    /** @param array<string, mixed> $overrides */
    private function auth(array $overrides = []): FlakyAttemptStoreAuth
    {
        return new FlakyAttemptStoreAuth([
            'systems_login_policy' => array_merge([
                'enabled' => true,
                'max_attempts' => 3,
                'decay_seconds' => 600,
                'lockout_seconds' => 900,
                'fail_open_if_cache_unavailable' => true,
            ], $overrides),
        ]);
    }

    // ─── The store is healthy ────────────────────────────────────────

    public function testAnAttemptIsAllowedWhenNothingHasFailedRecently(): void
    {
        self::assertTrue($this->auth()->probeCanAttempt());
    }

    public function testAnAttemptIsAllowedBelowTheThreshold(): void
    {
        $auth = $this->auth();
        $auth->timestamps = [time() - 60, time() - 30];

        self::assertTrue($auth->probeCanAttempt());
    }

    public function testTheThresholdLocksTheAttemptOut(): void
    {
        $auth = $this->auth();
        $auth->timestamps = [time() - 90, time() - 60, time() - 30];

        self::assertFalse($auth->probeCanAttempt());
    }

    /** Old failures fall out of the window rather than accumulating forever. */
    public function testFailuresOutsideTheWindowDoNotCount(): void
    {
        $auth = $this->auth();
        $old = time() - 100000;
        $auth->timestamps = [$old, $old + 1, $old + 2];

        self::assertTrue($auth->probeCanAttempt());
    }

    // ─── The store is unreadable ─────────────────────────────────────

    /**
     * The default. A broken attempts table must not lock every user out of an
     * otherwise working application.
     */
    public function testFailingOpenAllowsTheAttempt(): void
    {
        $auth = $this->auth(['fail_open_if_cache_unavailable' => true]);
        $auth->storeFails = true;

        self::assertTrue($auth->probeCanAttempt());
    }

    /**
     * The behaviour the setting was supposed to have all along: where losing
     * brute-force protection is the worse outcome, refuse instead.
     */
    public function testFailingClosedRefusesTheAttempt(): void
    {
        $auth = $this->auth(['fail_open_if_cache_unavailable' => false]);
        $auth->storeFails = true;

        self::assertFalse($auth->probeCanAttempt());
    }

    /** The caller has to be told this is temporary, not a bad password. */
    public function testTheRefusalIsReportedAsTemporary(): void
    {
        $auth = $this->auth(['fail_open_if_cache_unavailable' => false]);
        $auth->storeFails = true;
        $auth->probeCanAttempt();

        $status = $auth->lastAttemptStatus();

        self::assertSame('auth.login.policy_unavailable', $status['reason'] ?? null);
        self::assertSame(503, $status['http_code'] ?? null);
    }

    /** A 401 here would tell the user their password is wrong, which it is not. */
    public function testTheRefusalIsNotAnAuthenticationFailure(): void
    {
        $auth = $this->auth(['fail_open_if_cache_unavailable' => false]);
        $auth->storeFails = true;
        $auth->probeCanAttempt();

        self::assertNotSame(401, $auth->lastAttemptStatus()['http_code'] ?? null);
    }

    /** The flag is per attempt; one failed read must not poison the next login. */
    public function testTheFailureFlagIsResetBetweenAttempts(): void
    {
        $auth = $this->auth(['fail_open_if_cache_unavailable' => false]);

        $auth->storeFails = true;
        self::assertFalse($auth->probeCanAttempt());

        $auth->storeFails = false;
        self::assertTrue($auth->probeCanAttempt(), 'A recovered store must allow logins again.');
    }

    // ─── The policy is off ───────────────────────────────────────────

    /** With the policy disabled, an unreadable store is not a reason to refuse. */
    public function testADisabledPolicyIgnoresTheStoreEntirely(): void
    {
        $auth = $this->auth(['enabled' => false, 'fail_open_if_cache_unavailable' => false]);
        $auth->storeFails = true;

        self::assertTrue($auth->probeCanAttempt());
    }
}
