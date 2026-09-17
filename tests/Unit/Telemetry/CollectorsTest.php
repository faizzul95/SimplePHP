<?php

declare(strict_types=1);

namespace Tests\Unit\Telemetry;

use Core\Cache\CacheManager;
use Core\Support\HttpClient;
use Core\Telemetry\Entry;
use Core\Telemetry\Gate;
use Core\Telemetry\Recorder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The cache and outbound-HTTP collectors.
 *
 * Both instrument code that already worked, so the tests split in two: the
 * instrumented class must still behave exactly as before, and the telemetry
 * must describe what happened.
 */
final class CollectorsTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/myth-collect-' . bin2hex(random_bytes(6));
        reset_framework_service('telemetry');
    }

    protected function tearDown(): void
    {
        reset_framework_service('telemetry');

        foreach (glob($this->directory . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);

        parent::tearDown();
    }

    /** Bind a recording recorder into the container the collectors reach for. */
    private function recording(): Recorder
    {
        $recorder = new Recorder([
            'enabled' => true,
            'mode' => Gate::MODE_ALL,
            'storage' => ['path' => $this->directory],
        ]);

        register_framework_service('telemetry', static fn(): Recorder => $recorder);

        return $recorder;
    }

    private function cache(): CacheManager
    {
        return new CacheManager(['default' => 'array', 'stores' => ['array' => ['driver' => 'array']]]);
    }

    /** @return list<Entry> */
    private function cacheEntries(Recorder $recorder): array
    {
        return array_values(array_filter(
            $recorder->entries(),
            static fn(Entry $e): bool => $e->type === Entry::TYPE_CACHE
        ));
    }

    // ─── Cache: behaviour is unchanged ───────────────────────────────

    public function testAMissStillReturnsTheCallersDefault(): void
    {
        $this->recording();

        self::assertSame('fallback', $this->cache()->get('absent', 'fallback'));
        self::assertNull($this->cache()->get('absent'));
    }

    public function testAHitStillReturnsTheStoredValue(): void
    {
        $this->recording();
        $cache = $this->cache();
        $cache->put('k', 'v', 60);

        self::assertSame('v', $cache->get('k', 'fallback'));
    }

    /**
     * The sentinel exists for this case: a stored null is a hit, and must not
     * come back as the caller's default.
     */
    public function testAStoredNullIsAHitNotAMiss(): void
    {
        $recorder = $this->recording();
        $cache = $this->cache();
        $cache->put('nullable', null, 60);

        self::assertNull($cache->get('nullable', 'fallback'), 'stored null wins over the default');

        $reads = array_values(array_filter(
            $this->cacheEntries($recorder),
            static fn(Entry $e): bool => in_array($e->payload['operation'], ['hit', 'miss'], true)
        ));

        self::assertSame('hit', end($reads)->payload['operation']);
    }

    // ─── Cache: what gets recorded ───────────────────────────────────

    public function testHitsAndMissesAreRecordedSeparately(): void
    {
        $recorder = $this->recording();
        $cache = $this->cache();

        $cache->get('cold');
        $cache->put('warm', 1, 60);
        $cache->get('warm');

        $operations = array_map(
            static fn(Entry $e): string => $e->payload['operation'],
            $this->cacheEntries($recorder)
        );

        self::assertSame(['miss', 'put', 'hit'], $operations);
    }

    public function testForgetIsRecorded(): void
    {
        $recorder = $this->recording();
        $cache = $this->cache();
        $cache->put('k', 1, 60);
        $cache->forget('k');

        $operations = array_map(
            static fn(Entry $e): string => $e->payload['operation'],
            $this->cacheEntries($recorder)
        );

        self::assertContains('forget', $operations);
    }

    /** A cache holds rendered pages; writing them to disk on every read is not on. */
    public function testTheCachedValueIsNeverRecorded(): void
    {
        $recorder = $this->recording();
        $cache = $this->cache();
        $cache->put('k', 'super-secret-cached-body', 60);
        $cache->get('k');

        foreach ($this->cacheEntries($recorder) as $entry) {
            self::assertStringNotContainsString(
                'super-secret-cached-body',
                json_encode($entry->payload) ?: ''
            );
        }
    }

    public function testTheKeyAndStoreAreRecorded(): void
    {
        $recorder = $this->recording();
        $this->cache()->get('user:42:profile');

        $payload = $this->cacheEntries($recorder)[0]->payload;

        self::assertSame('user:42:profile', $payload['key']);
        self::assertSame('array', $payload['store']);
    }

    public function testRememberShowsUpAsAMissFollowedByAPut(): void
    {
        $recorder = $this->recording();

        $value = $this->cache()->remember('computed', 60, static fn(): string => 'built');

        self::assertSame('built', $value);

        $operations = array_map(
            static fn(Entry $e): string => $e->payload['operation'],
            $this->cacheEntries($recorder)
        );

        self::assertSame('miss', $operations[0]);
        self::assertContains('put', $operations);
    }

    /** With telemetry off the cache must still work and record nothing. */
    public function testTheCacheWorksWithTelemetryDisabled(): void
    {
        register_framework_service('telemetry', static fn(): Recorder => new Recorder(['enabled' => false]));

        $cache = $this->cache();
        $cache->put('k', 'v', 60);

        self::assertSame('v', $cache->get('k'));
        self::assertSame([], telemetry()->entries());
    }

    // ─── Outbound HTTP: URL redaction ────────────────────────────────

    /**
     * An outbound URL routinely carries a key as a query parameter, and the
     * recorder's key-based redaction cannot reach inside a string.
     */
    #[DataProvider('urlsCarryingSecrets')]
    public function testSecretQueryParametersAreRedacted(string $url, string $secret): void
    {
        $redacted = $this->redactUrl($url);

        self::assertStringNotContainsString($secret, $redacted);
        self::assertStringContainsString('redacted', $redacted);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function urlsCarryingSecrets(): array
    {
        return [
            'api_key' => ['https://api.example.test/v1/users?api_key=sk-live-abc123', 'sk-live-abc123'],
            'token' => ['https://api.example.test/v1?token=eyJhbGciOi', 'eyJhbGciOi'],
            'signature' => ['https://api.example.test/v1?sig=deadbeef&page=2', 'deadbeef'],
            'password' => ['https://api.example.test/login?password=hunter2', 'hunter2'],
            'access_token' => ['https://api.example.test/me?access_token=xyz789', 'xyz789'],
        ];
    }

    public function testNonSecretParametersSurviveIntact(): void
    {
        $url = 'https://api.example.test/v1/users?page=2&per_page=50';

        self::assertSame($url, $this->redactUrl($url));
    }

    public function testAUrlWithNoQueryStringIsUntouched(): void
    {
        $url = 'https://api.example.test/v1/users';

        self::assertSame($url, $this->redactUrl($url));
    }

    public function testTheNonSecretHalfOfAMixedQuerySurvives(): void
    {
        $redacted = $this->redactUrl('https://api.example.test/v1?api_key=secret123&page=7');

        self::assertStringNotContainsString('secret123', $redacted);
        self::assertStringContainsString('page=7', $redacted);
    }

    private function redactUrl(string $url): string
    {
        $method = new \ReflectionMethod(HttpClient::class, 'redactUrl');

        return (string) $method->invoke(null, $url);
    }
}
