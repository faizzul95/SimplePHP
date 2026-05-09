<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use Core\Support\HttpClient;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Core\Support\HttpClient (SSRF protection)
 *
 * Covers: assertSafeUrl() — private IPs, bad schemes, unresolvable hosts
 */
class HttpClientTest extends TestCase
{
    public function test_throws_for_private_ip_10_x_x_x(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/SSRF blocked/');

        HttpClient::assertSafeUrl('http://10.0.0.1/api');
    }

    public function test_throws_for_private_ip_192_168(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/SSRF blocked/');

        HttpClient::assertSafeUrl('http://192.168.1.1/admin');
    }

    public function test_throws_for_loopback(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/SSRF blocked/');

        HttpClient::assertSafeUrl('http://127.0.0.1/');
    }

    public function test_throws_for_localhost(): void
    {
        $this->expectException(\RuntimeException::class);
        // localhost resolves to 127.0.0.1
        $this->expectExceptionMessageMatches('/SSRF blocked|Could not resolve/');

        HttpClient::assertSafeUrl('http://localhost/');
    }

    public function test_throws_for_file_scheme(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Disallowed URL scheme/');

        HttpClient::assertSafeUrl('file:///etc/passwd');
    }

    public function test_throws_for_ftp_scheme(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Disallowed URL scheme/');

        HttpClient::assertSafeUrl('ftp://ftp.example.com/file');
    }

    public function test_throws_for_empty_host(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        HttpClient::assertSafeUrl('https:///no-host-path');
    }

    public function test_does_not_throw_for_public_url(): void
    {
        // example.com resolves to public IPs — should not throw
        // Use try/catch since DNS might not be available in all CI environments
        try {
            $this->expectNotToPerformAssertions();
            HttpClient::assertSafeUrl('https://example.com/');
        } catch (\RuntimeException $e) {
            // Only acceptable exception is "Could not resolve host" (no DNS in CI)
            $this->assertStringContainsString('resolve', $e->getMessage());
        }
    }

    public function test_throws_for_172_16_range(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/SSRF blocked/');

        HttpClient::assertSafeUrl('http://172.16.0.1/');
    }

    public function test_throws_for_link_local(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/SSRF blocked/');

        HttpClient::assertSafeUrl('http://169.254.169.254/latest/meta-data/');
    }
}
