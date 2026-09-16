<?php

declare(strict_types=1);

namespace Tests\Feature;

use Components\Maintenance;
use Core\Http\JsonResponse;
use Core\Http\Responsable;
use Core\Http\ResponseEmitted;
use PHPUnit\Framework\TestCase;

/**
 * PHP_SAPI is always 'cli' under PHPUnit, and handleRequest() returns
 * immediately for CLI — correctly, since a command is not a request. Overriding
 * that is the only way to reach the request path at all.
 */
final class WebMaintenance extends Maintenance
{
    protected function runsInCli(): bool
    {
        return false;
    }
}

/**
 * What a maintenance window does to each kind of caller.
 *
 * The window used to answer every request with an HTML 503, which a mobile
 * client cannot parse — so an app in a maintenance window showed a blank screen
 * rather than the message the window exists to deliver. And nothing was exempt,
 * so a load balancer that could not reach the health endpoint pulled the node
 * out of rotation and a payment provider that got a 503 stopped retrying: a
 * planned window turned into an outage plus a backlog of lost webhooks.
 */
final class MaintenanceWindowTest extends TestCase
{
    private string $downFile;

    /** @var array<string, mixed> */
    private array $serverBackup = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->serverBackup = $_SERVER;
        $this->downFile = rtrim(ROOT_DIR, '/\\') . '/storage/framework/down';

        $directory = dirname($this->downFile);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
    }

    protected function tearDown(): void
    {
        if (is_file($this->downFile)) {
            unlink($this->downFile);
        }

        $_SERVER = $this->serverBackup;

        parent::tearDown();
    }

    /** @param array<string, mixed> $payload */
    private function goDown(array $payload = []): void
    {
        file_put_contents($this->downFile, json_encode($payload + [
            'message' => 'Upgrading the database.',
            'retry' => 120,
        ]));
    }

    /** @param array<string, mixed> $config */
    private function requestDuringWindow(string $path, array $headers = [], array $config = []): ?Responsable
    {
        $_SERVER['REQUEST_URI'] = $path;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_HOST'] = 'example.test';
        $_SERVER['SCRIPT_NAME'] = '/index.php';

        foreach ($headers as $name => $value) {
            $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }

        try {
            (new WebMaintenance($config))->handleRequest();
        } catch (ResponseEmitted $emitted) {
            return $emitted->response();
        }

        return null;
    }

    // ─── The window is off ───────────────────────────────────────────

    public function testNothingHappensWhenTheAppIsUp(): void
    {
        self::assertNull($this->requestDuringWindow('/api/v1/users'));
        self::assertFalse(Maintenance::isActive());
    }

    // ─── API clients ─────────────────────────────────────────────────

    /**
     * The reason the negotiation was added: an HTML body behind a 503 is
     * unparseable to the client that most needs to read the message.
     */
    public function testAnApiClientGetsJsonRatherThanAnHtmlPage(): void
    {
        $this->goDown();

        $response = $this->requestDuringWindow('/api/v1/users', ['Accept' => 'application/json']);

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(503, $response->status());
    }

    public function testTheJsonBodyCarriesTheMessageAndTheRetryDelay(): void
    {
        $this->goDown();

        $response = $this->requestDuringWindow('/api/v1/users', ['Accept' => 'application/json']);

        self::assertInstanceOf(JsonResponse::class, $response);

        $payload = $response->payload();
        self::assertSame('service_unavailable', $payload['error']);
        self::assertSame('Upgrading the database.', $payload['message']);
        self::assertSame(120, $payload['retry_after']);
    }

    /**
     * Retry-After is what tells a well-behaved client and a load balancer when to
     * come back, instead of hammering the node through the whole window.
     */
    public function testRetryAfterIsSentAsAHeaderToo(): void
    {
        $this->goDown();

        $response = $this->requestDuringWindow('/api/v1/users', ['Accept' => 'application/json']);

        self::assertNotNull($response);
        self::assertSame('120', $response->headers()['Retry-After'] ?? null);
    }

    /** An XHR from the browser front-end is an API caller too. */
    public function testAnXhrGetsJson(): void
    {
        $this->goDown();

        $response = $this->requestDuringWindow('/dashboard', ['X-Requested-With' => 'XMLHttpRequest']);

        self::assertInstanceOf(JsonResponse::class, $response);
    }

    // ─── Browsers ────────────────────────────────────────────────────

    public function testABrowserStillGetsTheHtmlPage(): void
    {
        $this->goDown();

        $response = $this->requestDuringWindow('/dashboard', ['Accept' => 'text/html']);

        self::assertNotNull($response);
        self::assertSame(503, $response->status());
        self::assertStringContainsString('text/html', $response->headers()['Content-Type'] ?? '');
    }

    // ─── Exempt paths ────────────────────────────────────────────────

    /**
     * A health check that 503s during a planned window makes the orchestrator
     * remove the node, which turns the window into an outage.
     */
    public function testAnExemptPathStaysReachable(): void
    {
        $this->goDown();

        $response = $this->requestDuringWindow(
            '/api/v1/health',
            ['Accept' => 'application/json'],
            ['allowed_paths' => ['api/v1/health', 'up']]
        );

        self::assertNull($response, 'An exempt path must pass straight through.');
    }

    public function testExemptPathsAcceptWildcards(): void
    {
        $this->goDown();

        $response = $this->requestDuringWindow(
            '/webhooks/stripe',
            ['Accept' => 'application/json'],
            ['allowed_paths' => ['webhooks/*']]
        );

        self::assertNull($response);
    }

    /** The exemption must be exactly what was configured, not a prefix of it. */
    public function testAPathOutsideTheAllowListIsStillBlocked(): void
    {
        $this->goDown();

        $response = $this->requestDuringWindow(
            '/api/v1/users',
            ['Accept' => 'application/json'],
            ['allowed_paths' => ['api/v1/health']]
        );

        self::assertNotNull($response);
        self::assertSame(503, $response->status());
    }

    public function testNoAllowListMeansNothingIsExempt(): void
    {
        $this->goDown();

        $response = $this->requestDuringWindow('/api/v1/health', ['Accept' => 'application/json']);

        self::assertNotNull($response);
        self::assertSame(503, $response->status());
    }

    // ─── The scheduler and the worker ────────────────────────────────

    /**
     * The scheduler and the queue worker each need to know the app is down, and
     * neither has a Maintenance instance. Both used to re-implement the file
     * check, which is how the three could disagree about whether the app was up.
     */
    public function testTheStaticCheckAgreesWithTheInstanceCheck(): void
    {
        self::assertFalse(Maintenance::isActive());
        self::assertFalse((new Maintenance())->active());

        $this->goDown();

        self::assertTrue(Maintenance::isActive());
        self::assertTrue((new Maintenance())->active());
    }

    public function testTheSchedulerReadsTheSameSignal(): void
    {
        $this->goDown();

        $schedule = new \Core\Console\Schedule();
        $method = new \ReflectionMethod($schedule, 'isInMaintenanceMode');
        $method->setAccessible(true);

        self::assertTrue($method->invoke($schedule));
    }

    /**
     * A CLI process is not a request. Aborting `php myth migrate` because the app
     * is down would make it impossible to run the migration the window exists for
     * — so the real class, whose runsInCli() is not overridden, passes through.
     */
    public function testCliIsNeverBlockedByTheWindow(): void
    {
        $this->goDown();

        $_SERVER['REQUEST_URI'] = '/api/v1/users';
        $_SERVER['HTTP_ACCEPT'] = 'application/json';

        $blocked = false;

        try {
            (new Maintenance())->handleRequest();
        } catch (ResponseEmitted) {
            $blocked = true;
        }

        self::assertFalse($blocked, 'A command must run through a maintenance window.');
    }
}
