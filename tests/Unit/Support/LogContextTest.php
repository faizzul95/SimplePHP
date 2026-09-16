<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use Components\Logger;
use Core\Support\LogContext;
use PHPUnit\Framework\TestCase;

/**
 * The response already told the client `X-Request-Id: req_ab12…`. Until now the
 * log did not know that id, so the one piece of evidence a user could quote
 * matched nothing on disk.
 */
final class LogContextTest extends TestCase
{
    private string $logFile;

    protected function setUp(): void
    {
        parent::setUp();

        LogContext::reset();
        $this->logFile = sys_get_temp_dir() . '/myth-logcontext-' . bin2hex(random_bytes(6)) . '.log';
    }

    protected function tearDown(): void
    {
        LogContext::reset();

        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }

        parent::tearDown();
    }

    private function logger(): Logger
    {
        return new Logger($this->logFile);
    }

    private function contents(): string
    {
        return is_file($this->logFile) ? (string) file_get_contents($this->logFile) : '';
    }

    // ─── The tag ─────────────────────────────────────────────────────

    public function testNoContextMeansNoEmptyBrackets(): void
    {
        self::assertSame('', LogContext::tag());
    }

    public function testTheTagLeadsWithTheRequestId(): void
    {
        LogContext::set(['request_id' => 'req_ab12', 'method' => 'POST', 'path' => '/api/v1/users']);

        self::assertSame('[req_ab12 POST /api/v1/users]', LogContext::tag());
    }

    public function testTheUserIdIsMarkedSoItIsNotMistakenForAPath(): void
    {
        LogContext::set(['request_id' => 'req_ab12', 'user_id' => 42]);

        self::assertSame('[req_ab12 u:42]', LogContext::tag());
    }

    public function testAdHocKeysStillReachTheLine(): void
    {
        LogContext::set(['request_id' => 'req_ab12', 'job' => 'SendInvoice']);

        self::assertSame('[req_ab12 job=SendInvoice]', LogContext::tag());
    }

    /** Structured values would break the single-line format and are dropped. */
    public function testArraysAndObjectsAreRefused(): void
    {
        LogContext::put('payload', ['a' => 1]);
        LogContext::put('object', new \stdClass());

        self::assertSame([], LogContext::all());
    }

    public function testAnEmptyValueClearsRatherThanRecordsTheKey(): void
    {
        LogContext::put('user_id', 42);
        LogContext::put('user_id', null);

        self::assertArrayNotHasKey('user_id', LogContext::all());
    }

    // ─── Reaching the log ────────────────────────────────────────────

    public function testTheRequestIdAppearsInTheWrittenLine(): void
    {
        LogContext::set(['request_id' => 'req_deadbeef', 'method' => 'GET', 'path' => '/orders']);

        $this->logger()->log_info('Order lookup failed');

        self::assertStringContainsString('req_deadbeef', $this->contents());
        self::assertStringContainsString('Order lookup failed', $this->contents());
    }

    /**
     * The whole point is a grep: paste the id the client was shown, get every
     * line the request produced.
     */
    public function testEveryLineOfOneRequestCarriesTheSameId(): void
    {
        LogContext::set(['request_id' => 'req_shared']);

        $logger = $this->logger();
        $logger->log_info('first');
        $logger->log_warning('second');
        $logger->log_error('third');

        self::assertSame(3, substr_count($this->contents(), 'req_shared'));
    }

    public function testAnErrorRecordsWhereItWasLoggedFrom(): void
    {
        $this->logger()->log_error('Something broke');

        // The call site is this file, not Logger.php.
        self::assertMatchesRegularExpression('/\(.*LogContextTest\.php:\d+\)/', $this->contents());
    }

    /** Info is high-volume and rarely investigated; it does not pay for a backtrace. */
    public function testInfoLinesSkipTheCallSite(): void
    {
        $this->logger()->log_info('Routine');

        self::assertStringNotContainsString('LogContextTest.php:', $this->contents());
    }

    // ─── Isolation ───────────────────────────────────────────────────

    /**
     * Under a worker runtime the process outlives the request. Without a reset,
     * request B's log lines carry request A's id and user — which is worse than
     * no id at all, because it points the investigation at the wrong person.
     */
    public function testResetClearsEverythingForTheNextRequest(): void
    {
        LogContext::set(['request_id' => 'req_a', 'user_id' => 1]);
        LogContext::reset();

        self::assertSame([], LogContext::all());
        self::assertSame('', LogContext::tag());
    }

    public function testTheWorkerFlushListIncludesTheContext(): void
    {
        LogContext::set(['request_id' => 'req_a']);

        \Core\Server\WorkerState::flush(false);

        self::assertSame('', LogContext::tag());
    }

    // ─── Failure tolerance ───────────────────────────────────────────

    public function testAResolverThatThrowsDoesNotEscape(): void
    {
        LogContext::putSafely('user_id', static fn() => throw new \RuntimeException('db down'));

        self::assertArrayNotHasKey('user_id', LogContext::all());
    }

    public function testAWorkingResolverStillSetsTheValue(): void
    {
        LogContext::putSafely('user_id', static fn(): int => 9);

        self::assertSame(9, LogContext::get('user_id'));
    }

    // ─── From the environment ────────────────────────────────────────

    public function testTheContextCanBeRebuiltFromServerVars(): void
    {
        $_SERVER['MYTH_REQUEST_ID'] = 'req_env';
        $_SERVER['REQUEST_METHOD'] = 'DELETE';
        $_SERVER['REQUEST_URI'] = '/api/v1/users/9?token=secret';

        LogContext::fromServer();

        self::assertSame('req_env', LogContext::get('request_id'));
        self::assertSame('DELETE', LogContext::get('method'));
        self::assertSame('/api/v1/users/9', LogContext::get('path'), 'The query string must not reach the log.');

        unset($_SERVER['MYTH_REQUEST_ID']);
    }
}
