<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use Core\Database\SlowQueryLogger;
use PHPUnit\Framework\TestCase;

final class SlowQueryLoggerTest extends TestCase
{
    private string $backup = '';
    private bool $hadOriginal = false;

    protected function setUp(): void
    {
        parent::setUp();

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
            json_encode(['logged_at' => '2026-05-16T12:00:00+00:00', 'connection' => 'analytics', 'duration_ms' => 2200, 'query' => 'SELECT * FROM reports WHERE day = ?']),
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

    public function testSummarizeGroupsByQueryFingerprint(): void
    {
        $rows = SlowQueryLogger::readAll();
        $summary = SlowQueryLogger::summarize($rows, 5);

        self::assertCount(2, $summary);
        self::assertSame('analytics', $summary[0]['connection']);
        self::assertSame(2200.0, $summary[0]['max_ms']);
        self::assertSame(2, $summary[1]['count']);
        self::assertSame('default', $summary[1]['connection']);
    }

    public function testRecordRedactsBindsAndSanitizesRequestUri(): void
    {
        SlowQueryLogger::clear();

        SlowQueryLogger::record([
            'connection' => 'default',
            'table' => 'users',
            'duration_ms' => 1800,
            'threshold_ms' => 750,
            'alert_ms' => 2500,
            'query' => 'SELECT * FROM users WHERE email = ? AND password = ?',
            'binds' => ['alice@example.test', 'secret-password'],
            'full_query' => "SELECT * FROM users WHERE email = 'alice@example.test' AND password = 'secret-password'",
            'request_uri' => "/login\nAuthorization: injected",
        ]);

        $rows = SlowQueryLogger::readAll();

        self::assertCount(1, $rows);
        self::assertSame(['[REDACTED]', '[REDACTED]'], array_values($rows[0]['binds']));
        self::assertSame(2, $rows[0]['bind_count']);
        self::assertSame('', $rows[0]['full_query']);
        self::assertSame('/loginAuthorization: injected', $rows[0]['request_uri']);
    }
}