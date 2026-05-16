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
    private const CERTIFICATE_PEM = <<<'PEM'
-----BEGIN CERTIFICATE-----
MIIDYTCCAhSgAwIBAgIUPfjK7PIMKuwVRkyp8pQTfeCFX0MwQgYJKoZIhvcNAQEK
MDWgDTALBglghkgBZQMEAgGhGjAYBgkqhkiG9w0BAQgwCwYJYIZIAWUDBAIBogMC
ASCjAwIBATAbMRkwFwYDVQQDDBBhcGkuZXhhbXBsZS50ZXN0MB4XDTI2MDUxNjEw
MzYwN1oXDTI3MDUxNjEwMzYwN1owGzEZMBcGA1UEAwwQYXBpLmV4YW1wbGUudGVz
dDCCAVcwQgYJKoZIhvcNAQEKMDWgDTALBglghkgBZQMEAgGhGjAYBgkqhkiG9w0B
AQgwCwYJYIZIAWUDBAIBogMCASCjAwIBAQOCAQ8AMIIBCgKCAQEAvPUF22sNM7ex
aJKXadanVWjA4LlOIaakTpKzwyI7frSK8saHgHphoFBqzAHfYmoq14h7DIFDBr5X
p29jcZ2vldMyzX5hCGC5wslOcqHmTReYGvl8LbwjERIivN5XgYzFdvLPHoEFR2PW
crA6U2LZI7tgx2RUcCeKuiV58lU+WZMzLSbneqCnfuum2a6OVWqvJ/Xr0heNOQ6W
dQJ1em1OH58pqQMXCr0xe2yrSksDAAZ1979oEqQ499JmXSVaoxYs3au388zYZjoD
pevMmlasV4pGbCrGW2w34WnODnIgMnxLFQDcg3oyqXu0Jo3hSIB6+bMYRiLN/uj1
oX36NHRrKQIDAQABMEIGCSqGSIb3DQEBCjA1oA0wCwYJYIZIAWUDBAIBoRowGAYJ
KoZIhvcNAQEIMAsGCWCGSAFlAwQCAaIDAgEgowMCAQEDggEBAAQCQkswet7m1kt3
AjLjqEHwLB5JAuYOtIk3B8P4RA3ZX59hrl7tmza90FdExjgeP21JHcNuPftBc0om
Kj7z0B2O9SVUzgOzQgxhyFIuYikpx/kIBDAIDJw6hXoxtCTjg+31tF/bwWAxk5LG
frstxUEjGdp8Q3XDIrqXrbu21VwrB0pXuCLRnwGvplWFeHtkHq1IOQVW76UyvtcF
CkaWHvYCpSmE4MqM1YauJJ6OFp8LNFrTmk409oRRNOvFFHRVcutdKmCYRu0BL0ZP
7nBDsQPjJz68/RGvin35NL9qdmbmY3AxmMhr/89fN8aqLC1UTBjwxD68GSuWe1Oc
8XkCpRI=
-----END CERTIFICATE-----
PEM;

    private const CERTIFICATE_PIN = 'sha256//+zkJjfR6idxC+RQZ/ss4bV+qUvXq4i7+f7MWeWPSUZE=';

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

        $method = new \ReflectionMethod(HttpClient::class, 'extractSpkiPinFromCertificatePem');
        $method->setAccessible(true);

        $actual = $method->invoke(null, self::CERTIFICATE_PEM);

        self::assertSame(self::CERTIFICATE_PIN, $actual);
    }

    public function test_pin_mismatch_throws_when_policy_is_block(): void
    {
        if (!function_exists('openssl_x509_read')) {
            $this->markTestSkipped('OpenSSL extension is not available.');
        }

        $GLOBALS['config']['security']['http_client']['pins'] = [
            'api.example.test' => ['sha256//notTheRightPin'],
        ];

        $this->expectException(\Core\Support\CertificatePinException::class);

        $method = new \ReflectionMethod(HttpClient::class, 'assertPinnedCertificateMatches');
        $method->setAccessible(true);
        $method->invoke(null, 'api.example.test', [['Cert' => self::CERTIFICATE_PEM]], ['sha256//notTheRightPin']);
    }

    public function test_matching_pin_passes_validation(): void
    {
        if (!function_exists('openssl_x509_read')) {
            $this->markTestSkipped('OpenSSL extension is not available.');
        }

        $this->expectNotToPerformAssertions();

        $method = new \ReflectionMethod(HttpClient::class, 'assertPinnedCertificateMatches');
        $method->setAccessible(true);
        $method->invoke(null, 'api.example.test', [['Cert' => self::CERTIFICATE_PEM]], [self::CERTIFICATE_PIN]);
    }

    public function test_pin_mismatch_is_log_only_when_policy_is_configured(): void
    {
        if (!function_exists('openssl_x509_read')) {
            $this->markTestSkipped('OpenSSL extension is not available.');
        }
        $GLOBALS['config']['security']['http_client']['pin_on_error'] = 'log-only';

        $this->expectNotToPerformAssertions();

        $method = new \ReflectionMethod(HttpClient::class, 'assertPinnedCertificateMatches');
        $method->setAccessible(true);
        $method->invoke(null, 'api.example.test', [['Cert' => self::CERTIFICATE_PEM]], ['sha256//wrongPin']);
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

}
