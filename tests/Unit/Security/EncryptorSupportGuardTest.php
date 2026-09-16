<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use Core\Security\Encryptor;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Encryptor calls sodium_* directly and declared a capability probe it never used,
 * so a host without ext-sodium got an uncatchable Error. These run on both kinds
 * of host: with sodium they assert the happy path, without it the error quality.
 */
final class EncryptorSupportGuardTest extends TestCase
{
    private function sodiumAvailable(): bool
    {
        return extension_loaded('sodium');
    }

    public function testCapabilityProbeReportsTheHostHonestly(): void
    {
        $supported = Encryptor::isHardwareAccelerated();

        self::assertIsBool($supported);

        if (!$this->sodiumAvailable()) {
            self::assertFalse($supported, 'The probe must be false when ext-sodium is not loaded.');
        }
    }

    public function testCapabilityProbeNeverThrowsEvenWithoutSodium(): void
    {
        // A capability probe that fatals is useless — callers use it to decide
        // whether to call encrypt() at all.
        Encryptor::isHardwareAccelerated();

        self::assertTrue(true);
    }

    public function testEncryptThrowsARuntimeExceptionRatherThanAFatalError(): void
    {
        if (Encryptor::isHardwareAccelerated() && (string) config('app.key') !== '') {
            self::markTestSkipped('Host supports AES-256-GCM and APP_KEY is set — nothing to guard against here.');
        }

        $this->expectException(RuntimeException::class);
        Encryptor::encrypt('some sensitive value');
    }

    public function testDecryptThrowsARuntimeExceptionRatherThanAFatalError(): void
    {
        if (Encryptor::isHardwareAccelerated() && (string) config('app.key') !== '') {
            self::markTestSkipped('Host supports AES-256-GCM and APP_KEY is set — nothing to guard against here.');
        }

        $this->expectException(RuntimeException::class);
        Encryptor::decrypt('AAAAAAAAAAAAAAAAAAAAAAAA');
    }

    public function testTheMissingSodiumMessageNamesTheExtensionAndTheFix(): void
    {
        if ($this->sodiumAvailable()) {
            self::markTestSkipped('ext-sodium is loaded on this host.');
        }

        try {
            Encryptor::encrypt('value');
            self::fail('Expected a RuntimeException when ext-sodium is unavailable.');
        } catch (RuntimeException $e) {
            $message = $e->getMessage();

            self::assertStringContainsString('ext-sodium', $message, 'The error should name the missing extension.');
            self::assertStringContainsString('php.ini', $message, 'The error should say how to fix it.');
        }
    }

    public function testBlindIndexAlsoGuardsAgainstMissingSodium(): void
    {
        // blindIndex() reaches sodium through deriveKey(), so it needs the same
        // protection — but only the extension check, not the AES-NI one.
        if ($this->sodiumAvailable()) {
            self::markTestSkipped('ext-sodium is loaded on this host.');
        }

        $this->expectException(RuntimeException::class);
        Encryptor::blindIndex('user@example.com');
    }

    public function testBothEntryPointsAssertSupportBeforeTouchingSodium(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 3) . '/systems/Core/Security/Encryptor.php'
        );

        foreach (['encrypt', 'decrypt'] as $method) {
            self::assertMatchesRegularExpression(
                '/function ' . $method . '\([^)]*\)[^{]*\{\s*self::assertSupported\(\);/',
                $source,
                sprintf('Encryptor::%s() must call assertSupported() as its first statement.', $method)
            );
        }
    }

    public function testAMissingAppKeyErrorExplainsWhereTheKeyComesFrom(): void
    {
        if (!$this->sodiumAvailable()) {
            self::markTestSkipped('Cannot reach the APP_KEY check without ext-sodium.');
        }

        if ((string) config('app.key') !== '') {
            self::markTestSkipped('APP_KEY is configured in this environment.');
        }

        try {
            Encryptor::blindIndex('value');
            self::fail('Expected a RuntimeException when APP_KEY is unset.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('APP_KEY', $e->getMessage());
            self::assertStringContainsString('key:generate', $e->getMessage());
        }
    }
}
