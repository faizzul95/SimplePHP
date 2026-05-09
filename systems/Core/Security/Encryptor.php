<?php

declare(strict_types=1);

namespace Core\Security;

/**
 * AES-256-GCM column-level encryption for PII using libsodium.
 *
 * Provides authenticated encryption with no padding oracle risk.
 * Uses blind-index pattern for searchability without exposing plaintext.
 *
 * Requires: ext-sodium (included in PHP 8.0+ by default).
 * Requires: APP_KEY set in .env (64 hex chars for a 256-bit key, or any string).
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
        $key  = self::deriveKey($context);
        $hash = \sodium_crypto_generichash($plaintext, $key, 32);
        \sodium_memzero($key);
        return \bin2hex($hash);
    }

    /**
     * Check whether hardware AES-256-GCM is available (for performance awareness).
     * If false, consider using XChaCha20-Poly1305 instead (software-fast).
     */
    public static function isHardwareAccelerated(): bool
    {
        return \function_exists('sodium_crypto_aead_aes256gcm_is_available')
            && \sodium_crypto_aead_aes256gcm_is_available();
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
            throw new \RuntimeException('APP_KEY is not set. Run: php myth key:generate');
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
