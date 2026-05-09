<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use Core\Security\Hasher;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Core\Security\Hasher
 *
 * Covers: make(), verify(), needsRehash(), dummyVerify(), hashToken(), equals()
 */
class HasherTest extends TestCase
{
    public function test_make_returns_argon2id_hash(): void
    {
        $hash = Hasher::make('password123');

        $this->assertStringStartsWith('$argon2id$', $hash);
    }

    public function test_make_produces_different_hashes_for_same_input(): void
    {
        // Argon2id uses a random salt — hashes must not be identical
        $hash1 = Hasher::make('password123');
        $hash2 = Hasher::make('password123');

        $this->assertNotEquals($hash1, $hash2);
    }

    public function test_verify_returns_true_for_correct_password(): void
    {
        $hash = Hasher::make('correct-horse-battery-staple');

        $this->assertTrue(Hasher::verify('correct-horse-battery-staple', $hash));
    }

    public function test_verify_returns_false_for_wrong_password(): void
    {
        $hash = Hasher::make('correct-horse-battery-staple');

        $this->assertFalse(Hasher::verify('wrong-password', $hash));
    }

    public function test_needs_rehash_returns_false_for_fresh_argon2id(): void
    {
        $hash = Hasher::make('some-password');

        $this->assertFalse(Hasher::needsRehash($hash));
    }

    public function test_needs_rehash_returns_true_for_bcrypt_hash(): void
    {
        // Bcrypt hash — should trigger upgrade path
        $bcryptHash = password_hash('some-password', PASSWORD_BCRYPT);

        $this->assertTrue(Hasher::needsRehash($bcryptHash));
    }

    public function test_dummy_verify_does_not_throw(): void
    {
        // dummyVerify must never throw — it's called in the "user not found" path
        $this->expectNotToPerformAssertions();

        Hasher::dummyVerify('any-password');
    }

    public function test_hash_token_returns_sha256_hex(): void
    {
        $tokenHash = Hasher::hashToken('my-api-token');

        $this->assertEquals(64, strlen($tokenHash));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $tokenHash);
    }

    public function test_hash_token_is_deterministic(): void
    {
        $this->assertEquals(
            Hasher::hashToken('same-token'),
            Hasher::hashToken('same-token')
        );
    }

    public function test_equals_returns_true_for_matching_strings(): void
    {
        $this->assertTrue(Hasher::equals('secret-token', 'secret-token'));
    }

    public function test_equals_returns_false_for_different_strings(): void
    {
        $this->assertFalse(Hasher::equals('secret-token', 'different-token'));
    }

    public function test_equals_returns_false_for_empty_vs_value(): void
    {
        $this->assertFalse(Hasher::equals('secret', ''));
    }

    public function test_make_throws_not_for_valid_input(): void
    {
        // Should not throw for any non-empty string
        $this->expectNotToPerformAssertions();

        Hasher::make('short');
        Hasher::make(str_repeat('x', 1000));
        Hasher::make('unicode-🔐-password');
    }
}
