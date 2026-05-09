<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use Core\Security\Encryptor;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Core\Security\Encryptor
 *
 * Covers: encrypt(), decrypt(), blindIndex(), hardware availability
 */
class EncryptorTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('sodium')) {
            $this->markTestSkipped('ext-sodium is not available in this PHP build.');
        }

        // 64 hex chars = 32 bytes = valid AES-256 key
        $GLOBALS['config']['app']['key'] = str_repeat('a1', 32);
    }

    public function test_encrypt_returns_base64_string(): void
    {
        $enc = Encryptor::encrypt('hello@example.com');

        $this->assertNotEmpty($enc);
        $this->assertNotFalse(base64_decode($enc, true));
    }

    public function test_decrypt_recovers_original_plaintext(): void
    {
        $original = 'hello@example.com';
        $enc      = Encryptor::encrypt($original);

        $this->assertEquals($original, Encryptor::decrypt($enc));
    }

    public function test_encrypt_produces_different_ciphertexts_for_same_input(): void
    {
        // Random nonce ensures ciphertexts differ
        $enc1 = Encryptor::encrypt('same plaintext');
        $enc2 = Encryptor::encrypt('same plaintext');

        $this->assertNotEquals($enc1, $enc2);
    }

    public function test_decrypt_throws_on_tampered_ciphertext(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Decryption failed/');

        $enc     = Encryptor::encrypt('secret');
        $tampered = base64_encode(str_repeat('X', strlen(base64_decode($enc))));

        Encryptor::decrypt($tampered);
    }

    public function test_decrypt_throws_on_invalid_base64(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not valid base64/');

        Encryptor::decrypt('not!!valid%%base64');
    }

    public function test_blind_index_is_deterministic(): void
    {
        $idx1 = Encryptor::blindIndex('hello@example.com');
        $idx2 = Encryptor::blindIndex('hello@example.com');

        $this->assertEquals($idx1, $idx2);
    }

    public function test_blind_index_differs_for_different_inputs(): void
    {
        $idx1 = Encryptor::blindIndex('alice@example.com');
        $idx2 = Encryptor::blindIndex('bob@example.com');

        $this->assertNotEquals($idx1, $idx2);
    }

    public function test_blind_index_is_64_hex_chars(): void
    {
        $idx = Encryptor::blindIndex('test@example.com');

        $this->assertEquals(64, strlen($idx));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $idx);
    }

    public function test_blind_index_context_produces_different_values(): void
    {
        $email = 'test@example.com';
        $idx1  = Encryptor::blindIndex($email, 'email');
        $idx2  = Encryptor::blindIndex($email, 'phone');

        $this->assertNotEquals($idx1, $idx2);
    }

    public function test_throws_when_app_key_not_set(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/APP_KEY/');

        $saved = $GLOBALS['config']['app']['key'] ?? null;
        $GLOBALS['config']['app']['key'] = null;

        try {
            Encryptor::encrypt('test');
        } finally {
            $GLOBALS['config']['app']['key'] = $saved;
        }
    }

    public function test_is_hardware_accelerated_returns_bool(): void
    {
        $result = Encryptor::isHardwareAccelerated();

        $this->assertIsBool($result);
    }
}
