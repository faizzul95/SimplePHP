<?php

declare(strict_types=1);

namespace Tests\Unit\Server;

use Core\Http\Emitter;
use Core\Http\HtmlResponse;
use Core\Http\Request;
use Core\Http\Response;
use Core\Server\WorkerState;
use PHPUnit\Framework\TestCase;

/**
 * WorkerState::flush() reset six classes and one service. Everything else survived
 * the request boundary, including framework_service('auth') — and Components\Auth
 * caches the resolved user and their ACL set on the instance. Request N+1 could
 * therefore be served request N's identity.
 *
 */
final class WorkerIsolationTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $serverBackup = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->serverBackup = $_SERVER;
        WorkerState::reset();
        Emitter::reset();
        reset_framework_service();
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        WorkerState::reset();
        Emitter::reset();
        reset_framework_service();

        parent::tearDown();
    }

    // ─── The identity leak ───────────────────────────────────────────

    public function testFlushDropsResolvedServiceInstances(): void
    {
        register_framework_service('auth', static fn(): object => new \stdClass());

        $first = framework_service('auth');
        self::assertSame($first, framework_service('auth'), 'Sanity: the service is memoised.');

        WorkerState::flush(resetSuperglobals: false);

        self::assertNotSame(
            $first,
            framework_service('auth'),
            'A resolved auth instance survived the request boundary — it caches the '
            . 'authenticated user and their permissions, so the next caller inherits them.'
        );
    }

    public function testFlushKeepsResolversRegisteredSoProvidersNeedNotRerun(): void
    {
        register_framework_service('feature', static fn(): object => new \stdClass());

        WorkerState::flush(resetSuperglobals: false);

        // Providers boot once per worker, not once per request. Clearing the
        // resolvers as well as the instances would break every later request.
        self::assertInstanceOf(\stdClass::class, framework_service('feature'));
    }

    public function testResetFrameworkServiceInstancesKeepsResolvers(): void
    {
        register_framework_service('logger', static fn(): object => new \stdClass());
        $first = framework_service('logger');

        reset_framework_service_instances();

        $second = framework_service('logger');

        self::assertNotSame($first, $second);
        self::assertInstanceOf(\stdClass::class, $second);
    }

    // ─── Static request/response state ───────────────────────────────

    public function testFlushClearsTheCurrentRequest(): void
    {
        Request::setCurrent(new Request([], [], [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/one',
            'SCRIPT_NAME' => '/index.php',
            'HTTP_HOST' => 'localhost',
        ]));

        self::assertNotNull(Request::current());

        WorkerState::flush(resetSuperglobals: false);

        self::assertNull(Request::current(), 'The previous request object leaked into the next cycle.');
    }

    public function testFlushClearsPendingLinkHeaders(): void
    {
        Response::preload('/leaked.css', 'style');
        self::assertNotEmpty(Response::pendingLinkHeaders());

        WorkerState::flush(resetSuperglobals: false);

        self::assertSame([], Response::pendingLinkHeaders(), 'Preload hints leaked into the next response.');
    }

    public function testFlushClearsTheEmittedFlag(): void
    {
        ob_start();
        Emitter::send(new HtmlResponse('first request'));
        ob_end_clean();

        self::assertTrue(Emitter::hasSent());

        WorkerState::flush(resetSuperglobals: false);

        self::assertFalse(
            Emitter::hasSent(),
            'A sticky emit flag would make every later request on this worker silent.'
        );
    }

    // ─── Superglobal residue ─────────────────────────────────────────

    public function testFlushRestoresTheServerBaselineSoHeadersDoNotLeak(): void
    {
        $_SERVER = ['SERVER_SOFTWARE' => 'rr'];
        WorkerState::captureBaseline();

        // Request 1 arrives behind a proxy.
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.7';
        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
        $_GET = ['q' => 'one'];
        $_POST = ['a' => 'b'];
        $_COOKIE = ['sid' => 'abc'];

        WorkerState::flush();

        // Request 2 does not. It must not inherit request 1's client IP, or rate
        // limiting, IP blocklisting and the audit log all attribute it wrongly.
        self::assertArrayNotHasKey('HTTP_X_FORWARDED_FOR', $_SERVER);
        self::assertSame(['SERVER_SOFTWARE' => 'rr'], $_SERVER);
        self::assertSame([], $_GET);
        self::assertSame([], $_POST);
        self::assertSame([], $_COOKIE);
    }

    public function testFlushLeavesSuperglobalsAloneWhenAsked(): void
    {
        // FrankenPHP populates them itself before invoking the handler.
        $_SERVER['HTTP_X_CUSTOM'] = 'kept';

        WorkerState::flush(resetSuperglobals: false);

        self::assertSame('kept', $_SERVER['HTTP_X_CUSTOM']);
    }

    // ─── Registration ────────────────────────────────────────────────

    public function testQueueDispatcherIsFlushedBetweenRequests(): void
    {
        self::assertContains(
            \Core\Queue\Dispatcher::class,
            WorkerState::registered(),
            'Dispatcher caches which tables it has verified; that must not span requests.'
        );
    }

    public function testRegisterAddsAClassToTheFlushSet(): void
    {
        WorkerState::register(WorkerStatefulProbe::class);

        WorkerStatefulProbe::$resets = 0;
        WorkerState::flush(resetSuperglobals: false);

        self::assertSame(1, WorkerStatefulProbe::$resets);
    }

    public function testAFailingResetDoesNotAbortTheRestOfTheFlush(): void
    {
        WorkerState::register(WorkerThrowingProbe::class);
        WorkerState::register(WorkerStatefulProbe::class);

        WorkerStatefulProbe::$resets = 0;

        WorkerState::flush(resetSuperglobals: false);

        self::assertSame(
            1,
            WorkerStatefulProbe::$resets,
            'One uncooperative class must not leave the rest of the process dirty.'
        );
    }
}

final class WorkerStatefulProbe
{
    public static int $resets = 0;

    public static function reset(): void
    {
        self::$resets++;
    }
}

final class WorkerThrowingProbe
{
    public static function reset(): void
    {
        throw new \RuntimeException('probe failure');
    }
}
