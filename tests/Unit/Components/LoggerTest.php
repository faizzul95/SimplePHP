<?php

declare(strict_types=1);

use Components\Logger;
use PHPUnit\Framework\TestCase;

final class LoggerTest extends TestCase
{
    public function testDefaultLoggerPathUsesProjectLogsDirectory(): void
    {
        $logger = Logger::instance();
        $summary = $logger->getLogSummary();

        self::assertSame(Logger::defaultLogPath(), $summary['file_path']);
        self::assertStringContainsString('logs' . DIRECTORY_SEPARATOR . 'logger.log', $summary['file_path']);
        self::assertStringNotContainsString('systems' . DIRECTORY_SEPARATOR . 'logs', $summary['file_path']);
    }
}