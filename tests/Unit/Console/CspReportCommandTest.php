<?php

declare(strict_types=1);

use Core\Console\Kernel;
use Core\Security\CspViolationLogger;
use PHPUnit\Framework\TestCase;

final class CspReportCommandTest extends TestCase
{
    private string $logPath;
    private string $backup = '';
    private bool $hadOriginal = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('ENVIRONMENT')) {
            define('ENVIRONMENT', 'testing');
        }

        bootstrapTestFrameworkServices();

        $this->logPath = CspViolationLogger::logFilePath();
        $dir = dirname($this->logPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $this->hadOriginal = is_file($this->logPath);
        if ($this->hadOriginal) {
            $this->backup = (string) file_get_contents($this->logPath);
        }

        file_put_contents($this->logPath, implode(PHP_EOL, [
            json_encode([
                'ts' => '2026-05-16T10:00:00+00:00',
                'document_uri' => 'https://example.test/dashboard',
                'violated_directive' => 'script-src-elem',
                'effective_directive' => 'script-src-elem',
            ], JSON_UNESCAPED_SLASHES),
            json_encode([
                'ts' => '2026-05-16T11:00:00+00:00',
                'document_uri' => 'https://example.test/dashboard',
                'violated_directive' => 'script-src-elem',
                'effective_directive' => 'script-src-elem',
            ], JSON_UNESCAPED_SLASHES),
            json_encode([
                'ts' => '2026-05-16T12:00:00+00:00',
                'document_uri' => 'https://example.test/profile',
                'violated_directive' => 'style-src-elem',
                'effective_directive' => 'style-src-elem',
            ], JSON_UNESCAPED_SLASHES),
        ]) . PHP_EOL);
    }

    protected function tearDown(): void
    {
        if ($this->hadOriginal) {
            file_put_contents($this->logPath, $this->backup);
        } elseif (is_file($this->logPath)) {
            unlink($this->logPath);
        }

        parent::tearDown();
    }

    public function testCspReportCommandPrintsJsonSummaryFromLogFile(): void
    {
        $kernel = new Kernel();
        $exitCode = $kernel->callSilently('csp:report', [
            'format' => 'json',
            'top' => '2',
        ]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('"total": 3', $kernel->output());
        self::assertStringContainsString('"directive": "script-src-elem"', $kernel->output());
        self::assertStringContainsString('"count": 2', $kernel->output());
    }

    public function testCspReportCommandRejectsInvalidSinceOption(): void
    {
        $kernel = new Kernel();
        $exitCode = $kernel->callSilently('csp:report', [
            'since' => 'not-a-time-window',
        ]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Invalid --since value', $kernel->output());
    }
}