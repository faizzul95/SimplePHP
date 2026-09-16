<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use Components\Logger;
use Core\Support\LogContext;
use PHPUnit\Framework\TestCase;

/**
 * logException() used to write the full getTraceAsString(). Records are kept
 * single-line so they stay greppable, so every newline in that trace became a
 * literal `[NL]` — a two-thousand-character line nobody reads, in which the one
 * fact you needed (the cause) was usually the part that got wrapped away.
 */
final class LoggerExceptionFormatTest extends TestCase
{
    private string $logFile;

    protected function setUp(): void
    {
        parent::setUp();
        LogContext::reset();
        $this->logFile = sys_get_temp_dir() . '/myth-logexc-' . bin2hex(random_bytes(6)) . '.log';
    }

    protected function tearDown(): void
    {
        LogContext::reset();

        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }

        parent::tearDown();
    }

    private function logged(\Throwable $exception): string
    {
        (new Logger($this->logFile))->logException($exception);

        return (string) file_get_contents($this->logFile);
    }

    public function testTheExceptionClassIsNamed(): void
    {
        self::assertStringContainsString('RuntimeException', $this->logged(new \RuntimeException('boom')));
    }

    public function testTheThrowSiteIsRecorded(): void
    {
        $line = __LINE__ + 1;
        $exception = new \LogicException('bad state');

        $logged = $this->logged($exception);

        self::assertStringContainsString('LoggerExceptionFormatTest.php:' . $line, $logged);
    }

    /** An absolute path makes the line long and machine-specific for no gain. */
    public function testPathsAreRelativeToTheProjectRoot(): void
    {
        $logged = $this->logged(new \RuntimeException('boom'));

        self::assertStringNotContainsString(ROOT_DIR, $logged);
        self::assertStringContainsString('tests/Unit/Support/', str_replace('\\', '/', $logged));
    }

    /**
     * The wrapped exception is usually the one that tells you what to fix — a
     * PDOException saying "Duplicate entry" beats the RuntimeException that
     * rethrew it as "Could not save order".
     */
    public function testTheCauseChainSurvives(): void
    {
        $root = new \InvalidArgumentException('Duplicate entry for key users_email_unique');
        $middle = new \DomainException('Could not persist user', 0, $root);
        $outer = new \RuntimeException('Registration failed', 0, $middle);

        $logged = $this->logged($outer);

        self::assertStringContainsString('Registration failed', $logged);
        self::assertStringContainsString('caused by DomainException: Could not persist user', $logged);
        self::assertStringContainsString('caused by InvalidArgumentException: Duplicate entry', $logged);
    }

    public function testTheCauseChainIsBounded(): void
    {
        $exception = new \RuntimeException('level 0');
        for ($i = 1; $i <= 8; $i++) {
            $exception = new \RuntimeException('level ' . $i, 0, $exception);
        }

        $logged = $this->logged($exception);

        // Three causes at most: enough to reach the real error, not enough to
        // turn one record into a transcript.
        self::assertSame(3, substr_count($logged, 'caused by'));
    }

    public function testTheRecordStaysOnOneLine(): void
    {
        $logged = $this->logged(new \RuntimeException('boom'));

        self::assertSame(0, substr_count(trim($logged), "\n"), 'A record must be greppable.');
    }

    public function testTheTraceIsSummarisedRatherThanDumped(): void
    {
        $exception = $this->nest(12);

        $logged = $this->logged($exception);

        self::assertStringContainsString('trace: ', $logged);
        self::assertStringContainsString('more)', $logged, 'Truncation must be visible, not silent.');
        self::assertLessThan(1500, strlen($logged));
    }

    public function testTheRequestIdIsOnTheExceptionRecordToo(): void
    {
        LogContext::set(['request_id' => 'req_exc']);

        self::assertStringContainsString('req_exc', $this->logged(new \RuntimeException('boom')));
    }

    private function nest(int $depth): \RuntimeException
    {
        if ($depth <= 0) {
            return new \RuntimeException('deep');
        }

        return $this->nest($depth - 1);
    }
}
