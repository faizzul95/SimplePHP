<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use Core\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Request::ip() decides who gets rate-limited, who gets IP-blocked, and whose
 * address lands in the audit log. Every one of those is wrong if it can be set
 * by the caller.
 *
 * X-Forwarded-For reads `client, proxy1, proxy2` — each hop appends the address
 * it received from, so only the entries our own proxies appended are trustworthy.
 * Reading the leftmost value took the one the attacker writes: sending
 * `X-Forwarded-For: 1.2.3.4` made the framework attribute the request to
 * 1.2.3.4, and rotating that header per request bypassed every IP-keyed control
 * the framework has.
 */
final class ClientAddressTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $configBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->configBackup = $GLOBALS['config'] ?? [];
    }

    protected function tearDown(): void
    {
        $GLOBALS['config'] = $this->configBackup;
        parent::tearDown();
    }

    /** @param list<string> $trustedProxies */
    private function trustProxies(array $trustedProxies): void
    {
        $GLOBALS['config']['security']['trusted']['proxies'] = $trustedProxies;
    }

    /** @param array<string, string> $headers */
    private function ipFor(string $remoteAddr, array $headers = []): string
    {
        $server = ['REMOTE_ADDR' => $remoteAddr, 'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/'];

        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return (new Request([], [], $server))->ip();
    }

    // ─── Without a trusted proxy list ────────────────────────────────

    public function testForwardedHeadersAreIgnoredWithNoTrustedProxies(): void
    {
        $this->trustProxies([]);

        self::assertSame(
            '203.0.113.9',
            $this->ipFor('203.0.113.9', ['X-Forwarded-For' => '1.2.3.4'])
        );
    }

    public function testAnUntrustedPeerCannotForwardAnAddress(): void
    {
        $this->trustProxies(['10.0.0.1']);

        self::assertSame(
            '203.0.113.9',
            $this->ipFor('203.0.113.9', ['X-Forwarded-For' => '1.2.3.4'])
        );
    }

    // ─── Behind a trusted proxy ──────────────────────────────────────

    public function testASingleHopReportsTheClient(): void
    {
        $this->trustProxies(['10.0.0.1']);

        self::assertSame('203.0.113.9', $this->ipFor('10.0.0.1', ['X-Forwarded-For' => '203.0.113.9']));
    }

    /**
     * The bypass. The attacker sends `X-Forwarded-For: 1.2.3.4`; the real proxy
     * appends the address it saw, giving `1.2.3.4, 203.0.113.9`. The rightmost
     * untrusted entry is the one our infrastructure actually observed.
     */
    public function testAForgedLeadingEntryIsNotBelieved(): void
    {
        $this->trustProxies(['10.0.0.1']);

        self::assertSame(
            '203.0.113.9',
            $this->ipFor('10.0.0.1', ['X-Forwarded-For' => '1.2.3.4, 203.0.113.9'])
        );
    }

    public function testForgingSeveralEntriesDoesNotHelpEither(): void
    {
        $this->trustProxies(['10.0.0.1']);

        self::assertSame(
            '203.0.113.9',
            $this->ipFor('10.0.0.1', ['X-Forwarded-For' => '1.1.1.1, 2.2.2.2, 3.3.3.3, 203.0.113.9'])
        );
    }

    /** Our own proxies are skipped, so a two-hop chain still finds the client. */
    public function testTrustedHopsAreSkipped(): void
    {
        $this->trustProxies(['10.0.0.1', '10.0.0.2']);

        self::assertSame(
            '203.0.113.9',
            $this->ipFor('10.0.0.1', ['X-Forwarded-For' => '203.0.113.9, 10.0.0.2'])
        );
    }

    public function testACidrRangeOfProxiesIsHonoured(): void
    {
        $this->trustProxies(['10.0.0.0/8']);

        self::assertSame(
            '203.0.113.9',
            $this->ipFor('10.4.5.6', ['X-Forwarded-For' => '203.0.113.9, 10.7.8.9'])
        );
    }

    /** A request that never left the perimeter still has to resolve to something. */
    public function testAChainOfOnlyTrustedHopsFallsBackToTheNearestEntry(): void
    {
        $this->trustProxies(['10.0.0.0/8']);

        self::assertSame('10.0.0.5', $this->ipFor('10.0.0.1', ['X-Forwarded-For' => '10.0.0.7, 10.0.0.5']));
    }

    // ─── Malformed input ─────────────────────────────────────────────

    public function testGarbageEntriesAreSkipped(): void
    {
        $this->trustProxies(['10.0.0.1']);

        self::assertSame(
            '203.0.113.9',
            $this->ipFor('10.0.0.1', ['X-Forwarded-For' => 'not-an-ip, <script>, 203.0.113.9'])
        );
    }

    public function testAnEntirelyInvalidChainFallsBackToThePeer(): void
    {
        $this->trustProxies(['10.0.0.1']);

        self::assertSame('10.0.0.1', $this->ipFor('10.0.0.1', ['X-Forwarded-For' => 'nonsense, garbage']));
    }

    public function testAPortIsStrippedFromAForwardedEntry(): void
    {
        $this->trustProxies(['10.0.0.1']);

        self::assertSame('203.0.113.9', $this->ipFor('10.0.0.1', ['X-Forwarded-For' => '203.0.113.9:51000']));
    }

    public function testABracketedIpv6EntryIsUnwrapped(): void
    {
        $this->trustProxies(['10.0.0.1']);

        self::assertSame('2001:db8::1', $this->ipFor('10.0.0.1', ['X-Forwarded-For' => '[2001:db8::1]:443']));
    }

    /** RFC 7239 spells it `for=`, and some proxies emit that into Forwarded. */
    public function testTheRfc7239ForPrefixIsUnderstood(): void
    {
        $this->trustProxies(['10.0.0.1']);

        self::assertSame('203.0.113.9', $this->ipFor('10.0.0.1', ['Forwarded' => 'for=203.0.113.9']));
    }

    // ─── Header precedence ───────────────────────────────────────────

    /**
     * CF-Connecting-IP is written by the edge and cannot be forwarded by the
     * caller, so it outranks X-Forwarded-For. Client-IP is the opposite — trivial
     * for anyone to set — so it is consulted last.
     */
    public function testTheEdgeHeaderWinsOverTheForwardedChain(): void
    {
        $this->trustProxies(['10.0.0.1']);

        self::assertSame(
            '203.0.113.9',
            $this->ipFor('10.0.0.1', [
                'CF-Connecting-IP' => '203.0.113.9',
                'X-Forwarded-For' => '1.2.3.4',
            ])
        );
    }

    public function testClientIpDoesNotOverrideTheForwardedChain(): void
    {
        $this->trustProxies(['10.0.0.1']);

        self::assertSame(
            '203.0.113.9',
            $this->ipFor('10.0.0.1', [
                'Client-IP' => '1.2.3.4',
                'X-Forwarded-For' => '203.0.113.9',
            ])
        );
    }

    // ─── The peer itself ─────────────────────────────────────────────

    public function testAnInvalidRemoteAddrFallsBackToLoopback(): void
    {
        $this->trustProxies([]);

        self::assertSame('127.0.0.1', $this->ipFor('not-an-address'));
    }
}
