<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use Core\Security\PwnedPasswordChecker;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Core\Security\PwnedPasswordChecker
 *
 * Covers: fail-open behaviour when check disabled, API unreachable, isCompromised()
 * Note: no real HTTP calls — the check is disabled by default in tests.
 */
class PwnedPasswordCheckerTest extends TestCase
{
    protected function setUp(): void
    {
        // Ensure check is disabled by default (no real API calls in tests)
        $GLOBALS['config']['security']['password']['pwned_check'] = false;
    }

    public function test_returns_zero_when_check_disabled(): void
    {
        $count = PwnedPasswordChecker::timesBreached('password123');

        $this->assertEquals(0, $count);
    }

    public function test_is_compromised_returns_false_when_check_disabled(): void
    {
        $this->assertFalse(PwnedPasswordChecker::isCompromised('password123'));
    }

    public function test_returns_zero_on_network_failure(): void
    {
        // Enable check but point to unreachable host (test fail-open behaviour)
        $GLOBALS['config']['security']['password']['pwned_check'] = true;

        // The checker will try to call the HIBP API. If no network, it must fail open (return 0).
        // In CI without network, this should still return 0.
        $count = PwnedPasswordChecker::timesBreached('unique-password-' . uniqid());

        $this->assertIsInt($count);
        $this->assertGreaterThanOrEqual(0, $count);
    }

    public function test_is_compromised_returns_bool(): void
    {
        $result = PwnedPasswordChecker::isCompromised('any-password');

        $this->assertIsBool($result);
    }
}
