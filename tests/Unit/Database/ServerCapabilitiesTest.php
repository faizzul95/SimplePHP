<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use Core\Database\ServerCapabilities;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The queue claims jobs with `FOR UPDATE SKIP LOCKED`, which is what lets N
 * workers each take a different row instead of all blocking on the head of the
 * queue. It is also a syntax error on MySQL < 8.0.1 and MariaDB < 10.6 — so on
 * those servers the queue did not degrade, it stopped working.
 */
final class ServerCapabilitiesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ServerCapabilities::reset();
    }

    protected function tearDown(): void
    {
        ServerCapabilities::reset();
        parent::tearDown();
    }

    /** @return array<string, array{0:string,1:bool}> */
    public static function versionProvider(): array
    {
        return [
            'MySQL 8.0.36'            => ['8.0.36', true],
            'MySQL 8.4.0'             => ['8.4.0', true],
            'MySQL 8.0.1 exactly'     => ['8.0.1', true],
            'MySQL 8.0.0 is too old'  => ['8.0.0', false],
            'MySQL 5.7.44'            => ['5.7.44', false],
            'MySQL 5.6'               => ['5.6.51', false],
            'MariaDB 10.6.16'         => ['10.6.16-MariaDB-log', true],
            'MariaDB 10.11 banner'    => ['5.5.5-10.11.6-MariaDB-1:10.11.6+maria~ubu2204', true],
            'MariaDB 10.5 too old'    => ['10.5.23-MariaDB', false],
            'MariaDB 10.3 too old'    => ['10.3.39-MariaDB-log', false],
            'garbage'                 => ['not-a-version', false],
            'empty'                   => ['', false],
        ];
    }

    #[DataProvider('versionProvider')]
    public function testSkipLockedSupportIsDetectedPerServer(string $version, bool $expected): void
    {
        self::assertSame($expected, ServerCapabilities::supportsSkipLocked($version));
    }

    /**
     * MariaDB reports "5.5.5-" in front of its real version as a compatibility lie
     * for ancient clients. Taking the first dotted number at face value would
     * class every modern MariaDB as too old.
     */
    public function testTheMariadbCompatibilityPrefixIsSeenThrough(): void
    {
        self::assertSame(
            '10.11.6',
            ServerCapabilities::numericVersion('5.5.5-10.11.6-MariaDB-1:10.11.6+maria~ubu2204')
        );
    }

    public function testPlainVersionsAreParsed(): void
    {
        self::assertSame('8.0.36', ServerCapabilities::numericVersion('8.0.36'));
        self::assertSame('10.6.16', ServerCapabilities::numericVersion('10.6.16-MariaDB-log'));
    }

    public function testUnparseableVersionsReturnNull(): void
    {
        self::assertNull(ServerCapabilities::numericVersion(''));
        self::assertNull(ServerCapabilities::numericVersion('unknown'));
    }

    public function testTheAnswerIsCachedPerConnectionKey(): void
    {
        self::assertTrue(ServerCapabilities::supportsSkipLocked('8.0.36', 'queue'));

        // Probing SELECT VERSION() on every job claim would cost more than the
        // feature saves, so a cached key wins over a later contradicting value.
        self::assertTrue(ServerCapabilities::supportsSkipLocked('5.7.44', 'queue'));
    }

    public function testDifferentCacheKeysAreIndependent(): void
    {
        self::assertTrue(ServerCapabilities::supportsSkipLocked('8.0.36', 'primary'));
        self::assertFalse(ServerCapabilities::supportsSkipLocked('5.7.44', 'legacy'));
    }

    public function testResetClearsTheCache(): void
    {
        ServerCapabilities::supportsSkipLocked('8.0.36', 'queue');
        ServerCapabilities::reset();

        self::assertFalse(ServerCapabilities::supportsSkipLocked('5.7.44', 'queue'));
    }

    public function testAnUncachedProbeIsNotStored(): void
    {
        // No key means no caching — used where the connection is not yet known.
        self::assertTrue(ServerCapabilities::supportsSkipLocked('8.0.36'));
        self::assertFalse(ServerCapabilities::supportsSkipLocked('5.7.44'));
    }
}
