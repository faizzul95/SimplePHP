<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use Core\Http\Request;
use Core\Http\ResponseEmitted;
use Core\Security\RequestSignature;
use PHPUnit\Framework\TestCase;

/**
 * A bearer token cannot be attached to a cross-site request by the browser, so
 * CSRF has nothing to defend on an API — a CSRF token there is a value the client
 * fetches and echoes, which anyone able to make the request could also fetch.
 *
 * What a token client is exposed to is replay: a request captured once, from a
 * proxied debug build or a rooted device, sent again. These cover the control
 * that actually stops it.
 */
final class RequestSignatureTest extends TestCase
{
    private const KEY = 'tok_abc123secretvalue';

    /** @var array<string, mixed> */
    private array $serverBackup = [];

    /** @var array<string, mixed> */
    private array $configBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->serverBackup = $_SERVER;
        $this->configBackup = $GLOBALS['config'] ?? [];

        $GLOBALS['config']['security']['request_signing'] = [
            'enabled' => true,
            'algorithm' => 'sha256',
            'tolerance_seconds' => 300,
            'nonce_ttl' => 600,
            'shared_secret' => '',
            'header' => 'X-Signature',
            'timestamp_header' => 'X-Timestamp',
            'nonce_header' => 'X-Nonce',
        ];

        try {
            cache()->flush();
        } catch (\Throwable) {
            // No cache configured; the nonce check fails open, which the tests note.
        }

        // The middleware asks auth() for the presented bearer token. Only that
        // one method matters here, so the double reads it straight off the header
        // rather than pulling in the whole token service and its database.
        register_framework_service('auth', static fn(): object => new class {
            public function bearerToken(): ?string
            {
                $header = (string) (\Core\Http\Request::current()?->header('Authorization', '') ?? '');

                return preg_match('/^Bearer\s+(.+)$/i', $header, $matches) === 1
                    ? trim($matches[1])
                    : null;
            }
        });
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        $GLOBALS['config'] = $this->configBackup;
        Request::setCurrent(null);
        reset_framework_service();
        parent::tearDown();
    }

    // ─── The canonical string ────────────────────────────────────────

    public function testTheCanonicalFormIsStableForTheSameRequest(): void
    {
        $a = RequestSignature::canonical('POST', '/api/v1/orders', '1700000000', 'nonce-value-0001', '{"id":1}');
        $b = RequestSignature::canonical('post', 'api/v1/orders', '1700000000', 'nonce-value-0001', '{"id":1}');

        self::assertSame($a, $b, 'Case and a leading slash must not change the signature.');
    }

    /** @return array<string, array{0:array{0:string,1:string,2:string,3:string,4:string}}> */
    public static function alteredRequestProvider(): array
    {
        $base = ['POST', '/api/v1/orders', '1700000000', 'nonce-value-0001', '{"amount":10}'];

        return [
            'method changed' => [array_replace($base, [0 => 'DELETE'])],
            'path changed' => [array_replace($base, [1 => '/api/v1/admin'])],
            'timestamp changed' => [array_replace($base, [2 => '1700000001'])],
            'nonce changed' => [array_replace($base, [3 => 'nonce-value-0002'])],
            'body changed' => [array_replace($base, [4 => '{"amount":100000}'])],
        ];
    }

    /** @param array{0:string,1:string,2:string,3:string,4:string} $altered */
    #[\PHPUnit\Framework\Attributes\DataProvider('alteredRequestProvider')]
    public function testAnyAlterationChangesTheSignature(array $altered): void
    {
        $original = RequestSignature::sign(
            RequestSignature::canonical('POST', '/api/v1/orders', '1700000000', 'nonce-value-0001', '{"amount":10}'),
            self::KEY
        );

        $tampered = RequestSignature::sign(RequestSignature::canonical(...$altered), self::KEY);

        self::assertNotSame($original, $tampered);
    }

    public function testADifferentKeyProducesADifferentSignature(): void
    {
        $canonical = RequestSignature::canonical('POST', '/x', '1700000000', 'nonce-value-0001', '');

        self::assertNotSame(
            RequestSignature::sign($canonical, 'key-one'),
            RequestSignature::sign($canonical, 'key-two')
        );
    }

    public function testAValidSignatureMatches(): void
    {
        $canonical = RequestSignature::canonical('POST', '/x', '1700000000', 'nonce-value-0001', 'body');

        self::assertTrue(
            RequestSignature::matches(RequestSignature::sign($canonical, self::KEY), $canonical, self::KEY)
        );
    }

    /** A client that omits the `v1=` prefix should fail on the digest, not the format. */
    public function testABareHexDigestIsAccepted(): void
    {
        $canonical = RequestSignature::canonical('POST', '/x', '1700000000', 'nonce-value-0001', 'body');
        $bare = substr(RequestSignature::sign($canonical, self::KEY), strlen('v1='));

        self::assertTrue(RequestSignature::matches($bare, $canonical, self::KEY));
    }

    public function testAnEmptySignatureNeverMatches(): void
    {
        $canonical = RequestSignature::canonical('POST', '/x', '1700000000', 'nonce-value-0001', '');

        self::assertFalse(RequestSignature::matches('', $canonical, self::KEY));
        self::assertFalse(RequestSignature::matches('   ', $canonical, self::KEY));
    }

    // ─── The clock ───────────────────────────────────────────────────

    public function testATimestampInsideTheWindowIsAccepted(): void
    {
        self::assertTrue(RequestSignature::withinTolerance((string) (1700000000 - 60), 300, 1700000000));
    }

    public function testAStaleTimestampIsRejected(): void
    {
        self::assertFalse(RequestSignature::withinTolerance((string) (1700000000 - 3600), 300, 1700000000));
    }

    /**
     * The future side matters as much as the past: accepting forward timestamps
     * would let a captured request be stockpiled and replayed indefinitely.
     */
    public function testAFutureTimestampIsAlsoRejected(): void
    {
        self::assertFalse(RequestSignature::withinTolerance((string) (1700000000 + 3600), 300, 1700000000));
    }

    public function testANonNumericTimestampIsRejected(): void
    {
        self::assertFalse(RequestSignature::withinTolerance('yesterday', 300, 1700000000));
        self::assertFalse(RequestSignature::withinTolerance('', 300, 1700000000));
    }

    // ─── The nonce ───────────────────────────────────────────────────

    public function testAReasonableNonceIsAccepted(): void
    {
        self::assertTrue(RequestSignature::isWellFormedNonce(bin2hex(random_bytes(12))));
    }

    public function testAShortNonceIsRejected(): void
    {
        self::assertFalse(RequestSignature::isWellFormedNonce('abc'));
    }

    /**
     * The canonical string is newline-separated, so a nonce containing one could
     * shift the meaning of the fields around it.
     */
    public function testANonceCannotContainTheFieldSeparator(): void
    {
        self::assertFalse(RequestSignature::isWellFormedNonce("aaaaaaaaaaaaaaaa\nbbbb"));
        self::assertFalse(RequestSignature::isWellFormedNonce('aaaaaaaaaaaaaaaa bbbb'));
    }

    public function testAnAbsurdlyLongNonceIsRejected(): void
    {
        self::assertFalse(RequestSignature::isWellFormedNonce(str_repeat('a', 500)));
    }

    /** Scoped by key, so one client cannot burn the nonces another is about to use. */
    public function testTheNonceCacheKeyIsScopedByTheSigningKey(): void
    {
        self::assertNotSame(
            RequestSignature::nonceCacheKey('same-nonce-value', 'key-one'),
            RequestSignature::nonceCacheKey('same-nonce-value', 'key-two')
        );
    }

    // ─── The middleware ──────────────────────────────────────────────

    /** @param array<string, string> $headers */
    private function request(string $method, string $path, string $body = '', array $headers = []): Request
    {
        $server = [
            'REQUEST_METHOD' => strtoupper($method),
            'REQUEST_URI' => $path,
            'SCRIPT_NAME' => '/index.php',
            'HTTP_HOST' => 'example.test',
            'HTTP_ACCEPT' => 'application/json',
            'REMOTE_ADDR' => '203.0.113.9',
        ];

        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }

        $request = (new Request([], [], $server))->withRawBody($body);
        Request::setCurrent($request);

        return $request;
    }

    /**
     * @param  array<string, string> $overrides Replace a header after signing
     * @return array<string, string>
     */
    private function signedHeaders(string $method, string $path, string $body, array $overrides = []): array
    {
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(12));
        $canonical = RequestSignature::canonical($method, $path, $timestamp, $nonce, $body);

        return array_merge([
            'Authorization' => 'Bearer ' . self::KEY,
            'X-Timestamp' => $timestamp,
            'X-Nonce' => $nonce,
            'X-Signature' => RequestSignature::sign($canonical, self::KEY),
        ], $overrides);
    }

    private function verify(Request $request): int
    {
        $middleware = new \App\Http\Middleware\VerifyRequestSignature();

        try {
            $middleware->handle($request, static fn(): \Core\Http\JsonResponse => new \Core\Http\JsonResponse(['ok' => true]));

            return 200;
        } catch (ResponseEmitted $emitted) {
            return $emitted->response()->status();
        }
    }

    public function testACorrectlySignedRequestPasses(): void
    {
        $body = '{"amount":10}';
        $headers = $this->signedHeaders('POST', '/api/v1/orders', $body);

        self::assertSame(200, $this->verify($this->request('POST', '/api/v1/orders', $body, $headers)));
    }

    public function testAnUnsignedRequestIsRejected(): void
    {
        $request = $this->request('POST', '/api/v1/orders', '{}', ['Authorization' => 'Bearer ' . self::KEY]);

        self::assertSame(401, $this->verify($request));
    }

    /** The point of the whole thing: the same bytes twice must not both work. */
    public function testAReplayedRequestIsRejected(): void
    {
        $body = '{"amount":10}';
        $headers = $this->signedHeaders('POST', '/api/v1/orders', $body);

        self::assertSame(200, $this->verify($this->request('POST', '/api/v1/orders', $body, $headers)));
        self::assertSame(409, $this->verify($this->request('POST', '/api/v1/orders', $body, $headers)));
    }

    public function testATamperedBodyIsRejected(): void
    {
        $headers = $this->signedHeaders('POST', '/api/v1/orders', '{"amount":10}');

        // Same signature, different body.
        self::assertSame(401, $this->verify($this->request('POST', '/api/v1/orders', '{"amount":100000}', $headers)));
    }

    public function testAReplayAgainstADifferentEndpointIsRejected(): void
    {
        $body = '{"amount":10}';
        $headers = $this->signedHeaders('POST', '/api/v1/orders', $body);

        self::assertSame(401, $this->verify($this->request('POST', '/api/v1/admin/orders', $body, $headers)));
    }

    public function testAStaleCaptureIsRejected(): void
    {
        $body = '{}';
        $timestamp = (string) (time() - 3600);
        $nonce = bin2hex(random_bytes(12));
        $canonical = RequestSignature::canonical('POST', '/api/v1/orders', $timestamp, $nonce, $body);

        $request = $this->request('POST', '/api/v1/orders', $body, [
            'Authorization' => 'Bearer ' . self::KEY,
            'X-Timestamp' => $timestamp,
            'X-Nonce' => $nonce,
            'X-Signature' => RequestSignature::sign($canonical, self::KEY),
        ]);

        self::assertSame(401, $this->verify($request));
    }

    /** A signature from a different token must not be accepted for this one. */
    public function testASignatureFromAnotherTokenIsRejected(): void
    {
        $body = '{}';
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(12));
        $canonical = RequestSignature::canonical('POST', '/api/v1/orders', $timestamp, $nonce, $body);

        $request = $this->request('POST', '/api/v1/orders', $body, [
            'Authorization' => 'Bearer ' . self::KEY,
            'X-Timestamp' => $timestamp,
            'X-Nonce' => $nonce,
            'X-Signature' => RequestSignature::sign($canonical, 'a-different-token'),
        ]);

        self::assertSame(401, $this->verify($request));
    }

    /** Reads carry no body to protect, so they are exempt unless asked for. */
    public function testReadsAreExemptByDefault(): void
    {
        self::assertSame(200, $this->verify($this->request('GET', '/api/v1/orders')));
    }

    public function testReadsCanBeIncludedExplicitly(): void
    {
        $middleware = new \App\Http\Middleware\VerifyRequestSignature();
        $middleware->setParameters(['all']);

        try {
            $middleware->handle(
                $this->request('GET', '/api/v1/orders'),
                static fn(): \Core\Http\JsonResponse => new \Core\Http\JsonResponse(['ok' => true])
            );
            self::fail('An unsigned GET should be rejected under signed:all.');
        } catch (ResponseEmitted $emitted) {
            self::assertSame(401, $emitted->response()->status());
        }
    }

    /** Turned off, the middleware must be completely transparent. */
    public function testDisabledSigningLetsEverythingThrough(): void
    {
        $GLOBALS['config']['security']['request_signing']['enabled'] = false;

        self::assertSame(200, $this->verify($this->request('POST', '/api/v1/orders', '{}')));
    }

    /**
     * With signing on and no credential at all, passing through would make the
     * middleware silently optional — which is worse than rejecting.
     */
    public function testARequestWithNoSigningCredentialIsRejected(): void
    {
        self::assertSame(401, $this->verify($this->request('POST', '/api/v1/orders', '{}')));
    }
}
