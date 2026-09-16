<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use Core\Security\Hasher;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * DUMMY_HASH once held a string that only looked like an Argon2id hash.
 * password_verify() rejects a malformed hash in microseconds, so dummyVerify()
 * did no work and the miss path stayed ~83,000x faster than a real verify.
 */
final class HasherDummyHashTest extends TestCase
{
    private function dummyHash(): string
    {
        $constant = (new ReflectionClass(Hasher::class))->getConstant('DUMMY_HASH');
        self::assertIsString($constant, 'DUMMY_HASH must exist and be a string.');

        return $constant;
    }

    /** @return array{memory_cost:int,time_cost:int,threads:int} */
    private function configuredCosts(): array
    {
        $reflection = new ReflectionClass(Hasher::class);

        return [
            'memory_cost' => (int) $reflection->getConstant('MEMORY_COST'),
            'time_cost'   => (int) $reflection->getConstant('TIME_COST'),
            'threads'     => (int) $reflection->getConstant('THREADS'),
        ];
    }

    public function testDummyHashIsAGenuineArgon2idHash(): void
    {
        $info = password_get_info($this->dummyHash());

        self::assertSame(
            'argon2id',
            $info['algoName'],
            'DUMMY_HASH is not a real Argon2id hash, so password_verify() will reject it '
            . 'immediately and dummyVerify() becomes a no-op. Regenerate it with '
            . 'password_hash(bin2hex(random_bytes(32)), PASSWORD_ARGON2ID, [...]).'
        );
    }

    /**
     * password_get_info() only parses the "$argon2id$" prefix — it does NOT validate
     * the salt and digest payload, so the original placeholder constant passed that
     * check while still being rejected by password_verify(). This inspects the encoded
     * payload directly, which is deterministic and does not depend on timing.
     */
    public function testDummyHashPayloadIsStructurallyValid(): void
    {
        $segments = explode('$', $this->dummyHash());

        self::assertCount(6, $segments, 'Malformed Argon2 encoded hash: expected 6 "$"-separated segments.');

        [, $algo, $version, $params, $salt, $digest] = $segments;

        self::assertSame('argon2id', $algo);
        self::assertSame('v=19', $version);
        self::assertMatchesRegularExpression('/^m=\d+,t=\d+,p=\d+$/', $params);

        $decodedSalt = base64_decode($salt, true);
        self::assertNotFalse($decodedSalt, 'Salt segment is not valid base64 — password_verify() will reject this hash.');
        self::assertSame(
            16,
            strlen($decodedSalt),
            'Argon2 salt must decode to 16 bytes. A placeholder string will not.'
        );

        $decodedDigest = base64_decode($digest, true);
        self::assertNotFalse(
            $decodedDigest,
            'Digest segment is not valid base64. This is exactly how the original placeholder '
            . 'constant failed: password_verify() rejects it in microseconds and dummyVerify() '
            . 'silently stops defending against enumeration.'
        );
        self::assertSame(
            32,
            strlen($decodedDigest),
            'Argon2 digest must decode to 32 bytes.'
        );
    }

    public function testDummyHashCostsMatchTheConfiguredCosts(): void
    {
        $info = password_get_info($this->dummyHash());

        self::assertSame(
            $this->configuredCosts(),
            $info['options'],
            'DUMMY_HASH was generated with different cost parameters than the class '
            . 'constants. The "user not found" path would then cost a different amount '
            . 'of work than a real verification, which reopens the timing gap.'
        );
    }

    public function testDummyHashNeverMatchesAnyPlausiblePassword(): void
    {
        foreach (['', 'password', 'secret', 'admin', 'dummy', '123456'] as $candidate) {
            self::assertFalse(
                password_verify($candidate, $this->dummyHash()),
                sprintf('DUMMY_HASH must never verify against a real password (tried "%s").', $candidate)
            );
        }
    }

    public function testDummyHashDoesNotNeedRehash(): void
    {
        // If this fails the constant is stale relative to the configured costs,
        // which is the same drift testDummyHashCostsMatchTheConfiguredCosts catches
        // but stated in the framework's own terms.
        self::assertFalse(
            Hasher::needsRehash($this->dummyHash()),
            'DUMMY_HASH is stale relative to the current Argon2id parameters.'
        );
    }

    /**
     * The whole point of the constant: verifying against it must cost roughly the
     * same as verifying against a real hash. A generous 4x band keeps this stable
     * on noisy CI hardware while still catching a return to the microsecond path.
     */
    public function testDummyVerifyCostsTheSameOrderAsARealVerify(): void
    {
        $realHash = Hasher::make('a-real-password-for-timing-comparison');

        $realMs  = $this->timeMs(static fn() => Hasher::verify('wrong-password', $realHash));
        $dummyMs = $this->timeMs(static fn() => Hasher::dummyVerify('wrong-password'));

        self::assertGreaterThan(
            $realMs / 4,
            $dummyMs,
            sprintf(
                'dummyVerify() took %.3f ms against a real verify of %.3f ms. It is not '
                . 'doing the Argon2id work, so "user not found" is distinguishable by timing.',
                $dummyMs,
                $realMs
            )
        );
    }

    private function timeMs(callable $fn): float
    {
        $start = hrtime(true);
        $fn();

        return (hrtime(true) - $start) / 1e6;
    }
}
