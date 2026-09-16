<?php

declare(strict_types=1);

namespace Core\Security;

/**
 * AES-256-GCM column-level encryption for PII using libsodium.
 *
 * Provides authenticated encryption with no padding oracle risk.
 * Uses blind-index pattern for searchability without exposing plaintext.
 *
 * Requires ext-sodium and a CPU with AES-NI; probe with isHardwareAccelerated().
 * Requires APP_KEY in .env, surfaced as config('app.key') by app/config/app.php.
 *
 * Usage:
 *   $enc   = Encryptor::encrypt($email);
 *   $index = Encryptor::blindIndex($email);
 *   // Store both; query by index, display by decrypting enc
 *
 */
final class Encryptor
{
    /**
     * Encrypt a plaintext string for database storage.
     * Returns a base64-encoded bundle: nonce + ciphertext (with auth tag).
     *
     * @throws \RuntimeException if encryption fails or APP_KEY is missing
     */
    public static function encrypt(string $plaintext): string
    {
        self::assertSupported();
        $key   = self::deriveKey();
        $nonce = \random_bytes(SODIUM_CRYPTO_AEAD_AES256GCM_NPUBBYTES);

        $ciphertext = \sodium_crypto_aead_aes256gcm_encrypt(
            $plaintext,
            '',     // additional data — add context string for extra binding if desired
            $nonce,
            $key
        );

        \sodium_memzero($key);

        return \base64_encode($nonce . $ciphertext);
    }

    /**
     * Decrypt an encrypted value stored in the database.
     *
     * @throws \RuntimeException if decryption fails (tampered ciphertext) or APP_KEY missing
     */
    public static function decrypt(string $encoded): string
    {
        self::assertSupported();
        $key  = self::deriveKey();
        $data = \base64_decode($encoded, strict: true);

        if ($data === false) {
            throw new \RuntimeException('Invalid encrypted value — not valid base64.');
        }

        $nonceLen   = SODIUM_CRYPTO_AEAD_AES256GCM_NPUBBYTES;
        $nonce      = \substr($data, 0, $nonceLen);
        $ciphertext = \substr($data, $nonceLen);

        $plaintext = \sodium_crypto_aead_aes256gcm_decrypt($ciphertext, '', $nonce, $key);
        \sodium_memzero($key);

        if ($plaintext === false) {
            throw new \RuntimeException('Decryption failed — ciphertext may be tampered.');
        }

        return $plaintext;
    }

    /**
     * Deterministic blind index for searching encrypted fields.
     *
     * Store alongside the encrypted column and index it.
     * Query by blind index; decrypt on read.
     *
     * Schema example:
     *   email_encrypted   TEXT       — Encryptor::encrypt($email)
     *   email_blind_index CHAR(64)   — Encryptor::blindIndex($email)
     *   INDEX (email_blind_index)
     *
     * Query example:
     *   db()->table('users')->where('email_blind_index', Encryptor::blindIndex($input))->first()
     */
    public static function blindIndex(string $plaintext, string $context = 'blind_index'): string
    {
        self::assertSodium();
        $key  = self::deriveKey($context);
        $hash = \sodium_crypto_generichash($plaintext, $key, 32);
        \sodium_memzero($key);
        return \bin2hex($hash);
    }

    /**
     * Whether this host can perform AES-256-GCM. False when ext-sodium is missing
     * or the CPU has no AES-NI, which libsodium requires for this cipher.
     */
    public static function isHardwareAccelerated(): bool
    {
        return \function_exists('sodium_crypto_aead_aes256gcm_is_available')
            && \sodium_crypto_aead_aes256gcm_is_available();
    }

    /** @throws \RuntimeException when ext-sodium is not loaded */
    private static function assertSodium(): void
    {
        if (!\extension_loaded('sodium')) {
            throw new \RuntimeException(
                'Encryptor requires ext-sodium, which is not loaded. '
                . 'Enable extension=sodium in php.ini, then restart PHP.'
            );
        }
    }

    /** @throws \RuntimeException when the host cannot perform AES-256-GCM */
    private static function assertSupported(): void
    {
        self::assertSodium();

        if (!self::isHardwareAccelerated()) {
            throw new \RuntimeException(
                'Encryptor requires AES-256-GCM, which libsodium reports as unavailable on '
                . 'this CPU (no AES-NI). Switch to sodium_crypto_aead_xchacha20poly1305_ietf_*, '
                . 'which has no hardware requirement.'
            );
        }
    }

    /**
     * Derive a 256-bit key from APP_KEY using BLAKE2b keyed hash.
     * Provides domain separation between encryption and blind-index contexts.
     *
     * @throws \RuntimeException if APP_KEY is not configured
     */
    private static function deriveKey(string $context = 'encryption'): string
    {
        $appKey = config('app.key') ?? null;

        if ($appKey === null || $appKey === '') {
            throw new \RuntimeException(
                'APP_KEY is not set. Run: php myth key:generate '
                . '(reads config(\'app.key\'), which comes from APP_KEY in .env).'
            );
        }

        $appKey = (string) $appKey;

        // Accept both raw hex (64 chars = 32 bytes) and arbitrary strings
        $rawKey = (\strlen($appKey) === 64 && \ctype_xdigit($appKey))
            ? \sodium_hex2bin($appKey)
            : \substr(\hash('sha256', $appKey, true), 0, SODIUM_CRYPTO_SECRETBOX_KEYBYTES);

        return \sodium_crypto_generichash(
            $context,
            $rawKey,
            SODIUM_CRYPTO_SECRETBOX_KEYBYTES
        );
    }
}
