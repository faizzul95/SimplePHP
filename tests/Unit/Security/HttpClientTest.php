<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use Core\Support\HttpClient;
use PHPUnit\Framework\TestCase;
use phpseclib3\Crypt\RSA;
use phpseclib3\File\X509;

/**
 * Tests for Core\Support\HttpClient (SSRF protection)
 *
 * Covers: assertSafeUrl() — private IPs, bad schemes, unresolvable hosts
 */
class HttpClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['config']['security']['http_client'] = [
            'post_connect_ip_check' => true,
            'force_ipv4' => true,
            'connect_timeout_sec' => 5,
            'dns_cache_timeout' => 0,
            'allowed_private_hosts' => [],
            'pins' => [],
            'pin_on_error' => 'block',
        ];
        HttpClient::clearAllowList();
    }

    public function test_extracts_spki_pin_from_certificate_pem(): void
    {
        if (!function_exists('openssl_x509_read')) {
            $this->markTestSkipped('OpenSSL extension is not available.');
        }

        [$certificatePem, $expectedPin] = $this->createSelfSignedCertificateAndPin();
        $method = new \ReflectionMethod(HttpClient::class, 'extractSpkiPinFromCertificatePem');
        $method->setAccessible(true);

        $actual = $method->invoke(null, $certificatePem);

        self::assertSame($expectedPin, $actual);
    }

    public function test_pin_mismatch_throws_when_policy_is_block(): void
    {
        if (!function_exists('openssl_x509_read')) {
            $this->markTestSkipped('OpenSSL extension is not available.');
        }

        [$certificatePem] = $this->createSelfSignedCertificateAndPin();
        $GLOBALS['config']['security']['http_client']['pins'] = [
            'api.example.test' => ['sha256//notTheRightPin'],
        ];

        $this->expectException(\Core\Support\CertificatePinException::class);

        $method = new \ReflectionMethod(HttpClient::class, 'assertPinnedCertificateMatches');
        $method->setAccessible(true);
        $method->invoke(null, 'api.example.test', [['Cert' => $certificatePem]], ['sha256//notTheRightPin']);
    }

    public function test_matching_pin_passes_validation(): void
    {
        if (!function_exists('openssl_x509_read')) {
            $this->markTestSkipped('OpenSSL extension is not available.');
        }

        [$certificatePem, $expectedPin] = $this->createSelfSignedCertificateAndPin();

        $this->expectNotToPerformAssertions();

        $method = new \ReflectionMethod(HttpClient::class, 'assertPinnedCertificateMatches');
        $method->setAccessible(true);
        $method->invoke(null, 'api.example.test', [['Cert' => $certificatePem]], [$expectedPin]);
    }

    public function test_pin_mismatch_is_log_only_when_policy_is_configured(): void
    {
        if (!function_exists('openssl_x509_read')) {
            $this->markTestSkipped('OpenSSL extension is not available.');
        }

        [$certificatePem] = $this->createSelfSignedCertificateAndPin();
        $GLOBALS['config']['security']['http_client']['pin_on_error'] = 'log-only';

        $this->expectNotToPerformAssertions();

        $method = new \ReflectionMethod(HttpClient::class, 'assertPinnedCertificateMatches');
        $method->setAccessible(true);
        $method->invoke(null, 'api.example.test', [['Cert' => $certificatePem]], ['sha256//wrongPin']);
    }

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

    public function test_connected_ip_check_blocks_private_ip_after_connect(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/DNS rebinding detected/');

        HttpClient::assertConnectedIpIsSafe('10.0.0.9', 'https://example.com');
    }

    public function test_connected_ip_check_allows_configured_private_host(): void
    {
        $GLOBALS['config']['security']['http_client']['allowed_private_hosts'] = ['internal-api.local'];

        $this->expectNotToPerformAssertions();
        HttpClient::assertConnectedIpIsSafe('10.0.0.9', 'https://internal-api.local');
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function createSelfSignedCertificateAndPin(): array
    {
        $privateKey = RSA::createKey(2048);
        $publicKey = $privateKey->getPublicKey();

        $subject = new X509();
        $subject->setDNProp('id-at-commonName', 'api.example.test');
        $subject->setPublicKey($publicKey);

        $issuer = new X509();
        $issuer->setPrivateKey($privateKey);
        $issuer->setDN($subject->getDN());
        $issuer->setPublicKey($publicKey);

        $certificate = $issuer->sign($issuer, $subject);
        if (!is_array($certificate)) {
            self::fail('Unable to create certificate for pinning test.');
        }

        $certificatePem = $issuer->saveX509($certificate);
        if (!is_string($certificatePem) || $certificatePem === '') {
            self::fail('Unable to export certificate for pinning test.');
        }

        $opensslCertificate = openssl_x509_read($certificatePem);
        $opensslPublicKey = $opensslCertificate !== false ? openssl_pkey_get_public($opensslCertificate) : false;
        $details = $opensslPublicKey !== false ? openssl_pkey_get_details($opensslPublicKey) : false;
        if (!is_array($details) || !isset($details['key']) || !is_string($details['key'])) {
            self::fail('Unable to extract key details for pinning test.');
        }

        $expectedPin = 'sha256//' . base64_encode(hash('sha256', $details['key'], true));

        return [$certificatePem, $expectedPin];
    }
}
