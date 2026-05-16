<?php

declare(strict_types=1);

use Core\Console\Kernel;
use Core\Diagnostics\MemoryProfiler;
use PHPUnit\Framework\TestCase;

final class ProfileMemoryCommandTest extends TestCase
{
    private string $backup = '';
    private bool $hadOriginal = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('ENVIRONMENT')) {
            define('ENVIRONMENT', 'testing');
        }

        bootstrapTestFrameworkServices();

        $path = MemoryProfiler::path();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $this->hadOriginal = is_file($path);
        if ($this->hadOriginal) {
            $this->backup = (string) file_get_contents($path);
        }

        file_put_contents($path, implode(PHP_EOL, [
            json_encode(['logged_at' => '2026-05-16T10:00:00+00:00', 'path' => '/dashboard', 'method' => 'GET', 'elapsed_ms' => 35.5, 'memory_delta_bytes' => 1048576, 'memory_peak_bytes' => 6291456]),
            json_encode(['logged_at' => '2026-05-16T11:00:00+00:00', 'path' => '/auth/login', 'method' => 'POST', 'elapsed_ms' => 120.0, 'memory_delta_bytes' => 2097152, 'memory_peak_bytes' => 7340032]),
        ]) . PHP_EOL);
    }

    protected function tearDown(): void
    {
        $path = MemoryProfiler::path();
        if ($this->hadOriginal) {
            file_put_contents($path, $this->backup);
        } elseif (is_file($path)) {
            unlink($path);
        }

        parent::tearDown();
    }

    public function testProfileMemoryCommandOutputsJsonSummary(): void
    {
        $kernel = new Kernel();
        $exitCode = $kernel->callSilently('profile:memory', [
            'format' => 'json',
        ]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('"path": "/auth/login"', $kernel->output());
        self::assertStringContainsString('"memory_peak_bytes": 7340032', $kernel->output());
    }
}