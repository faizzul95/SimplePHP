<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use Core\Security\CspViolationLogger;
use PHPUnit\Framework\TestCase;

class CspViolationLoggerTest extends TestCase
{
    public function test_parse_returns_normalized_report_from_standard_payload(): void
    {
        $payload = json_encode([
            'csp-report' => [
                'document-uri' => 'https://example.com/dashboard',
                'violated-directive' => 'script-src-elem',
                'effective-directive' => 'script-src-elem',
                'blocked-uri' => 'https://evil.example/script.js',
                'source-file' => 'https://example.com/dashboard',
                'line-number' => 17,
                'column-number' => 3,
                'status-code' => 200,
                'script-sample' => 'alert(1)',
                'original-policy' => "default-src 'self'; report-uri /_myth/csp-report;",
            ],
        ], JSON_UNESCAPED_SLASHES);

        $report = CspViolationLogger::parse((string) $payload);

        self::assertIsArray($report);
        self::assertSame('https://example.com/dashboard', $report['document_uri']);
        self::assertSame('script-src-elem', $report['violated_directive']);
        self::assertSame('https://evil.example/script.js', $report['blocked_uri']);
        self::assertSame(17, $report['line_number']);
    }

    public function test_parse_returns_null_for_invalid_json(): void
    {
        self::assertNull(CspViolationLogger::parse('not-json'));
    }
}