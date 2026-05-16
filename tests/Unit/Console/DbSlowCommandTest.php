<?php

declare(strict_types=1);

use Core\Console\Kernel;
use Core\Database\SlowQueryLogger;
use PHPUnit\Framework\TestCase;

final class DbSlowCommandTest extends TestCase
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

        $path = SlowQueryLogger::path();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $this->hadOriginal = is_file($path);
        if ($this->hadOriginal) {
            $this->backup = (string) file_get_contents($path);
        }

        file_put_contents($path, implode(PHP_EOL, [
            json_encode(['logged_at' => '2026-05-16T10:00:00+00:00', 'connection' => 'default', 'duration_ms' => 1200, 'query' => 'SELECT * FROM users WHERE id = ?']),
            json_encode(['logged_at' => '2026-05-16T11:00:00+00:00', 'connection' => 'default', 'duration_ms' => 1400, 'query' => 'SELECT * FROM users WHERE id = ?']),
        ]) . PHP_EOL);
    }

    protected function tearDown(): void
    {
        $path = SlowQueryLogger::path();
        if ($this->hadOriginal) {
            file_put_contents($path, $this->backup);
        } elseif (is_file($path)) {
            unlink($path);
        }

        parent::tearDown();
    }

    public function testDbSlowCommandOutputsJsonSummary(): void
    {
        $kernel = new Kernel();
        $exitCode = $kernel->callSilently('db:slow', [
            'format' => 'json',
            'top' => '5',
        ]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('"total": 2', $kernel->output());
        self::assertStringContainsString('"count": 2', $kernel->output());
        self::assertStringContainsString('SELECT * FROM users WHERE id = ?', $kernel->output());
    }

    public function testDbSlowCommandRejectsInvalidSinceValue(): void
    {
        $kernel = new Kernel();
        $exitCode = $kernel->callSilently('db:slow', [
            'since' => 'not-a-range',
        ]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Invalid --since value', $kernel->output());
    }
}