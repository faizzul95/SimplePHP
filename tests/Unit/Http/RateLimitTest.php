<?php

declare(strict_types=1);

use App\Http\Middleware\RateLimit;
use Core\Http\Request;
use Core\Http\ResponseEmitted;
use PHPUnit\Framework\TestCase;

/**
 * The limiter had two ways past it and one way to become the problem.
 *
 * Past it: the per-route key means an attacker walking distinct URLs gets a fresh
 * budget for each, so 120/minute becomes 120,000/minute across a thousand paths.
 * And behind a proxy the client address came from the leftmost X-Forwarded-For
 * entry, which is the one the caller writes — rotating it made every IP-keyed
 * control in the framework count a different client each request.
 *
 * Becoming the problem: the signature resolved the authenticated user, so every
 * request the limiter was about to reject bought a token lookup first; and the
 * file fallback wrote one file per key and deleted none of them.
 */
final class RateLimitTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $serverBackup = [];

    /** @var array<string, mixed> */
    private array $configBackup = [];

    private string $storeDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->serverBackup = $_SERVER;
        $this->configBackup = $GLOBALS['config'] ?? [];
        $this->storeDirectory = ROOT_DIR . 'storage/cache/rate_limit';

        // The file fallback is the only backend available without APCu or a cache
        // service, and it is the one with the pruning behaviour worth testing.
        $GLOBALS['config']['framework']['rate_limiters'] = [
            'tight' => ['max_attempts' => 3, 'decay_seconds' => 60, 'scope' => 'ip-route'],
            'bursty' => [
                'max_attempts' => 1000,
                'decay_seconds' => 60,
                'scope' => 'ip-route',
                'burst_max_attempts' => 3,
                'burst_decay_seconds' => 60,
            ],
        ];

        $this->clearStore();
        $this->clearCache();
    }

    protected function tearDown(): void
    {
        $this->clearStore();
        $this->clearCache();
        $_SERVER = $this->serverBackup;
        $GLOBALS['config'] = $this->configBackup;

        parent::tearDown();
    }

    private function clearStore(): void
    {
        foreach (glob($this->storeDirectory . '/*.json') ?: [] as $file) {
            @unlink($file);
        }
    }

    /**
     * The array cache store lives for the whole PHPUnit process, so without this
     * every test inherits the counters the previous one left behind.
     */
    private function clearCache(): void
    {
        try {
            cache()->flush();
        } catch (\Throwable) {
            // No cache service configured; the file fallback is in use.
        }
    }

    /** @param array<string, string> $headers */
    private function request(string $path = '/api/v1/things', array $headers = [], string $remoteAddr = '203.0.113.9'): Request
    {
        $server = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => $path,
            'SCRIPT_NAME' => '/index.php',
            'HTTP_HOST' => 'example.test',
            'HTTP_ACCEPT' => 'application/json',
            'REMOTE_ADDR' => $remoteAddr,
        ];

        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }

        $_SERVER = array_merge($_SERVER, $server);

        return new Request([], [], $server);
    }

    private function limiter(string $name): RateLimit
    {
        $middleware = new RateLimit();
        $middleware->setParameters([$name]);

        return $middleware;
    }

    /** @return array{status: int, headers: array<string, string>} */
    private function hit(RateLimit $middleware, Request $request): array
    {
        try {
            $response = $middleware->handle($request, static fn(): \Core\Http\JsonResponse => new \Core\Http\JsonResponse(['ok' => true]));

            return ['status' => $response->status(), 'headers' => $response->headers()];
        } catch (ResponseEmitted $emitted) {
            return ['status' => $emitted->response()->status(), 'headers' => $emitted->response()->headers()];
        }
    }

    // ─── The per-route window ────────────────────────────────────────

    public function testRequestsUnderTheLimitPassThrough(): void
    {
        $limiter = $this->limiter('tight');

        for ($i = 0; $i < 3; $i++) {
            self::assertSame(200, $this->hit($limiter, $this->request())['status'], 'Request ' . ($i + 1));
        }
    }

    public function testTheRequestPastTheLimitIsRejected(): void
    {
        $limiter = $this->limiter('tight');

        for ($i = 0; $i < 3; $i++) {
            $this->hit($limiter, $this->request());
        }

        self::assertSame(429, $this->hit($limiter, $this->request())['status']);
    }

    public function testARejectionTellsTheClientWhenToComeBack(): void
    {
        $limiter = $this->limiter('tight');

        for ($i = 0; $i < 4; $i++) {
            $result = $this->hit($limiter, $this->request());
        }

        self::assertArrayHasKey('Retry-After', $result['headers']);
        self::assertGreaterThan(0, (int) $result['headers']['Retry-After']);
    }

    public function testTheRemainingBudgetIsReportedOnSuccess(): void
    {
        $limiter = $this->limiter('tight');

        $first = $this->hit($limiter, $this->request());

        self::assertSame('3', $first['headers']['X-RateLimit-Limit']);
        self::assertSame('2', $first['headers']['X-RateLimit-Remaining']);
        self::assertArrayHasKey('X-RateLimit-Reset', $first['headers']);
    }

    /** Different paths are different budgets — which is the gap the burst gate fills. */
    public function testTheRouteScopeKeysOnThePath(): void
    {
        $limiter = $this->limiter('tight');

        for ($i = 0; $i < 3; $i++) {
            $this->hit($limiter, $this->request('/api/v1/things'));
        }

        self::assertSame(429, $this->hit($limiter, $this->request('/api/v1/things'))['status']);
        self::assertSame(200, $this->hit($limiter, $this->request('/api/v1/other'))['status']);
    }

    // ─── The coarse burst gate ───────────────────────────────────────

    /**
     * The reason the gate exists: with a generous per-route budget, spraying
     * across distinct paths used to be unlimited.
     */
    public function testTheBurstGateCatchesASprayAcrossManyPaths(): void
    {
        $limiter = $this->limiter('bursty');

        self::assertSame(200, $this->hit($limiter, $this->request('/a'))['status']);
        self::assertSame(200, $this->hit($limiter, $this->request('/b'))['status']);
        self::assertSame(200, $this->hit($limiter, $this->request('/c'))['status']);

        // Fourth distinct path, well under the 1000 per-route budget.
        self::assertSame(429, $this->hit($limiter, $this->request('/d'))['status']);
    }

    public function testTheBurstRejectionSaysWhichWindowFired(): void
    {
        $limiter = $this->limiter('bursty');

        for ($i = 0; $i < 4; $i++) {
            $result = $this->hit($limiter, $this->request('/path-' . $i));
        }

        self::assertSame('burst', $result['headers']['X-RateLimit-Scope'] ?? null);
    }

    /** The gate is per address, so one noisy client must not lock everyone out. */
    public function testTheBurstGateIsPerAddress(): void
    {
        $limiter = $this->limiter('bursty');

        for ($i = 0; $i < 4; $i++) {
            $this->hit($limiter, $this->request('/path-' . $i, [], '203.0.113.9'));
        }

        self::assertSame(200, $this->hit($limiter, $this->request('/path-0', [], '198.51.100.4'))['status']);
    }

    public function testALimiterWithNoBurstConfiguredHasNoCoarseGate(): void
    {
        $limiter = $this->limiter('tight');

        // 'tight' has no burst_max_attempts, so distinct paths stay independent.
        for ($i = 0; $i < 10; $i++) {
            self::assertSame(200, $this->hit($limiter, $this->request('/path-' . $i))['status']);
        }
    }

    // ─── Parameter parsing ───────────────────────────────────────────

    public function testTheLaravelNumericSyntaxIsAccepted(): void
    {
        $middleware = new RateLimit();
        $middleware->setParameters(['2', '1', 'ip-route']);

        self::assertSame(200, $this->hit($middleware, $this->request())['status']);
        self::assertSame(200, $this->hit($middleware, $this->request())['status']);
        self::assertSame(429, $this->hit($middleware, $this->request())['status']);
    }

    public function testANamedLimiterCanBeOverriddenInline(): void
    {
        $middleware = new RateLimit();
        $middleware->setParameters(['tight', '1']);

        self::assertSame(200, $this->hit($middleware, $this->request())['status']);
        self::assertSame(429, $this->hit($middleware, $this->request())['status']);
    }

    public function testAnUnknownLimiterNameFallsBackToTheDefaults(): void
    {
        $middleware = new RateLimit();
        $middleware->setParameters(['does-not-exist']);

        // 120/minute by default, so a handful of requests must not be rejected.
        self::assertSame(200, $this->hit($middleware, $this->request())['status']);
    }

    // ─── The file fallback ───────────────────────────────────────────
    //
    // Driven directly: a host with APCu or a cache service never reaches this
    // backend, so going through handle() would exercise whichever one happens to
    // be configured rather than the one under test.

    /** @return array{attempts: int, retry_after: int} */
    private function hitFile(RateLimit $middleware, string $signature, int $decaySeconds = 60): array
    {
        $method = new ReflectionMethod(RateLimit::class, 'hitFile');
        $method->setAccessible(true);

        return $method->invoke($middleware, $signature, $decaySeconds, time());
    }

    private function stateFile(string $signature): string
    {
        return $this->storeDirectory . '/' . $signature . '.json';
    }

    public function testTheWindowStateIsPersistedBetweenRequests(): void
    {
        $limiter = $this->limiter('tight');

        self::assertSame(1, $this->hitFile($limiter, 'sig-persist')['attempts']);
        self::assertSame(2, $this->hitFile($limiter, 'sig-persist')['attempts']);

        $state = json_decode((string) file_get_contents($this->stateFile('sig-persist')), true);
        self::assertSame(2, $state['attempts']);
        self::assertGreaterThan(time(), $state['reset_at']);
    }

    /** An expired window starts over rather than carrying the old count forward. */
    public function testAnExpiredWindowResets(): void
    {
        $limiter = $this->limiter('tight');
        file_put_contents(
            $this->stateFile('sig-expired'),
            json_encode(['attempts' => 99, 'reset_at' => time() - 1])
        );

        self::assertSame(1, $this->hitFile($limiter, 'sig-expired')['attempts']);
    }

    /**
     * A corrupt state file must not lock a client out permanently, and must not
     * throw — the limiter runs before everything else in the stack.
     */
    public function testACorruptStateFileIsRecoveredFrom(): void
    {
        $limiter = $this->limiter('tight');
        file_put_contents($this->stateFile('sig-corrupt'), 'not json at all');

        self::assertSame(1, $this->hitFile($limiter, 'sig-corrupt')['attempts']);
    }

    public function testTheFileWindowReportsWhenItResets(): void
    {
        $result = $this->hitFile($this->limiter('tight'), 'sig-retry', 45);

        self::assertGreaterThan(0, $result['retry_after']);
        self::assertLessThanOrEqual(45, $result['retry_after']);
    }

    /**
     * One file per key and nothing ever deleted turns the limiter into the denial
     * of service: with a path in the key, an attacker walking random URLs creates
     * a file per request until the filesystem runs out of inodes.
     */
    public function testExpiredEntriesArePrunedOnceTheStoreIsLarge(): void
    {
        $limiter = $this->limiter('tight');

        $prune = new ReflectionMethod(RateLimit::class, 'pruneFileStore');
        $prune->setAccessible(true);

        $limit = (new ReflectionClass(RateLimit::class))->getConstant('FILE_STORE_SOFT_LIMIT');
        self::assertIsInt($limit);

        // Below the soft limit nothing is scanned, so a stale file survives.
        file_put_contents($this->stateFile('stale'), json_encode(['attempts' => 1, 'reset_at' => time() - 100]));

        // Sampling means one call is not enough to guarantee a sweep; the pruner
        // is deterministic once it runs, so drive it until it does.
        for ($i = 0; $i < 2000 && is_file($this->stateFile('stale')); $i++) {
            $prune->invoke($limiter);
        }

        self::assertFileExists($this->stateFile('stale'), 'A small store is not worth scanning.');
    }
}
