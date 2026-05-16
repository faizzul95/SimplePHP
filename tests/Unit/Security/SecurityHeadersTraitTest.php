<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use Core\Security\CspNonce;
use Middleware\Traits\SecurityHeadersTrait;
use PHPUnit\Framework\TestCase;

class SecurityHeadersTraitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CspNonce::reset();
        $_SERVER['HTTPS'] = 'on';
        $GLOBALS['config']['security'] = [];
    }

    public function test_builds_both_csp_headers_when_mode_is_both(): void
    {
        $GLOBALS['config']['security'] = [
            'csp' => [
                'enabled' => true,
                'mode' => 'both',
                'report_uri' => '/_myth/csp-report',
                'nonce_enabled' => true,
                'script-src' => ["'self'"],
                'style-src' => ["'self'"],
                'report_only_directives' => [
                    'script-src' => ["'self'", "'nonce-{nonce}'"],
                ],
            ],
            'headers' => ['hsts' => ['enabled' => false]],
            'permissions_policy' => [],
        ];

        $headers = $this->makeSubject()->previewSecurityHeaders();

        self::assertTrue($this->containsHeaderStartingWith($headers, 'Content-Security-Policy: '));
        self::assertTrue($this->containsHeaderStartingWith($headers, 'Content-Security-Policy-Report-Only: '));
        self::assertTrue($this->containsHeaderContaining($headers, "Content-Security-Policy-Report-Only: ", "report-uri /_myth/csp-report;"));
        self::assertTrue($this->containsHeaderContaining($headers, "Content-Security-Policy-Report-Only: ", "'nonce-" . CspNonce::get() . "'"));
    }

    public function test_builds_trusted_types_enforcement_headers(): void
    {
        $GLOBALS['config']['security'] = [
            'csp' => [
                'enabled' => true,
                'mode' => 'enforce',
            ],
            'trusted_types' => [
                'enabled' => true,
                'policies' => ['default', 'summernote'],
                'report_only' => false,
            ],
            'headers' => ['hsts' => ['enabled' => false]],
            'permissions_policy' => [],
        ];

        $headers = $this->makeSubject()->previewSecurityHeaders();

        self::assertContains("Require-Trusted-Types-For: 'script'", $headers);
        self::assertContains('Trusted-Types: default summernote', $headers);
    }

    private function containsHeaderStartingWith(array $headers, string $prefix): bool
    {
        foreach ($headers as $header) {
            if (str_starts_with($header, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function containsHeaderContaining(array $headers, string $prefix, string $fragment): bool
    {
        foreach ($headers as $header) {
            if (str_starts_with($header, $prefix) && str_contains($header, $fragment)) {
                return true;
            }
        }

        return false;
    }

    private function makeSubject(): object
    {
        return new class {
            use SecurityHeadersTrait;

            public function previewSecurityHeaders(): array
            {
                return $this->buildSecurityHeaders();
            }
        };
    }
}